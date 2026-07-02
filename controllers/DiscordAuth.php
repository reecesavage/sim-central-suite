<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

require_once MODPATH.'core/controllers/nova_login.php';

/**
 * Sim Central Suite - Discord auth controller.
 *
 * Routes (public-facing):
 *   GET  extensions/nova_ext_sim_central/DiscordAuth/start    - bounce to broker
 *   GET  extensions/nova_ext_sim_central/DiscordAuth/callback - receive broker JWT
 *   POST extensions/nova_ext_sim_central/DiscordAuth/unlink   - logged-in users only
 *
 * The "intent" param on /start (login | join | link) is stashed in the
 * session so the callback knows what to do when the Discord ID isn't
 * already in the users table - log-in / sign-up / link-to-current.
 */
class __extensions__nova_ext_sim_central__DiscordAuth extends Nova_login
{
	public function __construct()
	{
		parent::__construct();
		$this->ci =& get_instance();
		$this->ci->load->library('session');
	}

	// ---------- /start ----------

	public function start()
	{
		$intent = $this->input->get('intent', true);
		if ( ! in_array($intent, array('login', 'join', 'link', 'mobile'), true)) {
			$intent = 'login';
		}
		if ($intent === 'link' && ! \Auth::is_logged_in()) {
			// link-flow only makes sense for a logged-in user; fall back
			// to login.
			$intent = 'login';
		}
		$this->session->set_userdata('discord_auth_intent', $intent);

		// "Remember me" checkbox next to the Discord button. Stashed in
		// the session (like the intent) so it survives the OAuth
		// round-trip; the callback reads it back when logging in.
		$this->session->set_userdata('discord_auth_remember',
			$this->input->get('remember', true) === 'yes');

		redirect(\nova_ext_sim_central\DiscordAuth::brokerStartUrl());
	}

	// ---------- /callback ----------

	public function callback()
	{
		$token = $this->input->get('token', true);
		$error = $this->input->get('error', true);

		$intent = $this->session->userdata('discord_auth_intent') ?: 'login';
		$this->session->unset_userdata('discord_auth_intent');

		$remember = (bool) $this->session->userdata('discord_auth_remember');
		$this->session->unset_userdata('discord_auth_remember');

		// Broker bounced back an error (user cancelled on Discord,
		// email_not_verified, etc.). Surface a readable message.
		if ( ! empty($error)) {
			return $this->_renderError($this->_friendlyError($error));
		}

		if (empty($token)) {
			return $this->_renderError('Sign-in did not return a token. Please try again.');
		}

		list($status, $payload) = \nova_ext_sim_central\DiscordAuth::verifyToken($token);
		if ($status !== 'ok') {
			return $this->_renderError($this->_friendlyError($payload));
		}
		$claims = $payload;

		// Guild membership gate (v1.7.0+). Runs before any login /
		// link / join branch so the gate applies uniformly. The check
		// is a no-op when no required guilds are configured.
		list($guildStatus, $guildCode) = \nova_ext_sim_central\DiscordAuth::guildCheckPasses($claims);
		if ($guildStatus !== 'ok') {
			return $this->_renderError($this->_friendlyError($guildCode));
		}

		// 1. Existing user with this Discord ID? Log them in.
		// loginUserById enforces the same status / maintenance checks
		// Nova's email+password login does (pending users can't sign
		// in, maintenance blocks non-sysadmins), so the Discord path
		// can't be used to bypass either gate.
		$user = \nova_ext_sim_central\DiscordAuth::findUserByDiscordId($claims['sub']);
		if ($user !== null) {
			list($loginStatus, $loginCode) = \nova_ext_sim_central\DiscordAuth::loginUserById($user->userid, $remember);
			if ($loginStatus !== 'ok') {
				return $this->_renderError($this->_friendlyError('login_'.$loginCode));
			}
			// intent=mobile sends Discord sign-ins to the suite's mobile site.
			redirect($intent === 'mobile' ? site_url('mobile') : site_url(''));
			return;
		}

		// 2. Logged-in user trying to LINK an unlinked Discord? Attach.
		if ($intent === 'link' && \Auth::is_logged_in()) {
			$currentUserId = $this->session->userdata('userid');
			$ok = \nova_ext_sim_central\DiscordAuth::linkUserToDiscord($currentUserId, $claims);
			if ( ! $ok) {
				return $this->_renderError(
					'That Discord account is already linked to a different user. '
					.'Unlink it there first, then try again.'
				);
			}
			$this->session->set_flashdata('discord_auth_message', 'Discord account linked.');

			// Stamp the discord-only enforcement marker too: linking
			// proves the user owns this Discord account, equivalent to
			// signing in via Discord for the purposes of the
			// "discord-only login" gate.
			$this->session->set_userdata('discord_auth_signed_in', true);

			redirect(site_url('user/account'));
			return;
		}

		// 3. No matching user, intent=join: stash claims in session so
		// the join form picks them up. The actual account creation
		// happens through Nova's normal join flow (which queues the
		// character for GM approval). The db.insert.prepare.users
		// event for that flow stamps the Discord columns onto the new
		// user row using these claims.
		if ($intent === 'join') {
			$this->session->set_userdata('discord_auth_pending_join', json_encode($claims));
			// Also stash the raw signed JWT. The join form embeds it as a
			// hidden field so the Discord identity rides along with the form
			// submit itself - that survives even if the session doesn't
			// persist across the OAuth round-trip. The db-stamp event and the
			// join enforcement gate re-verify it from POST (tamper-proof),
			// and fall back to the session claims when it has since expired.
			$this->session->set_userdata('discord_auth_pending_join_jwt', $token);
			$this->session->set_flashdata('discord_auth_message', 'Discord linked. Fill in the join form to finish signing up.');
			redirect(site_url('main/join'));
			return;
		}

		// 4. Default (intent=login, no match): clear message pointing
		// at the standard sign-up path. We never auto-create accounts
		// because Nova's join flow includes character approval and
		// other steps the suite has no business skipping.
		return $this->_renderError(
			'This Discord account is not linked to any user on this sim. '
			.'If you already have an account, sign in with your email and '
			.'password and then click "Link Discord" on your account page. '
			.'If you are new, use "Sign up with Discord" on the join page '
			.'to start the join process with your Discord identity attached.'
		);
	}

	// ---------- /required ----------

	/**
	 * Forced landing page. Two enforcement hooks redirect here:
	 *
	 *   - requiresLink() (v1.4.0)        - logged-in user has no Discord
	 *                                       ID and the admin has marked
	 *                                       linking as required.
	 *   - loginDiscordOnly() (v1.8.0)    - logged-in user has Discord
	 *                                       linked but didn't sign in
	 *                                       via the Discord flow (and
	 *                                       isn't a sysadmin).
	 *
	 * The single same view adapts its CTA to which case applies, based
	 * on whether the user's row has a stored Discord ID.
	 */
	public function required()
	{
		if ( ! \Auth::is_logged_in()) {
			redirect(site_url('login'));
			return;
		}

		$ci =& get_instance();
		$ci->load->model('users_model', 'user');
		$row = $ci->user->get_user((int) $ci->session->userdata('userid'));

		$hasLinked = $row && ! empty($row->nova_ext_discord_auth_id);
		$signedIn  = (bool) $ci->session->userdata('discord_auth_signed_in');

		// Already linked AND signed in via Discord this session?
		// There's nothing for them to do here - bounce away. Stale
		// open tabs and direct-URL visits land here too.
		if ($hasLinked && $signedIn) {
			redirect(site_url(''));
			return;
		}

		// Pick CTA copy + intent based on whether they need to LINK
		// Discord (no stored ID yet) or just SIGN IN via Discord
		// (stored ID exists but the session marker is missing).
		if ($hasLinked) {
			$title     = 'Please sign in with Discord to continue';
			$ctaLabel  = 'Sign in with Discord';
			$ctaIntent = 'login';
		} else {
			$title     = 'Link your Discord to continue';
			$ctaLabel  = 'Link Discord';
			$ctaIntent = 'link';
		}

		$data = array(
			'title'       => $title,
			'has_linked'  => $hasLinked,
			'button_html' => \nova_ext_sim_central\DiscordAuth::brandedButtonHtml(
				$ctaLabel,
				site_url('extensions/nova_ext_sim_central/DiscordAuth/start?intent='.$ctaIntent)
			),
			'logout_url'  => site_url('login/logout'),
		);
		$this->_regions['title']   .= 'Link your Discord';
		$this->_regions['content']  = $this->extension['nova_ext_sim_central']
			->view('discord_auth_required', $this->skin, 'main', $data);
		Template::assign($this->_regions);
		Template::render();
	}

	// ---------- /unlink ----------

	public function unlink()
	{
		if ( ! \Auth::is_logged_in()) {
			redirect(site_url('login'));
			return;
		}
		// Required-link mode disables unlinking entirely. Users in this
		// mode can still RE-link (which overwrites the existing Discord
		// ID with a new one); they just can't fully detach.
		if (\nova_ext_sim_central\DiscordAuth::requiresLink()) {
			$this->session->set_flashdata('discord_auth_message',
				'Unlinking Discord is disabled - this sim requires every user to have a Discord account linked. Use "Change Discord account" to switch to a different one.');
			redirect(site_url('user/account'));
			return;
		}
		$userId = $this->session->userdata('userid');
		$result = \nova_ext_sim_central\DiscordAuth::unlinkUser($userId);
		if (is_array($result) && $result[0] === 'error') {
			$this->session->set_flashdata('discord_auth_message',
				'Could not unlink: set a password first so you can still sign in without Discord.');
		} else {
			$this->session->set_flashdata('discord_auth_message', 'Discord account unlinked.');
		}
		redirect(site_url('user/account'));
	}

	// ---------- helpers ----------

	private function _renderError($message)
	{
		$this->_regions['title']   .= 'Discord sign-in';
		$this->_regions['content']  = $this->extension['nova_ext_sim_central']
			->view('discord_auth_error', $this->skin, 'main', array(
				'title'   => 'Discord sign-in',
				'message' => $message,
				'login_url' => site_url('login'),
				'join_url'  => site_url('main/join'),
			));
		Template::assign($this->_regions);
		Template::render();
	}

	private function _friendlyError($code)
	{
		switch ($code) {
			case 'email_not_verified':
				return 'Your Discord email is not verified. Verify it on Discord, then try again.';
			case 'access_denied':
				return 'You cancelled the Discord sign-in.';
			case 'token_exchange_failed':
			case 'identity_fetch_failed':
				return 'Discord returned an unexpected error. Please try again in a moment.';
			case 'expired':
				return 'Sign-in took too long and expired. Please try again.';
			case 'broker_key_not_configured':
				return 'The site administrator has not finished configuring Discord sign-in. Please contact them.';
			case 'invalid_signature':
			case 'wrong_issuer':
			case 'wrong_audience':
				return 'The sign-in token failed verification. Please try again.';

			// Status / maintenance refusals from loginUserById - reuse
			// Nova's own lang strings so the messages match exactly
			// what email+password sign-in shows for the same conditions.
			case 'login_pending':
				return sprintf(lang('error_login_7'), lang('global_game_master'), lang('global_game_master'));
			case 'login_maintenance':
				return lang('error_login_5');
			case 'login_not_found':
				return 'The user account linked to this Discord could not be found.';

			// Guild membership refusals (v1.7.0+).
			// `guild_not_member`         - user passed JWT verify and email
			//                              gate but isn't in the required
			//                              Discord server(s). We show the
			//                              admin's free-form help text
			//                              (which can include invite-link
			//                              HTML) under a standard intro.
			// `broker_lacks_guilds_claim` - guild check was configured
			//                              but the broker didn't include
			//                              a `guilds` claim. Almost always
			//                              an old broker (<v1.1.0).
			case 'guild_not_member':
				$help = \nova_ext_sim_central\DiscordAuth::requiredGuildHelp();
				$mode = \nova_ext_sim_central\DiscordAuth::requiredGuildMode();
				$intro = ($mode === 'all')
					? 'You must be a member of <em>every</em> required Discord server to use this sim.'
					: 'You must be a member of at least one required Discord server to use this sim.';
				// Help text is admin-trusted - allow HTML so they can
				// include invite-link anchors.
				$body = $help !== '' ? '<br /><br />'.$help : '';
				return $intro.$body;
			case 'broker_lacks_guilds_claim':
				return 'This sim requires Discord guild membership checks, but the OAuth broker did not return your guild list. '
					.'The site administrator needs to update the broker to v1.1.0+ or remove the required guilds setting. '
					.'Please contact them.';

			default:
				if (strpos((string) $code, 'missing_claim:') === 0) {
					return 'Sign-in token was missing required information. Please try again.';
				}
				return 'Sign-in failed: '.htmlspecialchars((string) $code, ENT_QUOTES);
		}
	}
}
