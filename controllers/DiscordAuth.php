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
		if ( ! in_array($intent, array('login', 'join', 'link'), true)) {
			$intent = 'login';
		}
		if ($intent === 'link' && ! \Auth::is_logged_in()) {
			// link-flow only makes sense for a logged-in user; fall back
			// to login.
			$intent = 'login';
		}
		$this->session->set_userdata('discord_auth_intent', $intent);

		redirect(\nova_ext_sim_central\DiscordAuth::brokerStartUrl());
	}

	// ---------- /callback ----------

	public function callback()
	{
		$token = $this->input->get('token', true);
		$error = $this->input->get('error', true);

		$intent = $this->session->userdata('discord_auth_intent') ?: 'login';
		$this->session->unset_userdata('discord_auth_intent');

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

		// 1. Existing user with this Discord ID? Refresh + log them in.
		$user = \nova_ext_sim_central\DiscordAuth::findUserByDiscordId($claims['sub']);
		if ($user !== null) {
			\nova_ext_sim_central\DiscordAuth::refreshUserInfo($user, $claims);
			\nova_ext_sim_central\DiscordAuth::loginUserById($user->userid);
			redirect(site_url(''));
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
			redirect(site_url('admin/usersettings'));
			return;
		}

		// 3. No matching user. Behaviour depends on suite config.
		$mode = \nova_ext_sim_central\DiscordAuth::mode();

		if ($mode === 'auto-create') {
			$result = \nova_ext_sim_central\DiscordAuth::createUserFromClaims($claims);
			if (is_array($result) && isset($result[0]) && $result[0] === 'error') {
				return $this->_renderError($this->_friendlyError($result[1]));
			}
			\nova_ext_sim_central\DiscordAuth::loginUserById((int) $result);
			redirect(site_url(''));
			return;
		}

		// Link-only mode + no existing match. Bail with a clear message
		// pointing the user at the standard join/login flow.
		return $this->_renderError(
			'This Discord account is not linked to any user on this sim. '
			.'Sign in with your existing email and password, then click '
			.'"Link Discord" on your User Settings page.'
		);
	}

	// ---------- /unlink ----------

	public function unlink()
	{
		if ( ! \Auth::is_logged_in()) {
			redirect(site_url('login'));
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
		redirect(site_url('admin/usersettings'));
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
			case 'email_already_in_use':
				return 'A user with that email already exists. Sign in with your existing password and then link Discord from User Settings.';
			case 'no_email':
				return 'Discord did not return an email address. Please try again or sign up the normal way.';
			default:
				if (strpos((string) $code, 'missing_claim:') === 0) {
					return 'Sign-in token was missing required information. Please try again.';
				}
				return 'Sign-in failed: '.htmlspecialchars((string) $code, ENT_QUOTES);
		}
	}
}
