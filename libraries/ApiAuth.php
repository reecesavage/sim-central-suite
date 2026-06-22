<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * REST API token auth.
 *
 * Tokens are admin-issued from the ACP, stored hashed (sha256). Raw tokens
 * are shown to the admin exactly once at creation time; only the hash + a
 * short display prefix survive in the DB.
 *
 * Header parsing (Authorization vs X-API-Key) is the controller's
 * responsibility - this library only knows how to validate a RAW token
 * against the database. validateToken() returns a structured result so the
 * caller can emit the right HTTP status without re-implementing the matrix:
 *
 *   ok     -> 200, $result['token'] is the DB row
 *   401    -> missing / malformed / unknown / revoked / expired
 *   403    -> token is valid but lacks the required scope
 *   429    -> rate limit exceeded for the rolling 60s window
 *
 * Rate limiting is per-token, in-DB, single-row update. Window is 60s rolling
 * (resets the count when the stored window_at is older than 60s). Limit is
 * read from the suite's Config setting `rest_api_rate_limit_per_minute`.
 */
class ApiAuth
{
	const TOKEN_PREFIX = 'scapi_';
	const TOKEN_RANDOM_BYTES = 20; // 40 hex chars
	const HASH_ALGO = 'sha256';

	/**
	 * Canonical registry of scopes the API understands. Single source of truth
	 * for the ACP token form (Manage), the token-create validator below, and
	 * the API's own token-management endpoints. Read scopes gate GET endpoints;
	 * write scopes gate mutating ones. The posts:*.all and tokens:* scopes are
	 * additionally gated on the bound user being a sysadmin at request time.
	 */
	public static function availableScopes()
	{
		return array(
			'posts:read'       => 'Read public (activated) mission posts (list + view).',
			'posts:read.own'   => 'Read the bound user\'s own posts, including drafts. Requires a user-bound token.',
			'posts:write'      => 'Create and update the bound user\'s posts (save or activate). Requires a user-bound token.',
			'posts:delete'     => 'Delete the bound user\'s posts. Requires a user-bound token.',
			'posts:read.all'   => 'Read ANY post including others\' drafts. Sysadmin bypass: also requires the bound user to be a sysadmin.',
			'posts:write.all'  => 'Create/update ANY post (and add a character to it). Sysadmin bypass: also requires the bound user to be a sysadmin.',
			'posts:delete.all' => 'Delete ANY post. Sysadmin bypass: also requires the bound user to be a sysadmin.',
			'characters:read'  => 'Read characters (list + view).',
			'missions:read'    => 'Read missions (list + view).',
			'users:write'      => 'Disable or reactivate users and their linked characters.',
			'webhooks:read'    => 'List event webhooks and view their config.',
			'webhooks:write'   => 'Create, update, and delete event webhooks.',
			'tokens:read'      => 'List API tokens and view their metadata. Requires a sysadmin-bound token.',
			'tokens:write'     => 'Create, revoke, and delete API tokens. Requires a sysadmin-bound token.',
			'suite:update'     => 'Trigger a Sim Central Suite upgrade (GET /suite, POST /suite/update). Requires a sysadmin-bound token.',
		);
	}

	/**
	 * Scopes that only act when the token is bound to a user. Used to require a
	 * binding at token-create time (the ACP form and the API both call this).
	 */
	public static function scopeNeedsUser($scope)
	{
		return strpos($scope, 'posts:') === 0 && $scope !== 'posts:read';
	}

	/**
	 * Validate + normalise a token-create request from either the ACP form or
	 * the REST API. $in keys: label, scopes (array), user_id (int|string|''),
	 * expires_at (string|'').
	 *
	 * @return array { errors: string[], data: array|null }
	 *         data = { label, scopes(string[]), user_id(int|null), expires_at(string|null) }
	 */
	public static function validateTokenInput(array $in)
	{
		$ci =& get_instance();
		$errors = array();

		$label = isset($in['label']) ? trim((string) $in['label']) : '';
		if ($label === '') {
			$errors[] = 'label is required.';
		} elseif (strlen($label) > 120) {
			$errors[] = 'label is too long (max 120 chars).';
		}

		$available = self::availableScopes();
		$posted    = isset($in['scopes']) && is_array($in['scopes']) ? $in['scopes'] : array();
		$scopes    = array();
		foreach ($posted as $s) {
			if (isset($available[$s])) { $scopes[] = $s; }
		}
		$scopes = array_values(array_unique($scopes));
		if (empty($scopes)) {
			$errors[] = 'Select at least one valid scope.';
		}

		$rawUser     = isset($in['user_id']) ? trim((string) $in['user_id']) : '';
		$boundUserId = ($rawUser !== '' && ctype_digit($rawUser)) ? (int) $rawUser : 0;

		$needsUser = false;
		foreach ($scopes as $s) {
			if (self::scopeNeedsUser($s)) { $needsUser = true; break; }
		}
		if ($boundUserId > 0) {
			$exists = $ci->db->where('userid', $boundUserId)->count_all_results('users') > 0;
			if ( ! $exists) {
				$errors[] = 'Selected user does not exist.';
			}
		} elseif ($needsUser) {
			$errors[] = 'A user binding is required for the post own/write/delete scopes.';
		}

		$expiresAt = null;
		if ( ! empty($in['expires_at'])) {
			$ts = strtotime((string) $in['expires_at']);
			if ($ts === false || $ts < time()) {
				$errors[] = 'expires_at must be a valid future date/time.';
			} else {
				$expiresAt = date('Y-m-d H:i:s', $ts);
			}
		}

		if ( ! empty($errors)) {
			return array('errors' => $errors, 'data' => null);
		}
		return array('errors' => array(), 'data' => array(
			'label'      => $label,
			'scopes'     => $scopes,
			'user_id'    => $boundUserId > 0 ? $boundUserId : null,
			'expires_at' => $expiresAt,
		));
	}

	/**
	 * Generate a fresh token. Returns the raw token (show to admin ONCE),
	 * the sha256 hash (store in DB), and the display prefix (first 12 chars).
	 *
	 * Caller is responsible for inserting the row with the label, scopes, etc.
	 */
	public static function generateToken()
	{
		$random = bin2hex(random_bytes(self::TOKEN_RANDOM_BYTES));
		$raw    = self::TOKEN_PREFIX.$random;
		return array(
			'raw'    => $raw,
			'hash'   => hash(self::HASH_ALGO, $raw),
			'prefix' => substr($raw, 0, 12),
		);
	}

	/**
	 * Validate a raw token string against the tokens table.
	 *
	 * @param string|null $raw            Raw token (e.g. "scapi_a1b2c3..."), already
	 *                                    stripped of any header prefix by the caller.
	 *                                    Pass null when no header was found at all.
	 * @param string|null $requiredScope  Scope the endpoint requires, or null for "any valid token".
	 * @return array { status: 'ok'|'unauthorized'|'forbidden'|'rate_limited',
	 *                code: int, message: string, token?: object }
	 */
	public static function validateToken($raw, $requiredScope = null)
	{
		if ( ! is_string($raw) || $raw === '') {
			return self::deny(401, 'Missing API token. Send it in the "X-API-Key" header.');
		}
		if ( ! preg_match('/^'.preg_quote(self::TOKEN_PREFIX, '/').'[a-f0-9]+$/i', $raw)) {
			return self::deny(401, 'Malformed API token. Expected "'.self::TOKEN_PREFIX.'" followed by hex characters.');
		}

		$ci   =& get_instance();

		// Defensive: if the feature toggle is on but the admin hasn't run
		// "Setup database" yet, the tokens table doesn't exist and every
		// query below would 500. Surface that as a 503 so consumers know
		// this is a server config problem, not an auth problem.
		$prefix = $ci->db->dbprefix;
		if ( ! $ci->db->table_exists($prefix.'sim_central_api_tokens')
			&& ! $ci->db->table_exists('sim_central_api_tokens')) {
			return array(
				'status'  => 'unconfigured',
				'code'    => 503,
				'message' => 'REST API is enabled but the tokens table is missing. Ask the sim admin to run "Setup database" on the REST API feature.',
			);
		}

		$hash = hash(self::HASH_ALGO, $raw);
		$row  = $ci->db->get_where('sim_central_api_tokens', array('token_hash' => $hash))->row();

		if ( ! $row) {
			return self::deny(401, 'Unknown token.');
		}
		if ( ! empty($row->revoked_at)) {
			return self::deny(401, 'Token has been revoked.');
		}
		if ( ! empty($row->expires_at) && strtotime($row->expires_at) < time()) {
			return self::deny(401, 'Token has expired.');
		}

		if ($requiredScope !== null && ! self::hasScope($row, $requiredScope)) {
			return array(
				'status'  => 'forbidden',
				'code'    => 403,
				'message' => 'Token lacks required scope: '.$requiredScope,
			);
		}

		$rateResult = self::applyRateLimit($row);
		if ($rateResult !== null) {
			return $rateResult;
		}

		$ci->db->where('id', $row->id)->update('sim_central_api_tokens', array(
			'last_used_at' => date('Y-m-d H:i:s'),
		));

		return array(
			'status' => 'ok',
			'code'   => 200,
			'token'  => $row,
		);
	}

	private static function hasScope($row, $scope)
	{
		$scopes = json_decode($row->scopes, true);
		if ( ! is_array($scopes)) {
			return false;
		}
		return in_array($scope, $scopes, true);
	}

	/**
	 * Rolling 60s window per token. Returns a deny array if the window is
	 * exhausted, or null if the call is allowed (and increments the counter).
	 */
	private static function applyRateLimit($row)
	{
		$ci   =& get_instance();
		$limit = (int) self::rateLimitPerMinute();
		if ($limit <= 0) {
			return null; // disabled
		}

		$now       = time();
		$windowAt  = $row->rate_window_at ? strtotime($row->rate_window_at) : 0;
		$inWindow  = ($windowAt > 0) && (($now - $windowAt) < 60);

		if ( ! $inWindow) {
			$ci->db->where('id', $row->id)->update('sim_central_api_tokens', array(
				'rate_window_at' => date('Y-m-d H:i:s', $now),
				'rate_count'     => 1,
			));
			return null;
		}

		if ((int) $row->rate_count >= $limit) {
			return array(
				'status'  => 'rate_limited',
				'code'    => 429,
				'message' => 'Rate limit exceeded ('.$limit.'/min). Try again shortly.',
			);
		}

		$ci->db->where('id', $row->id)->set('rate_count', 'rate_count + 1', false)
			->update('sim_central_api_tokens');
		return null;
	}

	private static function rateLimitPerMinute()
	{
		$cfg = Config::load();
		if (isset($cfg['setting']['rest_api_rate_limit_per_minute'])) {
			return (int) $cfg['setting']['rest_api_rate_limit_per_minute'];
		}
		return 60;
	}

	private static function deny($code, $message)
	{
		return array(
			'status'  => 'unauthorized',
			'code'    => $code,
			'message' => $message,
		);
	}
}
