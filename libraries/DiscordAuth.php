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

	/**
	 * Request-scoped hand-off of the Discord claims stamped onto a new user
	 * row during the join flow. Set by events/discord_auth_db.php when it
	 * stamps the columns; read by events/discord_auth_email_join.php so the
	 * GM notification email can show the linked Discord identity (by then the
	 * session stash has already been cleared).
	 */
	public static $joinClaims = null;

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

	/**
	 * True if the admin has marked Discord linking as required during
	 * the join flow. Enforced client-side on the join form; the suite
	 * also stamps the session-stashed claims onto the new user row
	 * regardless of this setting (so opting in to linking always works).
	 *
	 * Implicitly true when requiresLink() is on - the global require
	 * subsumes the join-only flag.
	 */
	public static function requiredOnJoin()
	{
		if (self::requiresLink()) {
			return true;
		}
		$c = Config::load();
		return ! empty($c['setting']['discord_auth_required_on_join']);
	}

	/**
	 * Hard server-side gate for the "Discord linking required to join" rule.
	 * Returns true when the current request is the join-form submit, linking
	 * is required, and the applicant has NOT proved a Discord link (no valid
	 * signed JWT on the POST, no pending claims in the session). init.php calls
	 * this and bounces back to the join form when it returns true - so the
	 * requirement holds even if a user bypasses the client-side JS guard.
	 */
	public static function requiredJoinMissingLink($ci)
	{
		if ( ! self::requiredOnJoin()) {
			return false;
		}
		if (strtoupper((string) $ci->input->server('REQUEST_METHOD')) !== 'POST') {
			return false;
		}
		// Only the actual join submit (the submit button is present), not the
		// disclaimer step or an unrelated POST elsewhere on the site.
		if ( ! $ci->input->post('submit')) {
			return false;
		}
		if ($ci->router->fetch_class() !== 'main' || $ci->router->fetch_method() !== 'join') {
			return false;
		}

		// Linked if the form carried a valid signed JWT...
		$jwt = $ci->input->post('discord_auth_jwt');
		if (is_string($jwt) && $jwt !== '') {
			list($status, ) = self::verifyToken($jwt);
			if ($status === 'ok') {
				return false;
			}
		}
		// ...or the callback stashed claims in the session.
		$pending = $ci->session->userdata('discord_auth_pending_join');
		if ( ! empty($pending)) {
			$decoded = json_decode((string) $pending, true);
			if (is_array($decoded) && ! empty($decoded['sub'])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * True if the admin has marked Discord linking as required for
	 * every logged-in user. When on, the init.php enforcement hook
	 * redirects any logged-in user without a Discord ID to the
	 * forced-link page until they link.
	 *
	 * The login form itself is NOT blocked - users can still sign
	 * in with email + password (useful if Discord OAuth is down).
	 * They just can't navigate anywhere except the forced-link page
	 * until they finish linking.
	 *
	 * Implicitly true when loginDiscordOnly() is on - if users have
	 * to sign in via Discord, they have to have Discord linked.
	 */
	public static function requiresLink()
	{
		if (self::loginDiscordOnly()) {
			return true;
		}
		$c = Config::load();
		return ! empty($c['setting']['discord_auth_required']);
	}

	/**
	 * True if the admin has restricted sign-in to "Discord only" -
	 * the email + password login form is hidden by default (revealable
	 * behind a "sysadmin sign-in" toggle), and non-sysadmin users who
	 * successfully log in via email + password are immediately bounced
	 * to a Discord sign-in page. Sysadmins can still use email +
	 * password (escape hatch for when Discord OAuth is down).
	 *
	 * Enforced via:
	 *   - login form UI hides the email + password form (event)
	 *   - per-request hook in init.php redirects non-sysadmin users
	 *     who lack the discord_auth_signed_in session marker
	 *   - loginUserById sets the marker when a user signs in via the
	 *     Discord flow; linkUserToDiscord (from the controller's link
	 *     branch) also sets it, since linking proves Discord ownership
	 */
	public static function loginDiscordOnly()
	{
		$c = Config::load();
		return ! empty($c['setting']['discord_auth_login_discord_only']);
	}

	/**
	 * Per-request enforcement for the Discord-only login mode. Returns
	 * true if the current request should be redirected to the forced
	 * Discord sign-in page (controller's required() route).
	 *
	 * Skipped when:
	 *   - login_discord_only is off
	 *   - user isn't logged in
	 *   - user is a sysadmin (escape hatch)
	 *   - user signed in via the Discord flow this session
	 *   - request is for a URL that must stay reachable (Discord auth
	 *     flow itself, logout, assets)
	 */
	public static function shouldEnforceDiscordOnly($currentUri)
	{
		if ( ! self::loginDiscordOnly()) {
			return false;
		}
		if ( ! \Auth::is_logged_in()) {
			return false;
		}

		$ci =& get_instance();
		$userid = (int) $ci->session->userdata('userid');
		if ($userid <= 0) {
			return false;
		}

		// Sysadmin escape hatch - they can use email + password.
		if (\Auth::is_sysadmin($userid)) {
			return false;
		}

		// Admin-listed exempt users (e.g. service accounts) bypass enforcement.
		if (self::isLinkExcluded($userid)) {
			return false;
		}

		// Session marker set on Discord-flow login OR Discord link
		// (linking proves ownership, equivalent to a fresh sign-in).
		if ($ci->session->userdata('discord_auth_signed_in')) {
			return false;
		}

		// Same skip list as requiresLink - keep the Discord auth flow
		// reachable, keep logout reachable, ignore static assets.
		$skip = array(
			'extensions/nova_ext_sim_central/discordauth',
			'login',
			'assets/',
		);
		$uri = strtolower(ltrim((string) $currentUri, '/'));
		foreach ($skip as $prefix) {
			if (strpos($uri, $prefix) === 0) {
				return false;
			}
		}

		return true;
	}

	/**
	 * URL of the forced-link page rendered by DiscordAuth::required().
	 */
	public static function requiredPageUrl()
	{
		return site_url('extensions/nova_ext_sim_central/DiscordAuth/required');
	}

	/**
	 * Required Discord guild membership: returns an array of Discord
	 * snowflake-ID strings the user must be in (per `requiredGuildMode()`)
	 * to sign in / sign up / link. Empty array = no check.
	 *
	 * Stored under the `discord_auth_required_guild_ids` setting as a
	 * JSON array. Defaults to empty.
	 */
	public static function requiredGuildIds()
	{
		$c = Config::load();
		$v = isset($c['setting']['discord_auth_required_guild_ids']) ? $c['setting']['discord_auth_required_guild_ids'] : array();
		if ( ! is_array($v)) {
			return array();
		}
		// Defensive: cast every entry to a digit-only string. Discord IDs
		// are snowflakes; anything else came in via a bad config edit.
		$out = array();
		foreach ($v as $id) {
			$id = preg_replace('/[^0-9]/', '', (string) $id);
			if ($id !== '') {
				$out[] = $id;
			}
		}
		return $out;
	}

	public static function requiresGuildCheck()
	{
		return count(self::requiredGuildIds()) > 0;
	}

	/** 'any' (default) or 'all'. */
	public static function requiredGuildMode()
	{
		$c = Config::load();
		$m = isset($c['setting']['discord_auth_required_guild_mode']) ? (string) $c['setting']['discord_auth_required_guild_mode'] : 'any';
		return ($m === 'all') ? 'all' : 'any';
	}

	/**
	 * Admin-editable help text shown on the "not allowed - join the
	 * server" error page. HTML allowed (admin-trusted input). Empty
	 * falls back to a generic message in the controller.
	 */
	public static function requiredGuildHelp()
	{
		$c = Config::load();
		return isset($c['setting']['discord_auth_required_guild_help'])
			? (string) $c['setting']['discord_auth_required_guild_help']
			: '';
	}

	/**
	 * Nova user IDs exempt from the link-required / Discord-only enforcement.
	 * Stored under `discord_auth_required_exclude_user_ids` as a JSON array of
	 * integers. Empty = nobody is exempt. Unlike the Discord-only flow there is
	 * NO automatic sysadmin exemption for the link requirement - exemptions are
	 * exactly the IDs the admin lists here.
	 */
	public static function excludedLinkUserIds()
	{
		$c = Config::load();
		$v = isset($c['setting']['discord_auth_required_exclude_user_ids'])
			? $c['setting']['discord_auth_required_exclude_user_ids'] : array();
		if ( ! is_array($v)) {
			return array();
		}
		$out = array();
		foreach ($v as $id) {
			$id = (int) preg_replace('/[^0-9]/', '', (string) $id);
			if ($id > 0 && ! in_array($id, $out, true)) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/** True if the given Nova user id is exempt from the link enforcement. */
	public static function isLinkExcluded($userid)
	{
		$userid = (int) $userid;
		return $userid > 0 && in_array($userid, self::excludedLinkUserIds(), true);
	}

	/**
	 * Run the guild-membership check against a verified JWT claim set.
	 * Returns array($status, $code):
	 *   ('ok',    null)                       - no check configured, or user passes
	 *   ('error', 'broker_lacks_guilds_claim')- check configured, broker didn't
	 *                                            include the claim (old broker?)
	 *   ('error', 'guild_not_member')         - user is missing required guild(s)
	 *
	 * Calling site treats both error codes as a hard "sign-in refused"
	 * and surfaces an appropriate friendly message.
	 */
	public static function guildCheckPasses(array $claims)
	{
		$required = self::requiredGuildIds();
		if (empty($required)) {
			return array('ok', null);
		}

		if ( ! isset($claims['guilds']) || ! is_array($claims['guilds'])) {
			// Broker didn't include the guilds claim. Could be: old
			// broker that doesn't know about ?guilds=1, or a manually-
			// configured non-canonical broker.
			return array('error', 'broker_lacks_guilds_claim');
		}

		$userGuilds = array_map('strval', $claims['guilds']);
		$mode       = self::requiredGuildMode();

		if ($mode === 'all') {
			foreach ($required as $id) {
				if ( ! in_array($id, $userGuilds, true)) {
					return array('error', 'guild_not_member');
				}
			}
			return array('ok', null);
		}

		// 'any' mode
		foreach ($required as $id) {
			if (in_array($id, $userGuilds, true)) {
				return array('ok', null);
			}
		}
		return array('error', 'guild_not_member');
	}

	/**
	 * Decide whether the request currently in flight should be
	 * intercepted and redirected to the forced-link page. Returns
	 * true only when:
	 *
	 *   - the require-link setting is on
	 *   - someone is logged in
	 *   - that user has no Discord ID on file
	 *   - the request isn't one of the URLs that have to stay reachable
	 *     for the link / sign-out / asset flow to work
	 */
	public static function shouldEnforceLink($currentUri)
	{
		if ( ! self::requiresLink()) {
			return false;
		}
		if ( ! \Auth::is_logged_in()) {
			return false;
		}

		// Skip URLs that must stay reachable for the link flow,
		// logout, and asset/static requests.
		$skip = array(
			'extensions/nova_ext_sim_central/discordauth',
			'login',
			'assets/',
		);
		$uri = strtolower(ltrim((string) $currentUri, '/'));
		foreach ($skip as $prefix) {
			if (strpos($uri, $prefix) === 0) {
				return false;
			}
		}

		// Look up Discord ID for the session's user.
		$ci =& get_instance();
		$userid = (int) $ci->session->userdata('userid');
		if ($userid <= 0) {
			return false;
		}
		// Admin-listed exempt users (e.g. service accounts) bypass enforcement.
		if (self::isLinkExcluded($userid)) {
			return false;
		}

		$ci->load->model('users_model', 'user');
		$row = $ci->user->get_user($userid);
		if ( ! $row) {
			return false;
		}
		return empty($row->nova_ext_discord_auth_id);
	}

	/**
	 * Render the standard Discord-branded button as HTML. Single source
	 * of truth for the button markup - the login form, join form, user
	 * account page, and forced-link page all call this with their own
	 * label text. The CSS class `nova-ext-discord-button` is provided by
	 * the template-render listener (events/discord_auth_template_render.php)
	 * so the colors / hover state / typography apply everywhere it's used.
	 *
	 * Inline SVG is the canonical Clyde mark using currentColor so it
	 * picks up the button's white foreground.
	 */
	public static function brandedButtonHtml($label, $url)
	{
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 71 55" aria-hidden="true">'
			.'<path fill="currentColor" d="M60.1045 4.8978C55.5792 2.8214 50.7265 1.2916 45.6527 0.41542C45.5603 0.39851 45.468 0.440769 45.4204 0.525289C44.7963 1.6353 44.105 3.0834 43.6209 4.2216C38.1637 3.4046 32.7345 3.4046 27.3892 4.2216C26.905 3.0581 26.1886 1.6353 25.5617 0.525289C25.5141 0.443589 25.4218 0.40133 25.3294 0.41542C20.2584 1.2888 15.4057 2.8186 10.8776 4.8978C10.8384 4.9147 10.8048 4.9429 10.7825 4.9795C1.57795 18.7309 -0.943561 32.1443 0.293408 45.3914C0.299005 45.4562 0.335386 45.5182 0.385761 45.5576C6.45866 50.0174 12.3413 52.7249 18.1147 54.5195C18.2071 54.5477 18.305 54.5139 18.3638 54.4378C19.7295 52.5728 20.9469 50.6063 21.9907 48.5383C22.0523 48.4172 21.9935 48.2735 21.8676 48.2256C19.9366 47.4931 18.0979 46.6 16.3292 45.5858C16.1893 45.5041 16.1781 45.304 16.3068 45.2082C16.679 44.9293 17.0513 44.6391 17.4067 44.3461C17.471 44.2926 17.5606 44.2813 17.6362 44.3151C29.2558 49.6202 41.8354 49.6202 53.3179 44.3151C53.3935 44.2785 53.4831 44.2898 53.5502 44.3433C53.9057 44.6363 54.2779 44.9293 54.6529 45.2082C54.7816 45.304 54.7732 45.5041 54.6333 45.5858C52.8646 46.6197 51.0259 47.4931 49.0921 48.2228C48.9662 48.2707 48.9102 48.4172 48.9718 48.5383C50.038 50.6034 51.2554 52.5699 52.5959 54.435C52.6519 54.5139 52.7526 54.5477 52.845 54.5195C58.6464 52.7249 64.529 50.0174 70.6019 45.5576C70.6551 45.5182 70.6887 45.459 70.6943 45.3942C72.1747 30.0791 68.2147 16.7757 60.1968 4.9823C60.1772 4.9429 60.1437 4.9147 60.1045 4.8978ZM23.7259 37.3253C20.2276 37.3253 17.3451 34.1136 17.3451 30.1693C17.3451 26.225 20.1717 23.0133 23.7259 23.0133C27.308 23.0133 30.1626 26.2532 30.1066 30.1693C30.1066 34.1136 27.28 37.3253 23.7259 37.3253ZM47.3178 37.3253C43.8196 37.3253 40.9371 34.1136 40.9371 30.1693C40.9371 26.225 43.7636 23.0133 47.3178 23.0133C50.9 23.0133 53.7545 26.2532 53.6986 30.1693C53.6986 34.1136 50.9 37.3253 47.3178 37.3253Z"/>'
			.'</svg>';

		return '<a href="'.htmlspecialchars($url, ENT_QUOTES).'" class="nova-ext-discord-button">'
			.$svg
			.'<span>'.htmlspecialchars($label, ENT_QUOTES).'</span>'
			.'</a>';
	}

	public static function callbackUrl()
	{
		return site_url('extensions/nova_ext_sim_central/DiscordAuth/callback');
	}

	public static function brokerStartUrl()
	{
		$url = self::brokerUrl().'/start?return_to='.rawurlencode(self::callbackUrl());
		// Opt into the broker's optional `guilds` scope only when this
		// sim actually has a guild check configured. Sims without one
		// keep the smaller `identify email` scope and don't trigger the
		// extra "see your servers" line on Discord's consent screen.
		if (self::requiresGuildCheck()) {
			$url .= '&guilds=1';
		}
		return $url;
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
	 * Returns the column overrides to stamp onto a `users` row at
	 * create or update time. Exposed so the db.insert.prepare event
	 * for the join flow can merge them in.
	 */
	public static function columnsForClaims(array $claims)
	{
		return self::discordCols($claims);
	}

	/**
	 * Sign a user in - mimics Nova_auth::_set_session, which is
	 * protected so we can't call it directly. The fields here are the
	 * same ones Nova stamps on a normal password login; if Nova
	 * extends the session schema in a future release we'd add fields
	 * here too.
	 *
	 * Returns array($status, $code) where $status is 'ok' on success
	 * or 'error' on refusal. Refusal cases mirror Nova_auth::login()
	 * exactly so Discord sign-in and email/password sign-in have the
	 * same gates:
	 *   - 'not_found'   user row missing
	 *   - 'pending'     status is pending (awaiting GM approval)
	 *   - 'maintenance' site is in maintenance and user is not sysadmin
	 *
	 * Codes are mapped to lang strings by the caller (using Nova's
	 * existing error_login_* keys for parity with the password login).
	 */
	public static function loginUserById($userId)
	{
		$ci =& get_instance();
		$ci->load->model('users_model', 'user');
		$ci->load->model('settings_model', 'settings');
		$ci->load->model('menu_model');
		$ci->load->model('characters_model', 'char');

		$ci->db->where('userid', (int) $userId);
		$person = $ci->db->get('users')->row();
		if ( ! $person) {
			return array('error', 'not_found');
		}

		// Status check - parity with Nova_auth::login() case 7.
		if (isset($person->status) && $person->status === 'pending') {
			return array('error', 'pending');
		}

		// Maintenance check - parity with Nova_auth::login() case 5.
		// Sysadmins bypass maintenance; everyone else can't sign in.
		$maintenance = $ci->settings->get_setting('maintenance');
		if ($maintenance === 'on' && (! isset($person->is_sysadmin) || $person->is_sysadmin !== 'y')) {
			return array('error', 'maintenance');
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

		// Marker used by shouldEnforceDiscordOnly() to recognise that
		// this session was established via the Discord OAuth flow (not
		// email + password). The discord-only enforcement hook leaves
		// sessions with this marker alone.
		$ci->session->set_userdata('discord_auth_signed_in', true);
		return array('ok', null);
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
