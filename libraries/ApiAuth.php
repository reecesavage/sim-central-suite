<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * REST API bearer-token auth.
 *
 * Tokens are admin-issued from the ACP, stored hashed (sha256), and presented
 * as `Authorization: Bearer scapi_<hex>`. Raw tokens are shown to the admin
 * exactly once at creation time; only the hash + a short display prefix
 * survive in the DB.
 *
 * validateBearer() is the single entry point used by controllers/Api.php. It
 * returns a structured result so the caller can emit the right HTTP status
 * without re-implementing the matrix:
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
	 * Validate an Authorization header against the tokens table.
	 *
	 * @param string|null $authHeader     Raw header value (e.g. "Bearer scapi_...").
	 * @param string|null $requiredScope  Scope the endpoint requires, or null for "any valid token".
	 * @return array { status: 'ok'|'unauthorized'|'forbidden'|'rate_limited',
	 *                code: int, message: string, token?: object }
	 */
	public static function validateBearer($authHeader, $requiredScope = null)
	{
		$raw = self::extractBearer($authHeader);
		if ($raw === null) {
			return self::deny(401, 'Missing or malformed Authorization header.');
		}

		$ci   =& get_instance();
		$hash = hash(self::HASH_ALGO, $raw);
		$row  = $ci->db->get_where('nova_ext_sim_central_api_tokens', array('token_hash' => $hash))->row();

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

		$ci->db->where('id', $row->id)->update('nova_ext_sim_central_api_tokens', array(
			'last_used_at' => date('Y-m-d H:i:s'),
		));

		return array(
			'status' => 'ok',
			'code'   => 200,
			'token'  => $row,
		);
	}

	/**
	 * Pull the bearer value from a header. Returns null if the header is
	 * absent or doesn't match the `Bearer scapi_<hex>` shape.
	 */
	private static function extractBearer($authHeader)
	{
		if ( ! is_string($authHeader) || $authHeader === '') {
			return null;
		}
		if ( ! preg_match('/^Bearer\s+('.preg_quote(self::TOKEN_PREFIX, '/').'[a-f0-9]+)$/i', trim($authHeader), $m)) {
			return null;
		}
		return $m[1];
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
			$ci->db->where('id', $row->id)->update('nova_ext_sim_central_api_tokens', array(
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
			->update('nova_ext_sim_central_api_tokens');
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
