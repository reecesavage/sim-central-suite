<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Discord OAuth2 flow orchestration, suite-side.
 *
 * The sim-central-broker Cloudflare Worker does the actual Discord
 * dance and signs a JWT with an RSA private key. This class consumes
 * those JWTs:
 *
 *   verifyToken($jwt)           - signature + claim checks (iss, aud,
 *                                 exp, email_verified). Returns claims
 *                                 array on success, error string on
 *                                 failure.
 *   findUserByDiscordId($id)    - returns user row or null.
 *   createUserFromClaims($cls)  - creates a Nova user from Discord
 *                                 identity. Generates a placeholder
 *                                 password the user can later replace
 *                                 via the standard reset flow.
 *   linkUserToDiscord($userId)  - attaches Discord cols to an
 *                                 existing user.
 *   unlinkUser($userId)         - clears the Discord cols (refuses
 *                                 if the user has no password set,
 *                                 to avoid locking them out).
 *   refreshUserInfo($user)      - keeps stored username/avatar in
 *                                 sync with what Discord just told us.
 *   loginUserById($userId)      - mimics Nova_auth::_set_session so
 *                                 the user is signed in without ever
 *                                 holding a password.
 *
 * Settings live in the merged suite config (state row over config.json
 * defaults), under the `setting.discord_auth_*` keys. Public broker
 * key is required; without it verification always fails.
 */
class DiscordAuth
{
	const DEFAULT_BROKER_URL = 'https://auth.simcentral.host';

	// ---------- config accessors ----------

	public static function brokerUrl()
	{
		$c = Config::load();
		$v = isset($c['setting']['discord_auth_broker_url']) ? trim((string) $c['setting']['discord_auth_broker_url']) : '';
		return $v !== '' ? rtrim($v, '/') : self::DEFAULT_BROKER_URL;
	}

	public static function publicKey()
	{
		$c = Config::load();
		return isset($c['setting']['discord_auth_public_key']) ? (string) $c['setting']['discord_auth_public_key'] : '';
	}

	/** 'link-only' or 'auto-create'. Defaults to the safer 'link-only'. */
	public static function mode()
	{
		$c = Config::load();
		$m = isset($c['setting']['discord_auth_mode']) ? (string) $c['setting']['discord_auth_mode'] : 'link-only';
		return ($m === 'auto-create') ? 'auto-create' : 'link-only';
	}

	public static function callbackUrl()
	{
		return site_url('extensions/nova_ext_sim_central/DiscordAuth/callback');
	}

	public static function brokerStartUrl()
	{
		return self::brokerUrl().'/start?return_to='.rawurlencode(self::callbackUrl());
	}

	// ---------- JWT verification ----------

	/**
	 * Verify a broker-issued JWT. Returns array($status, $payloadOrError)
	 * where $status is 'ok' | 'error'. On 'error', the second slot is a
	 * short machine-friendly code suitable for flash messages.
	 */
	public static function verifyToken($jwt)
	{
		$pubKey = self::publicKey();
		if ($pubKey === '') {
			return array('error', 'broker_key_not_configured');
		}

		$claims = Jwt::decode($jwt, $pubKey);
		if ($claims === null) {
			return array('error', 'invalid_signature');
		}

		// Required claim shape.
		foreach (array('iss', 'aud', 'sub', 'exp', 'iat', 'email_verified') as $required) {
			if ( ! array_key_exists($required, $claims)) {
				return array('error', 'missing_claim:'.$required);
			}
		}

		// Expiry.
		if ((int) $claims['exp'] < time()) {
			return array('error', 'expired');
		}

		// Issuer must match the configured broker URL.
		if (rtrim((string) $claims['iss'], '/') !== self::brokerUrl()) {
			return array('error', 'wrong_issuer');
		}

		// Audience must match THIS sim's origin (binds the token to one site).
		$expectedAud = self::originOf(self::callbackUrl());
		if ((string) $claims['aud'] !== $expectedAud) {
			return array('error', 'wrong_audience');
		}

		// Email-verified gate. Broker already enforces it, but we re-check
		// in case the suite is pointed at a third-party broker with looser
		// policy. This is a hard suite-level requirement.
		if ($claims['email_verified'] !== true) {
			return array('error', 'email_not_verified');
		}

		return array('ok', $claims);
	}

	// ---------- user matching / creation / linking ----------

	public static function findUserByDiscordId($discordId)
	{
		$ci =& get_instance();
		$query = $ci->db
			->where('nova_ext_discord_auth_id', (string) $discordId)
			->limit(1)
			->get('users');
		return ($query->num_rows() > 0) ? $query->row() : null;
	}

	/**
	 * Attach Discord identity to an existing user. Returns true on
	 * success, false if another user already has that Discord ID
	 * (the UNIQUE index would have rejected it anyway).
	 */
	public static function linkUserToDiscord($userId, array $claims)
	{
		$existing = self::findUserByDiscordId($claims['sub']);
		if ($existing !== null && (int) $existing->userid !== (int) $userId) {
			return false;
		}
		$ci =& get_instance();
		$ci->load->model('users_model', 'user');
		$ci->user->update_user((int) $userId, self::discordCols($claims));
		return true;
	}

	public static function unlinkUser($userId)
	{
		$ci =& get_instance();
		$ci->load->model('users_model', 'user');

		// Refuse if the user has no password set - unlinking would lock
		// them out. The check is on the password column being non-empty.
		$row = $ci->user->get_user((int) $userId);
		if ( ! $row || empty($row->password)) {
			return array('error', 'no_password_set');
		}

		$ci->user->update_user((int) $userId, array(
			'nova_ext_discord_auth_id'             => null,
			'nova_ext_discord_auth_username'       => null,
			'nova_ext_discord_auth_avatar'         => null,
			'nova_ext_discord_auth_email_verified' => null,
			'nova_ext_discord_auth_linked_at'      => null,
		));
		return array('ok', null);
	}

	public static function refreshUserInfo($user, array $claims)
	{
		// Light touch: only update if something changed. Avoid spurious
		// writes on every login.
		$cols = self::discordCols($claims);
		$dirty = array();
		foreach ($cols as $k => $v) {
			if ( ! property_exists($user, $k) || (string) $user->$k !== (string) $v) {
				$dirty[$k] = $v;
			}
		}
		if (empty($dirty)) {
			return;
		}
		$ci =& get_instance();
		$ci->load->model('users_model', 'user');
		$ci->user->update_user((int) $user->userid, $dirty);
	}

	/**
	 * Create a brand new Nova user from the Discord claims. The user
	 * lands in 'active' status with no characters - same shape as a
	 * normal join, minus the moderation step. Caller is responsible
	 * for logging them in afterwards.
	 *
	 * Returns the new userid (int) on success, or array('error', $code)
	 * on failure (e.g. email already in use by another user).
	 */
	public static function createUserFromClaims(array $claims)
	{
		$ci =& get_instance();
		$ci->load->model('users_model', 'user');
		$ci->load->model('settings_model', 'settings');

		$email = isset($claims['email']) ? (string) $claims['email'] : '';
		if ($email === '') {
			return array('error', 'no_email');
		}

		// If the email collides with an existing account, that user
		// should link Discord from their profile, not create a duplicate.
		if ($ci->user->check_email($email) > 0) {
			return array('error', 'email_already_in_use');
		}

		// Random unguessable placeholder password - the user can later
		// set a real one via the standard reset flow if they want
		// non-Discord login. Hashed exactly like a normal join.
		$placeholder = bin2hex(random_bytes(16));
		$row = array(
			'email'        => $email,
			'password'     => \Auth::hash($placeholder),
			'access_role'  => (int) $ci->settings->get_setting('default_user_role'),
			'status'       => 'active',
			'language'     => $ci->settings->get_setting('default_user_language'),
			'timezone'     => $ci->settings->get_setting('default_user_timezone'),
			'daylight_savings' => $ci->settings->get_setting('default_user_dst'),
			'skin_main'    => $ci->settings->get_setting('default_skin_main'),
			'skin_admin'   => $ci->settings->get_setting('default_skin_admin'),
			'skin_wiki'    => $ci->settings->get_setting('default_skin_wiki'),
			'display_rank' => $ci->settings->get_setting('default_display_rank'),
			'leave_date'   => 0,
			'last_post'    => 0,
			'is_sysadmin'  => 'n',
			'date_register'=> now(),
		);
		// Merge in the Discord-specific columns.
		$row = array_merge($row, self::discordCols($claims));

		$ci->user->create_user($row);

		$newId = (int) $ci->db->insert_id();
		if ($newId > 0) {
			$ci->user->create_user_prefs($newId);
		}
		return $newId;
	}

	/**
	 * Sign a user in - mimics Nova_auth::_set_session, which is
	 * protected so we can't call it directly. The fields here are the
	 * same ones Nova stamps on a normal password login; if Nova
	 * extends the session schema in a future release we'd add fields
	 * here too.
	 */
	public static function loginUserById($userId)
	{
		$ci =& get_instance();
		$ci->load->model('users_model', 'user');
		$ci->load->model('menu_model');
		$ci->load->model('characters_model', 'char');

		$ci->db->where('userid', (int) $userId);
		$person = $ci->db->get('users')->row();
		if ( ! $person) {
			return false;
		}

		$characters = $ci->char->get_user_characters($person->userid, '', 'array');

		$array = array(
			'userid'       => $person->userid,
			'skin_main'    => $person->skin_main,
			'skin_admin'   => $person->skin_admin,
			'skin_wiki'    => $person->skin_wiki,
			'display_rank' => $person->display_rank,
			'language'     => $person->language,
			'timezone'     => $person->timezone,
			'dst'          => $person->daylight_savings,
			'main_char'    => $person->main_char,
			'characters'   => $characters,
			'role'         => $person->access_role,
			'access'       => self::accessForRole($person->access_role),
		);

		// my_links menu shortcuts, same shape Nova builds.
		$myLinks = explode(',', $person->my_links ?? '');
		if (count($myLinks) > 0) {
			foreach ($myLinks as $value) {
				$menus = $ci->menu_model->get_menu_item($value);
				if ($menus->num_rows() > 0) {
					$item = $menus->row();
					$array['my_links'][] = anchor($item->menu_link, $item->menu_name);
				}
			}
		}

		$ci->user->update_login_record($person->userid, now());
		$ci->session->set_userdata($array);
		return true;
	}

	// ---------- internals ----------

	private static function discordCols(array $claims)
	{
		return array(
			'nova_ext_discord_auth_id'             => (string) $claims['sub'],
			'nova_ext_discord_auth_username'       => isset($claims['username'])   ? (string) $claims['username']   : null,
			'nova_ext_discord_auth_avatar'         => isset($claims['avatar'])     ? (string) $claims['avatar']     : null,
			'nova_ext_discord_auth_email_verified' => ! empty($claims['email_verified']) ? 1 : 0,
			'nova_ext_discord_auth_linked_at'      => time(),
		);
	}

	/**
	 * Reproduces Nova_auth::_set_access by going through the same
	 * access_model methods it uses internally (which ARE public).
	 * Cleaner than re-reading the access_roles table directly.
	 */
	private static function accessForRole($roleId)
	{
		$ci =& get_instance();
		$ci->load->model('access_model', 'access');
		$page_ids = $ci->access->get_role_data($roleId);
		return $ci->access->get_pages($page_ids);
	}

	private static function originOf($url)
	{
		$parts = parse_url($url);
		if ( ! $parts || empty($parts['scheme']) || empty($parts['host'])) {
			return '';
		}
		$origin = $parts['scheme'].'://'.$parts['host'];
		if ( ! empty($parts['port'])) {
			$default = ($parts['scheme'] === 'https') ? 443 : 80;
			if ((int) $parts['port'] !== $default) {
				$origin .= ':'.$parts['port'];
			}
		}
		return $origin;
	}
}
