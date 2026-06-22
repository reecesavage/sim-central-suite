<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

require_once MODPATH.'core/libraries/Nova_controller_main.php';

/**
 * Sim Central Suite - Mobile site.
 *
 * A lightweight, phone-friendly UI served at /mobile (a pre_system hook
 * rewrites that to this controller; the full extension URL also works).
 * Members log in and can:
 *   - Manage THEIR mission posts (list, create, edit, save, post, delete)
 *   - Manage THEIR personal logs (list, create, edit, save, post, delete)
 *   - Browse the crew manifest, mission list + detail, and sim tour
 *
 * Theme: dark (default), light, and system (follows OS preference), toggled
 * via a button in the header and persisted in localStorage.
 *
 * All post/log writes use PostWrite / Nova's models so webhooks, emails, and
 * moderation behave identically to the API and the desktop site.
 */
class __extensions__nova_ext_sim_central__Mobile extends Nova_controller_main
{
	public function __construct()
	{
		parent::__construct();
		$this->load->model('posts_model',       'posts');
		$this->load->model('missions_model',    'missions');
		$this->load->model('personallogs_model','logs');
		$this->load->model('characters_model',  'characters');
		$this->load->model('tour_model',        'tour');
	}

	// ========== PUBLIC ROUTES — AUTH ==========

	public function index()
	{
		$this->_gate();
		$this->_requireLogin();
		$this->_postList();
	}

	public function login()
	{
		$this->_gate();

		if (Auth::is_logged_in()) {
			redirect($this->_u());
		}

		$discordOnly = $this->_discordEnabled() && \nova_ext_sim_central\DiscordAuth::loginDiscordOnly();

		if (strtoupper($this->input->server('REQUEST_METHOD')) === 'POST' && ! $discordOnly) {
			$email    = (string) $this->input->post('email');
			$password = (string) $this->input->post('password');
			$code     = Auth::login($email, Auth::hash($password), '');

			if ($code === 0) {
				if ($this->_discordEnabled()
					&& \nova_ext_sim_central\DiscordAuth::requiresLink()
					&& ! $this->_currentUserHasDiscord()) {
					redirect(site_url('extensions/nova_ext_sim_central/DiscordAuth/start').'?intent=mobile');
				}
				redirect($this->_u());
			}
			$this->_loginForm($this->_loginError($code));
			return;
		}

		$this->_loginForm('');
	}

	public function logout()
	{
		$this->_gate();
		Auth::logout();
		redirect($this->_u('login'));
	}

	// ========== PUBLIC ROUTES — POSTS ==========

	public function post()
	{
		$this->_gate();
		$this->_requireLogin();

		$uid      = (int) $this->session->userdata('userid');
		$id       = $this->uri->segment(5);
		$id       = ($id !== null && ctype_digit((string) $id)) ? (int) $id : 0;
		$editFlag = ($this->uri->segment(6) === 'edit');

		$isActivated = false;

		if ($id > 0) {
			$row = $this->posts->get_post($id);
			if ( ! $row) {
				$this->session->set_flashdata('sc_mobile', 'That post no longer exists.');
				redirect($this->_u());
			}
			if ( ! $this->_userOnPost($row, $uid)) {
				$this->session->set_flashdata('sc_mobile', 'You can only edit posts you are an author on.');
				redirect($this->_u());
			}

			$isPosted = in_array($row->post_status, array('activated', 'pending'), true);

			if ($isPosted && ! $editFlag) {
				$this->_readonlyPost($row, $uid);
				return;
			}

			$lock = \nova_ext_sim_central\PostWrite::lockState($row, $uid);
			if ($lock['state'] === 'held') {
				$this->_readonlyPost($row, $uid,
					'Locked by '.$lock['owner'].', editing '.$lock['age'].' min ago.');
				return;
			}
			\nova_ext_sim_central\PostWrite::acquireLock($id, $uid);

			$isActivated = $isPosted;
			$values = $this->_valuesFromPost($row);
		} else {
			$values = $this->_defaultValues($uid);
		}

		$this->_editor($id, $values, '', $isActivated);
	}

	public function save()
	{
		$this->_gate();
		$this->_requireLogin();

		$uid     = (int) $this->session->userdata('userid');
		$postId  = (int) $this->input->post('post_id');
		$publish = ($this->input->post('action') === 'post');
		$values  = $this->_valuesFromInput();

		$existing = null;
		if ($postId > 0) {
			$existing = $this->posts->get_post($postId);
			if ( ! $existing || ! $this->_userOnPost($existing, $uid)) {
				$this->session->set_flashdata('sc_mobile', 'You can only edit posts you are an author on.');
				redirect($this->_u());
			}
		}
		$isActivated = $existing
			&& in_array($existing->post_status, array('activated', 'pending'), true);

		$title = trim($values['title']);
		if ($title === '') {
			return $this->_editor($postId, $values, 'A title is required.', $isActivated);
		}

		$authorIds = array();
		foreach ((array) $values['authors'] as $a) {
			$n = (int) $a;
			if ($n > 0) { $authorIds[$n] = true; }
		}
		$authorIds = array_keys($authorIds);
		if (empty($authorIds) || ! $this->_charactersExist($authorIds)) {
			return $this->_editor($postId, $values, 'Pick at least one valid author.', $isActivated);
		}

		$userChars = \nova_ext_sim_central\PostWrite::userCharacterIds($uid);
		if (empty(array_intersect($authorIds, $userChars))) {
			return $this->_editor($postId, $values, 'At least one of your own characters must be on the post.', $isActivated);
		}

		$missionId = (int) $values['mission_id'];
		if ($missionId <= 0 || $this->db->where('mission_id', $missionId)->count_all_results('missions') < 1) {
			return $this->_editor($postId, $values, 'Choose a mission.', $isActivated);
		}

		$tlErrors = \nova_ext_sim_central\PostWrite::timelineErrors($missionId, $values);
		if ( ! empty($tlErrors)) {
			return $this->_editor($postId, $values, $tlErrors[0], $isActivated);
		}

		$authorsCsv = implode(',', $authorIds);
		$usersCsv   = \nova_ext_sim_central\PostWrite::authorsUsersCsv($authorIds);
		$actor      = \nova_ext_sim_central\PostWrite::resolveActor($uid, $authorIds);
		if ($actor <= 0) { $actor = (int) ($userChars ? $userChars[0] : $authorIds[0]); }

		$reqStatus = $publish ? 'activated' : 'saved';
		$status    = ($reqStatus === 'activated')
			? \nova_ext_sim_central\PostWrite::moderatedStatus('activated', $authorsCsv)
			: 'saved';

		\nova_ext_sim_central\PostWrite::populateRequestInputs($values);

		$orderedOn = $this->_featureOn('ordered_mission_posts');
		$fields = array(
			'post_authors'       => $authorsCsv,
			'post_authors_users' => $usersCsv,
			'post_title'         => $title,
			'post_content'       => (string) $values['body'],
			'post_tags'          => $this->_tagsCsv($values['tags']),
			'post_location'      => (string) $values['location'],
			'post_mission'       => $missionId,
			'post_status'        => $status,
			'post_saved'         => $actor,
			'post_participants'  => $usersCsv,
			'post_last_update'   => now(),
		);
		if ( ! $orderedOn) {
			$fields['post_timeline'] = (string) $values['timeline'];
		}

		$wasActivated = $existing ? ($existing->post_status === 'activated') : false;

		if ($postId > 0) {
			$this->posts->update_post($postId, $fields);
			$newId = $postId;
		} else {
			$fields['post_date']      = now();
			$fields['post_lock_user'] = 0;
			$fields['post_lock_date'] = 0;
			$this->posts->create_mission_entry($fields);
			$newId = (int) $this->db->insert_id();
			if ($newId <= 0 || ! $this->posts->get_post($newId)) {
				$rec = $this->db->where('post_title', $title)->where('post_saved', $actor)
					->order_by('post_id', 'desc')->limit(1)->get('posts')->row();
				$newId = $rec ? (int) $rec->post_id : 0;
			}
		}

		if ($postId > 0) {
			\nova_ext_sim_central\PostWrite::releaseLock($postId);
		}

		if ($status === 'activated' && ! $wasActivated) {
			\nova_ext_sim_central\PostWrite::afterActivate($newId, $authorIds, $actor);
		} elseif ($status === 'saved') {
			\nova_ext_sim_central\PostWrite::afterSave($newId, $actor);
		}

		$msg = ($status === 'activated') ? 'Posted.' : ($status === 'pending' ? 'Submitted for moderation.' : 'Draft saved.');
		$this->session->set_flashdata('sc_mobile', $msg);
		redirect($this->_u());
	}

	public function delete()
	{
		$this->_gate();
		$this->_requireLogin();

		$uid    = (int) $this->session->userdata('userid');
		$postId = (int) $this->input->post('post_id');
		$row    = $postId > 0 ? $this->posts->get_post($postId) : false;
		if ( ! $row || ! $this->_userOnPost($row, $uid)) {
			$this->session->set_flashdata('sc_mobile', 'You can only delete posts you are an author on.');
			redirect($this->_u());
		}
		\nova_ext_sim_central\PostWrite::releaseLock($postId);
		$this->posts->delete_post($postId);
		$this->session->set_flashdata('sc_mobile', 'Post deleted.');
		redirect($this->_u());
	}

	public function unpost()
	{
		$this->_gate();
		$this->_requireLogin();

		$uid    = (int) $this->session->userdata('userid');
		$postId = (int) $this->input->post('post_id');
		$row    = $postId > 0 ? $this->posts->get_post($postId) : false;
		if ( ! $row || ! $this->_userOnPost($row, $uid)) {
			$this->session->set_flashdata('sc_mobile', 'You can only update posts you are an author on.');
			redirect($this->_u());
		}
		\nova_ext_sim_central\PostWrite::releaseLock($postId);
		$this->posts->update_post($postId, array(
			'post_status'      => 'saved',
			'post_last_update' => now(),
		));
		$this->session->set_flashdata('sc_mobile', 'Post returned to draft.');
		redirect($this->_u());
	}

	public function cancel()
	{
		$this->_gate();
		$this->_requireLogin();

		$uid    = (int) $this->session->userdata('userid');
		$postId = (int) $this->input->post('post_id');
		$row    = $postId > 0 ? $this->posts->get_post($postId) : false;
		if ($row && $this->_userOnPost($row, $uid)) {
			\nova_ext_sim_central\PostWrite::releaseLock($postId);
		}
		redirect($postId > 0 ? $this->_u('post/'.$postId) : $this->_u());
	}

	/** Read-only view of any activated/pending post (used from mission detail links). */
	public function viewpost()
	{
		$this->_gate();
		$this->_requireLogin();

		$uid = (int) $this->session->userdata('userid');
		$id  = $this->uri->segment(5);
		$id  = ($id !== null && ctype_digit((string) $id)) ? (int) $id : 0;

		if ($id <= 0) {
			redirect($this->_u('missions'));
		}

		$row = $this->posts->get_post($id);
		if ( ! $row || ! in_array($row->post_status, array('activated', 'pending'), true)) {
			$this->session->set_flashdata('sc_mobile', 'Post not found.');
			redirect($this->_u('missions'));
		}

		$isAuthor = $this->_userOnPost($row, $uid);
		$this->_viewPost($row, $isAuthor);
	}

	// ========== PUBLIC ROUTES — PERSONAL LOGS ==========

	public function logs()
	{
		$this->_gate();
		$this->_requireLogin();
		$this->_logList();
	}

	public function log()
	{
		$this->_gate();
		$this->_requireLogin();

		$uid      = (int) $this->session->userdata('userid');
		$id       = $this->uri->segment(5);
		$id       = ($id !== null && ctype_digit((string) $id)) ? (int) $id : 0;
		$editFlag = ($this->uri->segment(6) === 'edit');

		if ($id > 0) {
			$row = $this->logs->get_log($id);
			if ( ! $row || (int) $row->log_author_user !== $uid) {
				$this->session->set_flashdata('sc_mobile', 'Log not found.');
				redirect($this->_u('logs'));
			}

			$isPosted = ($row->log_status === 'activated');

			if ($isPosted && ! $editFlag) {
				$this->_readonlyLog($row, $uid);
				return;
			}

			$values = $this->_logValuesFromRow($row);
			$this->_logEditor($id, $values, '', $isPosted);
		} else {
			$values = $this->_logDefaultValues($uid);
			$this->_logEditor(0, $values, '');
		}
	}

	public function savelog()
	{
		$this->_gate();
		$this->_requireLogin();

		$uid     = (int) $this->session->userdata('userid');
		$logId   = (int) $this->input->post('log_id');
		$publish = ($this->input->post('action') === 'post');
		$values  = $this->_logValuesFromInput();

		$existing = null;
		if ($logId > 0) {
			$existing = $this->logs->get_log($logId);
			if ( ! $existing || (int) $existing->log_author_user !== $uid) {
				$this->session->set_flashdata('sc_mobile', 'You can only edit your own logs.');
				redirect($this->_u('logs'));
			}
		}
		$isActivated = $existing && ($existing->log_status === 'activated');

		$charId    = (int) $values['author_id'];
		$userChars = \nova_ext_sim_central\PostWrite::userCharacterIds($uid);
		if ( ! in_array($charId, $userChars, true)) {
			return $this->_logEditor($logId, $values, 'Select one of your characters as the author.', $isActivated);
		}

		$title = trim($values['title']);
		if ($title === '') {
			return $this->_logEditor($logId, $values, 'A title is required.', $isActivated);
		}

		$status = $publish ? 'activated' : 'saved';

		$data = array(
			'log_title'            => $title,
			'log_content'          => (string) $values['body'],
			'log_tags'             => $this->_tagsCsv($values['tags']),
			'log_author_user'      => $uid,
			'log_author_character' => $charId,
			'log_status'           => $status,
			'log_last_update'      => now(),
		);

		if ($logId > 0) {
			$this->logs->update_log($logId, $data);
		} else {
			$data['log_date'] = now();
			$this->logs->create_personal_log($data);
		}

		$msg = ($status === 'activated') ? 'Log posted.' : 'Log draft saved.';
		$this->session->set_flashdata('sc_mobile', $msg);
		redirect($this->_u('logs'));
	}

	public function deletelog()
	{
		$this->_gate();
		$this->_requireLogin();

		$uid   = (int) $this->session->userdata('userid');
		$logId = (int) $this->input->post('log_id');
		$row   = $logId > 0 ? $this->logs->get_log($logId) : false;
		if ( ! $row || (int) $row->log_author_user !== $uid) {
			$this->session->set_flashdata('sc_mobile', 'You can only delete your own logs.');
			redirect($this->_u('logs'));
		}
		$this->db->delete('personallogs', array('log_id' => $logId));
		$this->session->set_flashdata('sc_mobile', 'Log deleted.');
		redirect($this->_u('logs'));
	}

	public function unpostlog()
	{
		$this->_gate();
		$this->_requireLogin();

		$uid   = (int) $this->session->userdata('userid');
		$logId = (int) $this->input->post('log_id');
		$row   = $logId > 0 ? $this->logs->get_log($logId) : false;
		if ( ! $row || (int) $row->log_author_user !== $uid) {
			$this->session->set_flashdata('sc_mobile', 'You can only update your own logs.');
			redirect($this->_u('logs'));
		}
		$this->logs->update_log($logId, array(
			'log_status'      => 'saved',
			'log_last_update' => now(),
		));
		$this->session->set_flashdata('sc_mobile', 'Log returned to draft.');
		redirect($this->_u('logs'));
	}

	public function cancellog()
	{
		$this->_gate();
		$this->_requireLogin();
		$logId = (int) $this->input->post('log_id');
		redirect($logId > 0 ? $this->_u('log/'.$logId) : $this->_u('logs'));
	}

	// ========== PUBLIC ROUTES — READ-ONLY VIEWS ==========

	public function manifest()
	{
		$this->_gate();
		$this->_requireLogin();
		$this->_manifestPage();
	}

	public function missions()
	{
		$this->_gate();
		$this->_requireLogin();
		$this->_missionList();
	}

	public function mission()
	{
		$this->_gate();
		$this->_requireLogin();
		$id = $this->uri->segment(5);
		$id = ($id !== null && ctype_digit((string) $id)) ? (int) $id : 0;
		if ($id <= 0) { redirect($this->_u('missions')); }
		$this->_missionDetail($id);
	}

	public function tour()
	{
		$this->_gate();
		$this->_requireLogin();
		$this->_tourList();
	}

	public function touritem()
	{
		$this->_gate();
		$this->_requireLogin();
		$id = $this->uri->segment(5);
		$id = ($id !== null && ctype_digit((string) $id)) ? (int) $id : 0;
		if ($id <= 0) { redirect($this->_u('tour')); }
		$this->_tourDetail($id);
	}

	// ========== LOGIN RENDERING ==========

	private function _loginForm($error)
	{
		$discordOn   = $this->_discordEnabled();
		$discordOnly = $discordOn && \nova_ext_sim_central\DiscordAuth::loginDiscordOnly();

		$discordBtn = '';
		if ($discordOn) {
			$url  = site_url('extensions/nova_ext_sim_central/DiscordAuth/start').'?intent=mobile';
			$icon = '<svg viewBox="0 0 71 55" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
				. '<path fill="currentColor" d="M60.10 4.90A58.55 58.55 0 0 0 45.65.42a.23.23 0 0 0-.23.11 40.78 40.78 0 0 0-1.8 3.7 54.05 54.05 0 0 0-16.23 0 37.4 37.4 0 0 0-1.83-3.7.24.24 0 0 0-.23-.11A58.39 58.39 0 0 0 10.88 4.9a.21.21 0 0 0-.1.08C1.58 18.73-.94 32.14.29 45.39a.24.24 0 0 0 .09.16 58.86 58.86 0 0 0 17.72 8.96.23.23 0 0 0 .25-.08 41.9 41.9 0 0 0 3.61-5.88.23.23 0 0 0-.12-.32 38.77 38.77 0 0 1-5.54-2.64.23.23 0 0 1-.02-.38c.37-.28.75-.57 1.1-.86a.22.22 0 0 1 .23-.03c11.62 5.3 24.2 5.3 35.68 0a.22.22 0 0 1 .24.03c.36.29.73.58 1.1.86a.23.23 0 0 1-.02.38 36.4 36.4 0 0 1-5.54 2.63.23.23 0 0 0-.12.33 47.07 47.07 0 0 0 3.6 5.87.23.23 0 0 0 .26.09 58.66 58.66 0 0 0 17.74-8.96.23.23 0 0 0 .1-.16c1.48-15.32-2.48-28.62-10.5-40.41a.18.18 0 0 0-.09-.09ZM23.73 37.33c-3.5 0-6.38-3.21-6.38-7.16 0-3.94 2.83-7.16 6.38-7.16 3.58 0 6.43 3.24 6.38 7.16 0 3.95-2.83 7.16-6.38 7.16Zm23.59 0c-3.5 0-6.38-3.21-6.38-7.16 0-3.94 2.83-7.16 6.38-7.16 3.58 0 6.43 3.24 6.38 7.16 0 3.95-2.8 7.16-6.38 7.16Z"/>'
				. '</svg>';
			$discordBtn = '<a class="sc-btn-discord" href="'.$this->_esc($url).'">'.$icon.'<span>Sign in with Discord</span></a>';
		}

		$body  = '<h1>'.$this->_esc($this->_simName()).'</h1>';
		$body .= '<p class="sc-sub">Mobile</p>';
		if ($error !== '') {
			$body .= '<div class="sc-error">'.$this->_esc($error).'</div>';
		}

		if ( ! $discordOnly) {
			$body .= '<form method="post" action="'.$this->_esc($this->_u('login')).'">'
				. $this->_csrf()
				. '<label>Email<input type="email" name="email" autocomplete="username" required></label>'
				. '<label>Password<input type="password" name="password" autocomplete="current-password" required></label>'
				. '<button class="sc-btn sc-btn-main" type="submit">Log in</button>'
				. '</form>';
		} else {
			$body .= '<p class="sc-sub">This sim requires signing in with Discord.</p>';
		}

		if ($discordBtn !== '') {
			$body .= '<div class="sc-or">'.($discordOnly ? '' : 'or').'</div>'.$discordBtn;
		}

		$this->_layout('Log in', $body, false);
	}

	private function _loginError($code)
	{
		switch ((int) $code) {
			case 2:
			case 3: return 'Email or password is incorrect.';
			case 4: return 'Multiple accounts use that email - contact your GM.';
			case 5: return 'The sim is in maintenance mode. Try again later.';
			case 6: return 'Too many login attempts. Please wait and try again.';
			case 7: return 'Your account is still pending approval.';
			default: return 'Unable to log in.';
		}
	}

	// ========== GUARDS / AUTH HELPERS ==========

	private function _gate()
	{
		$features = \nova_ext_sim_central\Config::features();
		if (empty($features['mobile'])) {
			show_404();
		}
	}

	private function _featureOn($key)
	{
		$features = \nova_ext_sim_central\Config::features();
		return ! empty($features[$key]);
	}

	private function _discordEnabled()
	{
		return $this->_featureOn('discord_auth') && class_exists('nova_ext_sim_central\\DiscordAuth');
	}

	private function _currentUserHasDiscord()
	{
		$uid = (int) $this->session->userdata('userid');
		if ($uid <= 0) { return false; }
		$row = $this->db->select('nova_ext_discord_auth_id')->get_where('users', array('userid' => $uid))->row();
		return $row && ! empty($row->nova_ext_discord_auth_id);
	}

	private function _requireLogin()
	{
		if ( ! Auth::is_logged_in()) {
			redirect($this->_u('login'));
		}
	}

	// ========== HTML HELPERS ==========

	private function _u($path = '')
	{
		return site_url('mobile'.($path !== '' ? '/'.$path : ''));
	}

	private function _esc($s)
	{
		return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
	}

	private function _simName()
	{
		return isset($this->options['sim_name']) && $this->options['sim_name'] !== ''
			? $this->options['sim_name'] : 'Sim Central';
	}

	private function _csrf()
	{
		return '<input type="hidden" name="'.$this->_esc($this->security->get_csrf_token_name())
			.'" value="'.$this->_esc($this->security->get_csrf_hash()).'">';
	}

	// ========== LAYOUT ==========

	private function _layout($title, $bodyHtml, $showNav = true)
	{
		$sim = $this->_esc($this->_simName());
		$nav      = '';
		$themeBtn = '';
		if ($showNav && Auth::is_logged_in()) {
			$nav = '<nav class="sc-nav">'
				. '<a href="'.$this->_esc($this->_u()).'">Posts</a>'
				. '<a href="'.$this->_esc($this->_u('logs')).'">Logs</a>'
				. '<a href="'.$this->_esc($this->_u('manifest')).'">Manifest</a>'
				. '<a href="'.$this->_esc($this->_u('missions')).'">Missions</a>'
				. '<a href="'.$this->_esc($this->_u('tour')).'">Tour</a>'
				. '<a href="'.$this->_esc($this->_u('logout')).'">Log out</a>'
				. '</nav>';
			$themeBtn = '<button class="sc-theme-btn" id="sc-theme-btn" aria-label="Toggle colour theme">Auto</button>';
		}

		// Runs before <style> to avoid any flash of wrong theme.
		$themeInit = '<script>(function(){'
			. 'var t=localStorage.getItem("sc-theme");'
			. 'if(t==="light"||t==="dark")document.documentElement.dataset.theme=t;'
			. '})();</script>';

		header('Content-Type: text/html; charset=utf-8');
		echo '<!DOCTYPE html><html lang="en"><head>'
			. $themeInit
			. '<meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>'.$this->_esc($title).' &middot; '.$sim.'</title>'
			. '<style>'.$this->_css().'</style>'
			. '</head><body><div class="sc-wrap">'
			. '<header class="sc-head">'
			. '<div class="sc-head-top"><a class="sc-brand" href="'.$this->_esc($this->_u()).'">'.$sim.'</a>'.$themeBtn.'</div>'
			. $nav
			. '</header>'
			. '<main class="sc-main">'.$bodyHtml.'</main>'
			. '</div>'
			. $this->_themeToggleJs()
			. '</body></html>';
		exit;
	}

	private function _themeToggleJs()
	{
		return '<script>(function(){'
			. 'var btn=document.getElementById("sc-theme-btn");'
			. 'if(!btn)return;'
			. 'var cycle=["system","light","dark"];'
			. 'var labels={"system":"Auto","light":"Light","dark":"Dark"};'
			. 'function apply(t){'
			. 'if(t==="light"||t==="dark")document.documentElement.dataset.theme=t;'
			. 'else delete document.documentElement.dataset.theme;'
			. 'localStorage.setItem("sc-theme",t);'
			. 'btn.textContent=labels[t]||"Auto";'
			. '}'
			. 'var cur=localStorage.getItem("sc-theme")||"system";'
			. 'btn.textContent=labels[cur]||"Auto";'
			. 'btn.addEventListener("click",function(){'
			. 'var c=localStorage.getItem("sc-theme")||"system";'
			. 'var i=cycle.indexOf(c);'
			. 'apply(cycle[(i+1)%cycle.length]);'
			. '});'
			. '})();</script>';
	}

	private function _css()
	{
		$light = '--sc-bg:#f5f7fa;--sc-bg2:#fff;--sc-bg3:#fff;--sc-bg4:#e8edf5;'
			. '--sc-brd:#dde1ea;--sc-brd2:#cdd3df;--sc-brd3:#e5e9f0;'
			. '--sc-fg:#1a1d23;--sc-fg2:#3a3f4a;--sc-mu:#5a6070;'
			. '--sc-bl:#2563eb;--sc-bl2:#2563eb;'
			. '--sc-err-bg:#fff0f0;--sc-err-fg:#c0392b;'
			. '--sc-ok-bg:#f0fff5;--sc-ok-fg:#1a7a3c;'
			. '--sc-not-bg:#eff6ff;--sc-not-fg:#1d4ed8;'
			. '--sc-dr-bg:#fffbeb;--sc-dr-fg:#92400e;'
			. '--sc-sw:#cdd3df';

		return ':root{'
			. '--sc-bg:#0f1115;--sc-bg2:#141821;--sc-bg3:#161a22;--sc-bg4:#262b36;'
			. '--sc-brd:#232733;--sc-brd2:#2b3040;--sc-brd3:#1c2029;'
			. '--sc-fg:#e6e8eb;--sc-fg2:#c4c8d0;--sc-mu:#9aa0aa;'
			. '--sc-bl:#3b82f6;--sc-bl2:#8ab4f8;'
			. '--sc-err-bg:#3a1d1d;--sc-err-fg:#ff9b9b;'
			. '--sc-ok-bg:#13301f;--sc-ok-fg:#9ff0c0;'
			. '--sc-not-bg:#0d2137;--sc-not-fg:#7dc4f0;'
			. '--sc-dr-bg:#3a2f12;--sc-dr-fg:#ffd479;'
			. '--sc-sw:#2b3040'
			. '}'
			// Explicit light mode
			. ':root[data-theme="light"]{'.$light.'}'
			// System mode: follow OS when theme is not forced
			. '@media(prefers-color-scheme:light){'
			. ':root:not([data-theme]){'.$light.'}}'
			// Base
			. '*{box-sizing:border-box}'
			. 'body{margin:0;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--sc-bg);color:var(--sc-fg)}'
			. '.sc-wrap{max-width:680px;margin:0 auto;padding:0 14px 48px}'
			// Header
			. '.sc-head{border-bottom:1px solid var(--sc-brd);position:sticky;top:0;background:var(--sc-bg);padding:10px 0 0;z-index:10}'
			. '.sc-head-top{display:flex;align-items:center;justify-content:space-between;padding-bottom:8px}'
			. '.sc-brand{color:var(--sc-fg);font-weight:700;text-decoration:none;font-size:18px}'
			. '.sc-theme-btn{background:var(--sc-bg4);border:1px solid var(--sc-brd2);color:var(--sc-fg2);border-radius:6px;padding:3px 9px;font-size:11px;cursor:pointer;font-family:inherit;white-space:nowrap}'
			// Nav: horizontally scrollable on small screens
			. '.sc-nav{display:flex;gap:0;overflow-x:auto;scrollbar-width:none;padding-bottom:10px}'
			. '.sc-nav::-webkit-scrollbar{display:none}'
			. '.sc-nav a{color:var(--sc-bl2);text-decoration:none;font-size:14px;white-space:nowrap;padding-right:16px}'
			. '.sc-nav a:last-child{padding-right:0}'
			// Main
			. '.sc-main{padding:18px 0}'
			. 'h1{font-size:22px;margin:0 0 4px}'
			. '.sc-sub{color:var(--sc-mu);margin:0 0 16px;font-size:14px}'
			// Forms
			. 'label{display:block;margin:0 0 12px;font-size:14px;color:var(--sc-fg2)}'
			. 'input,textarea,select{width:100%;margin-top:4px;padding:11px 12px;border:1px solid var(--sc-brd2);border-radius:8px;background:var(--sc-bg3);color:var(--sc-fg);font:inherit}'
			. 'textarea{min-height:240px;resize:vertical}'
			// Buttons
			. '.sc-btn{display:inline-block;text-align:center;padding:12px 16px;border-radius:8px;border:0;font:inherit;font-weight:600;cursor:pointer;text-decoration:none;margin:6px 0}'
			. '.sc-btn-main{background:var(--sc-bl);color:#fff;width:100%}'
			. '.sc-btn-sec{background:var(--sc-bg4);color:var(--sc-fg)}'
			. '.sc-btn-danger{background:var(--sc-err-bg);color:var(--sc-err-fg)}'
			. '.sc-btn-discord{display:flex;align-items:center;justify-content:center;gap:8px;background:#5865F2;color:#fff;width:100%;padding:12px 16px;border-radius:8px;text-decoration:none;font-weight:600}'
			. '.sc-btn-discord svg{width:20px;height:20px;flex:0 0 auto}'
			. '.sc-or{color:var(--sc-mu);text-align:center;margin:14px 0;font-size:13px}'
			// Alerts
			. '.sc-error{background:var(--sc-err-bg);color:var(--sc-err-fg);padding:10px 12px;border-radius:8px;margin:0 0 14px;font-size:14px}'
			. '.sc-ok{background:var(--sc-ok-bg);color:var(--sc-ok-fg);padding:10px 12px;border-radius:8px;margin:0 0 14px;font-size:14px}'
			. '.sc-notice{background:var(--sc-not-bg);color:var(--sc-not-fg);padding:10px 12px;border-radius:8px;margin:0 0 14px;font-size:14px}'
			// Cards + list
			. '.sc-card{display:block;padding:14px;border:1px solid var(--sc-brd);border-radius:10px;margin:0 0 10px;text-decoration:none;color:inherit;background:var(--sc-bg2)}'
			. '.sc-card h3{margin:0 0 4px;font-size:16px;color:var(--sc-fg)}'
			. '.sc-meta{color:var(--sc-mu);font-size:13px}'
			. '.sc-back{color:var(--sc-bl2);font-size:14px;text-decoration:none}'
			// Badges
			. '.sc-badge{display:inline-block;font-size:11px;padding:2px 8px;border-radius:999px;background:var(--sc-bg4);color:var(--sc-fg2);margin-left:6px}'
			. '.sc-badge-draft{background:var(--sc-dr-bg);color:var(--sc-dr-fg)}'
			. '.sc-badge-cur{background:#0f3023;color:#5ef0a0}'
			. '.sc-badge-pend{background:#1a2a50;color:var(--sc-bl2)}'
			. '.sc-section{margin:0 0 8px;color:var(--sc-mu);font-size:13px;text-transform:uppercase;letter-spacing:.04em}'
			// Layout helpers
			. '.sc-row{display:flex;gap:10px}.sc-row>*{flex:1}'
			. '.sc-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}.sc-actions>form{flex:1}.sc-actions .sc-btn{width:100%}'
			// Author toggles (posts)
			. '.sc-authors{margin:0 0 12px}'
			. '.sc-search{margin:6px 0 10px}'
			. '.sc-colist{max-height:280px;overflow:auto;border:1px solid var(--sc-brd);border-radius:8px;padding:4px 10px;background:var(--sc-bg3)}'
			. '.sc-toggle{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid var(--sc-brd3)}'
			. '.sc-toggle:last-child{border-bottom:0}'
			. '.sc-toggle-label{font-size:15px;color:var(--sc-fg)}'
			. '.sc-switch{position:relative;display:inline-block;width:46px;height:26px;flex:0 0 auto}'
			. '.sc-switch input{opacity:0;width:0;height:0;position:absolute;margin:0}'
			. '.sc-slider{position:absolute;inset:0;background:var(--sc-sw);border-radius:26px;transition:.15s;cursor:pointer}'
			. '.sc-slider:before{content:"";position:absolute;height:20px;width:20px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.15s}'
			. '.sc-switch input:checked + .sc-slider{background:var(--sc-bl)}'
			. '.sc-switch input:checked + .sc-slider:before{transform:translateX(20px)}'
			// Rich-text editor toolbar
			. '.sc-toolbar{display:flex;gap:6px;margin-bottom:4px}'
			. '.sc-toolbar-btn{background:var(--sc-bg4);border:1px solid var(--sc-brd2);color:var(--sc-fg);border-radius:6px;padding:5px 12px;font-weight:700;cursor:pointer;font-size:15px;line-height:1.4;font-family:inherit}'
			. '.sc-toolbar-btn:active,.sc-toolbar-btn.sc-active{background:var(--sc-bl);color:#fff;border-color:var(--sc-bl)}'
			. '.sc-editor{display:block;width:100%;min-height:240px;padding:11px 12px;border:1px solid var(--sc-brd2);border-radius:8px;background:var(--sc-bg3);color:var(--sc-fg);font:inherit;outline:none;overflow-y:auto;word-break:break-word;line-height:1.5;box-sizing:border-box}'
			. '.sc-editor:focus{border-color:var(--sc-bl)}'
			// Post / log body display
			. '.sc-post-body{margin:16px 0;line-height:1.7;font-size:15px;border-top:1px solid var(--sc-brd3);padding-top:14px}'
			// Manifest character cards
			. '.sc-char{display:flex;align-items:center;gap:12px;padding:12px;border:1px solid var(--sc-brd);border-radius:10px;margin:0 0 8px;background:var(--sc-bg2)}'
			. '.sc-char-img{width:48px;height:48px;border-radius:6px;object-fit:cover;flex:0 0 auto}'
			. '.sc-char-ph{width:48px;height:48px;border-radius:6px;flex:0 0 auto;background:var(--sc-bg4);display:flex;align-items:center;justify-content:center;color:var(--sc-mu);font-size:22px}'
			. '.sc-char-body{min-width:0}'
			. '.sc-char-name{font-size:15px;font-weight:600;color:var(--sc-fg)}'
			. '.sc-char-pos{font-size:13px;color:var(--sc-mu);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
			// Images (tour / missions)
			. '.sc-img-main{width:100%;border-radius:10px;display:block;max-height:280px;object-fit:cover;margin:0 0 8px}'
			. '.sc-img-row{display:flex;gap:8px;overflow-x:auto;scrollbar-width:none;margin:0 0 16px}'
			. '.sc-img-row::-webkit-scrollbar{display:none}'
			. '.sc-img-row img{width:120px;height:80px;object-fit:cover;border-radius:6px;flex:0 0 auto}'
			// Tour dynamic fields
			. '.sc-field{margin:0 0 14px}'
			. '.sc-field-label{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--sc-mu);margin:0 0 2px}'
			. '.sc-field-val{font-size:15px;color:var(--sc-fg)}'
			// Mission description block
			. '.sc-mission-body{font-size:15px;line-height:1.7;color:var(--sc-fg);margin:0 0 16px}';
	}

	// ========== POST LIST ==========

	private function _postList()
	{
		$uid     = (int) $this->session->userdata('userid');
		$charIds = \nova_ext_sim_central\PostWrite::userCharacterIds($uid);

		$drafts = ! empty($charIds) ? $this->posts->get_saved_posts($charIds)->result() : array();
		$recent = ! empty($charIds) ? \nova_ext_sim_central\PostWrite::postsByChars($charIds, 'activated', 15) : array();

		$body = '';
		$flash = $this->session->flashdata('sc_mobile');
		if ($flash) {
			$body .= '<div class="sc-ok">'.$this->_esc($flash).'</div>';
		}
		$body .= '<a class="sc-btn sc-btn-main" href="'.$this->_esc($this->_u('post')).'">+ New post</a>';

		$body .= '<p class="sc-section">Drafts</p>';
		if (empty($drafts)) {
			$body .= '<p class="sc-meta">No drafts.</p>';
		} else {
			foreach ($drafts as $p) {
				$body .= $this->_postCard($p, true);
			}
		}

		$body .= '<p class="sc-section">Recent posts</p>';
		if (empty($recent)) {
			$body .= '<p class="sc-meta">Nothing posted yet.</p>';
		} else {
			foreach ($recent as $p) {
				$body .= $this->_postCard($p, false);
			}
		}

		$this->_layout('Posts', $body);
	}

	private function _postCard($p, $isDraft)
	{
		$title = $this->_esc(($p->post_title !== '' && $p->post_title !== null) ? $p->post_title : '(untitled)');
		$when  = ! empty($p->post_date) ? date('M j, Y', (int) $p->post_date) : '';
		$badge = $isDraft ? '<span class="sc-badge sc-badge-draft">draft</span>' : '';
		$href  = $this->_esc($this->_u('post/'.(int) $p->post_id));
		return '<a class="sc-card" href="'.$href.'"><h3>'.$title.$badge.'</h3>'
			. '<div class="sc-meta">'.$this->_esc($when).'</div></a>';
	}

	// ========== POST EDITOR ==========

	private function _editor($postId, $values, $error, $isActivated = false)
	{
		$uid       = (int) $this->session->userdata('userid');
		$orderedOn = $this->_featureOn('ordered_mission_posts');
		$isEdit    = ($postId > 0);

		$b  = '<h1>'.($isEdit ? 'Edit post' : 'New post').'</h1>';
		if ($error !== '') {
			$b .= '<div class="sc-error">'.$this->_esc($error).'</div>';
		}

		$editorHtml = \nova_ext_sim_central\PostWrite::storedToEditorHtml($values['body']);

		$b .= '<form method="post" action="'.$this->_esc($this->_u('save')).'" id="sc-post-form">'.$this->_csrf()
			. '<input type="hidden" name="post_id" value="'.(int) $postId.'">'
			. '<label>Title<input type="text" name="title" value="'.$this->_esc($values['title']).'" required></label>'
			. '<label>Mission'.$this->_missionSelect((int) $values['mission_id']).'</label>'
			. '<div class="sc-section">Authors</div>'
			. '<p class="sc-meta">At least one of your characters is required; add co-authors as needed.</p>'
			. $this->_authorChecks((array) $values['authors'], $uid)
			. '<label>Post'
			. $this->_toolbar()
			. '<div class="sc-editor" id="sc-editor" contenteditable="true">'.$editorHtml.'</div>'
			. '<input type="hidden" name="body" id="sc-body-hidden">'
			. '</label>'
			. '<label>Location<input type="text" name="location" value="'.$this->_esc($values['location']).'"></label>';

		if ($orderedOn) {
			$b .= '<div class="sc-section">Timeline</div>'
				. '<label>Time<input type="time" name="ordered_time" value="'.$this->_esc($values['ordered_time']).'"></label>'
				. '<div data-tl="day_time"><label>Mission day<input type="number" name="ordered_day" value="'.$this->_esc($values['ordered_day']).'"></label></div>'
				. '<div data-tl="date_time"><label>Date<input type="date" name="ordered_date" value="'.$this->_esc($values['ordered_date']).'"></label></div>'
				. '<div data-tl="stardate"><label>Stardate<input type="text" name="ordered_stardate" value="'.$this->_esc($values['ordered_stardate']).'"></label></div>';
		} else {
			$b .= '<label>Timeline<input type="text" name="timeline" value="'.$this->_esc($values['timeline']).'"></label>';
		}

		$b .= '<label>Tags<input type="text" name="tags" value="'.$this->_esc($values['tags']).'" placeholder="comma, separated"></label>';

		if ($isActivated) {
			$b .= '<div class="sc-actions">'
				. '<button class="sc-btn sc-btn-sec" name="action" value="save" type="submit">Return to draft</button>'
				. '<button class="sc-btn sc-btn-main" name="action" value="post" type="submit">Save changes</button>'
				. '</div>';
		} else {
			$b .= '<div class="sc-actions">'
				. '<button class="sc-btn sc-btn-sec" name="action" value="save" type="submit">Save draft</button>'
				. '<button class="sc-btn sc-btn-main" name="action" value="post" type="submit">Post</button>'
				. '</div>';
		}

		$b .= '</form>';

		if ($isEdit) {
			$b .= '<form method="post" action="'.$this->_esc($this->_u('cancel')).'" style="margin-top:8px">'
				. $this->_csrf()
				. '<input type="hidden" name="post_id" value="'.(int) $postId.'">'
				. '<div class="sc-actions"><button class="sc-btn sc-btn-sec" type="submit">Cancel editing</button></div>'
				. '</form>'
				. '<form method="post" action="'.$this->_esc($this->_u('delete')).'" onsubmit="return confirm(\'Delete this post? This cannot be undone.\');">'
				. $this->_csrf()
				. '<input type="hidden" name="post_id" value="'.(int) $postId.'">'
				. '<div class="sc-actions"><button class="sc-btn sc-btn-danger" type="submit">Delete</button></div>'
				. '</form>';
		}

		$b .= $this->_editorJs('sc-editor', 'sc-body-hidden', 'sc-post-form')
			. $this->_timelineJs();

		$this->_layout($isEdit ? 'Edit post' : 'New post', $b);
	}

	private function _readonlyPost($row, $uid, $lockMsg = '')
	{
		$postId   = (int) $row->post_id;
		$title    = ($row->post_title !== '' && $row->post_title !== null) ? $row->post_title : '(untitled)';
		$isLocked = ($lockMsg !== '');
		$status   = (string) $row->post_status;

		$b  = '<h1>'.$this->_esc($title).'</h1>';
		$b .= '<p class="sc-meta">'
			.($status === 'pending' ? 'Pending moderation' : 'Posted')
			.(! empty($row->post_date) ? ' &middot; '.date('M j, Y', (int) $row->post_date) : '')
			.'</p>';

		if ($isLocked) {
			$b .= '<div class="sc-error">'.$this->_esc($lockMsg).'</div>';
		} else {
			$b .= '<div class="sc-notice">This post is already posted.</div>';
		}

		$bodyHtml = function_exists('text_output')
			? text_output((string) $row->post_content, '')
			: nl2br(\nova_ext_sim_central\PostWrite::storedToEditorHtml((string) $row->post_content));
		$b .= '<div class="sc-post-body">'.$bodyHtml.'</div>';

		if ( ! empty($row->post_location)) {
			$b .= '<p class="sc-meta"><strong>Location:</strong> '.$this->_esc($row->post_location).'</p>';
		}
		if ( ! empty($row->post_timeline)) {
			$b .= '<p class="sc-meta"><strong>Timeline:</strong> '.$this->_esc($row->post_timeline).'</p>';
		}
		if ( ! empty($row->post_tags)) {
			$b .= '<p class="sc-meta"><strong>Tags:</strong> '.$this->_esc($row->post_tags).'</p>';
		}

		if ( ! $isLocked) {
			$editUrl = $this->_esc($this->_u('post/'.$postId.'/edit'));
			$b .= '<div class="sc-actions" style="margin-top:16px">'
				. '<a class="sc-btn sc-btn-sec" href="'.$editUrl.'">Edit post</a>'
				. '</div>'
				. '<form method="post" action="'.$this->_esc($this->_u('unpost')).'" style="margin-top:8px">'
				. $this->_csrf()
				. '<input type="hidden" name="post_id" value="'.$postId.'">'
				. '<button class="sc-btn sc-btn-danger" type="submit">Return to draft</button>'
				. '</form>';
		}

		$this->_layout($title, $b);
	}

	/** Read-only post view accessible from any logged-in user (e.g. via mission detail). */
	private function _viewPost($row, $isAuthor)
	{
		$postId = (int) $row->post_id;
		$title  = ($row->post_title !== '' && $row->post_title !== null) ? $row->post_title : '(untitled)';
		$status = (string) $row->post_status;

		// Resolve author names from the character CSV.
		$authorNames = array();
		$authorIds   = array_filter(array_map('intval', explode(',', (string) $row->post_authors)));
		if ( ! empty($authorIds)) {
			$chars = $this->db
				->select('charid, first_name, last_name, display_name')
				->where_in('charid', $authorIds)
				->get('characters')->result();
			foreach ($chars as $c) {
				$authorNames[] = ! empty($c->display_name) ? (string) $c->display_name
					: trim((string) $c->first_name.' '.(string) $c->last_name);
			}
		}

		$b  = '<p class="sc-meta"><a class="sc-back" href="'.$this->_esc($this->_u('missions')).'">← Missions</a></p>';
		$b .= '<h1>'.$this->_esc($title).'</h1>';
		$b .= '<p class="sc-meta">';
		if ( ! empty($authorNames)) {
			$b .= $this->_esc(implode(', ', $authorNames)).' &middot; ';
		}
		$b .= ($status === 'pending' ? 'Pending moderation' : 'Posted');
		$b .= (! empty($row->post_date) ? ' &middot; '.date('M j, Y', (int) $row->post_date) : '');
		$b .= '</p>';

		$bodyHtml = function_exists('text_output')
			? text_output((string) $row->post_content, '')
			: nl2br(\nova_ext_sim_central\PostWrite::storedToEditorHtml((string) $row->post_content));
		$b .= '<div class="sc-post-body">'.$bodyHtml.'</div>';

		if ( ! empty($row->post_location)) {
			$b .= '<p class="sc-meta"><strong>Location:</strong> '.$this->_esc($row->post_location).'</p>';
		}
		if ( ! empty($row->post_timeline)) {
			$b .= '<p class="sc-meta"><strong>Timeline:</strong> '.$this->_esc($row->post_timeline).'</p>';
		}
		if ( ! empty($row->post_tags)) {
			$b .= '<p class="sc-meta"><strong>Tags:</strong> '.$this->_esc($row->post_tags).'</p>';
		}

		if ($isAuthor) {
			$editUrl = $this->_esc($this->_u('post/'.$postId.'/edit'));
			$b .= '<div class="sc-actions" style="margin-top:16px">'
				. '<a class="sc-btn sc-btn-sec" href="'.$editUrl.'">Edit post</a>'
				. '</div>';
		}

		$this->_layout($title, $b);
	}

	private function _missionSelect($selected)
	{
		$rows = $this->missions->get_all_missions('current')->result();
		$out  = '<select name="mission_id" id="sc-mission">';
		if (empty($rows)) {
			$out .= '<option value="">(no current missions)</option>';
		}
		foreach ($rows as $m) {
			$sel = ((int) $m->mission_id === (int) $selected) ? ' selected' : '';
			$cfg = isset($m->mission_ext_ordered_config_setting) ? (string) $m->mission_ext_ordered_config_setting : '';
			$out .= '<option value="'.(int) $m->mission_id.'" data-config="'.$this->_esc($cfg).'"'.$sel.'>'.$this->_esc($m->mission_title).'</option>';
		}
		return $out.'</select>';
	}

	private function _authorChecks($selectedIds, $uid)
	{
		$selectedIds = array_map('intval', $selectedIds);
		$rows = $this->db
			->select('characters.charid, characters.first_name, characters.last_name, characters.suffix, characters.display_name, characters.user, characters.crew_type, ranks.rank_name')
			->from('characters')
			->join('ranks', 'ranks.rank_id = characters.rank', 'left')
			->where_in('characters.crew_type', array('active', 'npc'))
			->order_by('characters.last_name', 'asc')
			->order_by('characters.first_name', 'asc')
			->get()->result();

		$mine   = '';
		$others = '';
		foreach ($rows as $c) {
			$name = ! empty($c->display_name) ? $c->display_name
				: trim(($c->first_name ?? '').' '.($c->last_name ?? '').(empty($c->suffix) ? '' : ' '.$c->suffix));
			$label   = ($c->rank_name ? $this->_esc($c->rank_name).' ' : '').$this->_esc($name);
			$checked = in_array((int) $c->charid, $selectedIds, true) ? ' checked' : '';
			$toggle  = '<label class="sc-toggle" data-name="'.$this->_esc(strtolower($name)).'">'
				. '<span class="sc-toggle-label">'.$label.'</span>'
				. '<span class="sc-switch"><input type="checkbox" name="authors[]" value="'.(int) $c->charid.'"'.$checked.'><span class="sc-slider"></span></span>'
				. '</label>';
			if ((int) $c->user === $uid) { $mine .= $toggle; } else { $others .= $toggle; }
		}

		$html = '<div class="sc-authors">';
		$html .= '<div class="sc-section">Your characters</div>'
			. ($mine !== '' ? $mine : '<p class="sc-meta">You have no characters.</p>');
		if ($others !== '') {
			$html .= '<div class="sc-section">Co-authors</div>'
				. '<input type="search" id="sc-author-search" class="sc-search" placeholder="Search characters…" autocomplete="off">'
				. '<div class="sc-colist" id="sc-coauthors">'.$others.'</div>';
		}
		return $html.'</div>';
	}

	private function _valuesFromPost($row)
	{
		$rawTime = isset($row->nova_ext_ordered_post_time) ? preg_replace('/[^0-9]/', '', (string) $row->nova_ext_ordered_post_time) : '';
		$timeVal = '';
		if ($rawTime !== '') {
			$rawTime = str_pad($rawTime, 4, '0', STR_PAD_LEFT);
			$timeVal = substr($rawTime, 0, 2).':'.substr($rawTime, 2, 2);
		}
		return array(
			'title'            => (string) $row->post_title,
			'body'             => (string) $row->post_content,
			'mission_id'       => (int) $row->post_mission,
			'authors'          => array_values(array_filter(array_map('intval', explode(',', (string) $row->post_authors)))),
			'location'         => isset($row->post_location) ? (string) $row->post_location : '',
			'tags'             => isset($row->post_tags) ? (string) $row->post_tags : '',
			'timeline'         => isset($row->post_timeline) ? (string) $row->post_timeline : '',
			'ordered_time'     => $timeVal,
			'ordered_day'      => isset($row->nova_ext_ordered_post_day) ? (string) $row->nova_ext_ordered_post_day : '',
			'ordered_date'     => isset($row->nova_ext_ordered_post_date) ? (string) $row->nova_ext_ordered_post_date : '',
			'ordered_stardate' => isset($row->nova_ext_ordered_post_stardate) ? (string) $row->nova_ext_ordered_post_stardate : '',
		);
	}

	private function _valuesFromInput()
	{
		$authors = $this->input->post('authors');
		return array(
			'title'            => (string) $this->input->post('title'),
			'body'             => \nova_ext_sim_central\PostWrite::editorHtmlToStored((string) $this->input->post('body')),
			'mission_id'       => (int) $this->input->post('mission_id'),
			'authors'          => is_array($authors) ? $authors : array(),
			'location'         => (string) $this->input->post('location'),
			'tags'             => (string) $this->input->post('tags'),
			'timeline'         => (string) $this->input->post('timeline'),
			'ordered_time'     => (string) $this->input->post('ordered_time'),
			'ordered_day'      => (string) $this->input->post('ordered_day'),
			'ordered_date'     => (string) $this->input->post('ordered_date'),
			'ordered_stardate' => (string) $this->input->post('ordered_stardate'),
		);
	}

	private function _defaultValues($uid)
	{
		$main = (int) $this->session->userdata('main_char');
		return array(
			'title' => '', 'body' => '', 'mission_id' => 0,
			'authors' => $main > 0 ? array($main) : array(),
			'location' => '', 'tags' => '', 'timeline' => '',
			'ordered_time' => '', 'ordered_day' => '', 'ordered_date' => '', 'ordered_stardate' => '',
		);
	}

	private function _charactersExist(array $ids)
	{
		if (empty($ids)) { return false; }
		$found = $this->db->select('charid')->where_in('charid', $ids)->get('characters')->num_rows();
		return $found === count($ids);
	}

	private function _userOnPost($post, $userId)
	{
		$csv = isset($post->post_authors_users) ? (string) $post->post_authors_users : '';
		if ($csv === '') { return false; }
		$ids = array_map('intval', array_filter(explode(',', $csv), 'strlen'));
		return in_array((int) $userId, $ids, true);
	}

	private function _tagsCsv($raw)
	{
		$parts = array_filter(array_map('trim', explode(',', (string) $raw)), 'strlen');
		return implode(',', $parts);
	}

	/** B / I / U formatting toolbar HTML. */
	private function _toolbar()
	{
		return '<div class="sc-toolbar" role="toolbar" aria-label="Formatting">'
			. '<button type="button" class="sc-toolbar-btn" data-cmd="bold" title="Bold"><b>B</b></button>'
			. '<button type="button" class="sc-toolbar-btn" data-cmd="italic" title="Italic"><i>I</i></button>'
			. '<button type="button" class="sc-toolbar-btn" data-cmd="underline" title="Underline"><u>U</u></button>'
			. '</div>';
	}

	/** JS for the contenteditable rich editor: serialize on submit + toolbar handlers. */
	private function _editorJs($editorId, $hiddenId, $formId)
	{
		return '<script>(function(){'
			// Toolbar: B / I / U
			. 'document.querySelectorAll(".sc-toolbar-btn").forEach(function(b){'
			. 'b.addEventListener("mousedown",function(e){'
			. 'e.preventDefault();document.execCommand(this.getAttribute("data-cmd"),false,null);});});'
			// Serialize contenteditable → hidden field on submit
			. 'var form=document.getElementById("'.$formId.'");'
			. 'var ed=document.getElementById("'.$editorId.'");'
			. 'var hid=document.getElementById("'.$hiddenId.'");'
			. 'if(form&&ed&&hid){form.addEventListener("submit",function(){hid.value=ed.innerHTML;});}'
			. '})();</script>';
	}

	/** JS for mission timeline field visibility (posts editor only). */
	private function _timelineJs()
	{
		return '<script>(function(){'
			. 'var sel=document.getElementById("sc-mission");'
			. 'function tl(){var c=(sel&&sel.options[sel.selectedIndex])?sel.options[sel.selectedIndex].getAttribute("data-config"):"";'
			. 'var w=document.querySelectorAll("[data-tl]");for(var i=0;i<w.length;i++){'
			. 'var show=(w[i].getAttribute("data-tl")===c);w[i].style.display=show?"":"none";'
			. 'if(!show){var inp=w[i].querySelector("input");if(inp)inp.value="";}}'
			. '}'
			. 'if(sel){sel.addEventListener("change",tl);}tl();'
			. 'var s=document.getElementById("sc-author-search");if(s){s.addEventListener("input",function(){var q=this.value.toLowerCase();'
			. 'var r=document.querySelectorAll("#sc-coauthors .sc-toggle");for(var j=0;j<r.length;j++){var n=r[j].getAttribute("data-name")||"";r[j].style.display=(n.indexOf(q)!==-1)?"":"none";}});}'
			. '})();</script>';
	}

	// ========== PERSONAL LOG LIST ==========

	private function _logList()
	{
		$uid     = (int) $this->session->userdata('userid');
		$charIds = \nova_ext_sim_central\PostWrite::userCharacterIds($uid);

		$drafts = ! empty($charIds) ? $this->logs->get_saved_logs($charIds)->result() : array();
		$recent = $this->logs->get_user_logs($uid, 15, 'activated')->result();

		$body = '';
		$flash = $this->session->flashdata('sc_mobile');
		if ($flash) {
			$body .= '<div class="sc-ok">'.$this->_esc($flash).'</div>';
		}
		$body .= '<a class="sc-btn sc-btn-main" href="'.$this->_esc($this->_u('log')).'">+ New log</a>';

		$body .= '<p class="sc-section">Drafts</p>';
		if (empty($drafts)) {
			$body .= '<p class="sc-meta">No drafts.</p>';
		} else {
			foreach ($drafts as $log) {
				$body .= $this->_logCard($log, true);
			}
		}

		$body .= '<p class="sc-section">Recent logs</p>';
		if (empty($recent)) {
			$body .= '<p class="sc-meta">Nothing posted yet.</p>';
		} else {
			foreach ($recent as $log) {
				$body .= $this->_logCard($log, false);
			}
		}

		$this->_layout('Logs', $body);
	}

	private function _logCard($log, $isDraft)
	{
		$title = $this->_esc(! empty($log->log_title) ? $log->log_title : '(untitled)');
		$when  = ! empty($log->log_date) ? date('M j, Y', (int) $log->log_date) : '';
		$badge = $isDraft ? '<span class="sc-badge sc-badge-draft">draft</span>' : '';
		$href  = $this->_esc($this->_u('log/'.(int) $log->log_id));
		return '<a class="sc-card" href="'.$href.'"><h3>'.$title.$badge.'</h3>'
			. '<div class="sc-meta">'.$this->_esc($when).'</div></a>';
	}

	// ========== PERSONAL LOG EDITOR ==========

	private function _logEditor($logId, $values, $error, $isActivated = false)
	{
		$uid    = (int) $this->session->userdata('userid');
		$isEdit = ($logId > 0);

		$b  = '<h1>'.($isEdit ? 'Edit log' : 'New log').'</h1>';
		if ($error !== '') {
			$b .= '<div class="sc-error">'.$this->_esc($error).'</div>';
		}

		$editorHtml = \nova_ext_sim_central\PostWrite::storedToEditorHtml($values['body']);

		$b .= '<form method="post" action="'.$this->_esc($this->_u('savelog')).'" id="sc-log-form">'.$this->_csrf()
			. '<input type="hidden" name="log_id" value="'.(int) $logId.'">'
			. '<label>Title<input type="text" name="log_title" value="'.$this->_esc($values['title']).'" required></label>'
			. '<label>Author'.$this->_logCharSelect((int) $values['author_id'], $uid).'</label>'
			. '<label>Log'
			. $this->_toolbar()
			. '<div class="sc-editor" id="sc-log-editor" contenteditable="true">'.$editorHtml.'</div>'
			. '<input type="hidden" name="body" id="sc-log-body-hidden">'
			. '</label>'
			. '<label>Tags<input type="text" name="tags" value="'.$this->_esc($values['tags']).'" placeholder="comma, separated"></label>';

		if ($isActivated) {
			$b .= '<div class="sc-actions">'
				. '<button class="sc-btn sc-btn-sec" name="action" value="save" type="submit">Return to draft</button>'
				. '<button class="sc-btn sc-btn-main" name="action" value="post" type="submit">Save changes</button>'
				. '</div>';
		} else {
			$b .= '<div class="sc-actions">'
				. '<button class="sc-btn sc-btn-sec" name="action" value="save" type="submit">Save draft</button>'
				. '<button class="sc-btn sc-btn-main" name="action" value="post" type="submit">Post</button>'
				. '</div>';
		}

		$b .= '</form>';

		if ($isEdit) {
			$b .= '<form method="post" action="'.$this->_esc($this->_u('cancellog')).'" style="margin-top:8px">'
				. $this->_csrf()
				. '<input type="hidden" name="log_id" value="'.(int) $logId.'">'
				. '<div class="sc-actions"><button class="sc-btn sc-btn-sec" type="submit">Cancel editing</button></div>'
				. '</form>'
				. '<form method="post" action="'.$this->_esc($this->_u('deletelog')).'" onsubmit="return confirm(\'Delete this log? This cannot be undone.\');">'
				. $this->_csrf()
				. '<input type="hidden" name="log_id" value="'.(int) $logId.'">'
				. '<div class="sc-actions"><button class="sc-btn sc-btn-danger" type="submit">Delete</button></div>'
				. '</form>';
		}

		$b .= $this->_editorJs('sc-log-editor', 'sc-log-body-hidden', 'sc-log-form');

		$this->_layout($isEdit ? 'Edit log' : 'New log', $b);
	}

	private function _readonlyLog($row, $uid)
	{
		$logId = (int) $row->log_id;
		$title = ! empty($row->log_title) ? $row->log_title : '(untitled)';

		$b  = '<h1>'.$this->_esc($title).'</h1>';
		$b .= '<p class="sc-meta">Posted'
			.(! empty($row->log_date) ? ' &middot; '.date('M j, Y', (int) $row->log_date) : '')
			.'</p>';
		$b .= '<div class="sc-notice">This log is already posted.</div>';

		$bodyHtml = function_exists('text_output')
			? text_output((string) $row->log_content, '')
			: nl2br(\nova_ext_sim_central\PostWrite::storedToEditorHtml((string) $row->log_content));
		$b .= '<div class="sc-post-body">'.$bodyHtml.'</div>';

		if ( ! empty($row->log_tags)) {
			$b .= '<p class="sc-meta"><strong>Tags:</strong> '.$this->_esc($row->log_tags).'</p>';
		}

		$editUrl = $this->_esc($this->_u('log/'.$logId.'/edit'));
		$b .= '<div class="sc-actions" style="margin-top:16px">'
			. '<a class="sc-btn sc-btn-sec" href="'.$editUrl.'">Edit log</a>'
			. '</div>'
			. '<form method="post" action="'.$this->_esc($this->_u('unpostlog')).'" style="margin-top:8px">'
			. $this->_csrf()
			. '<input type="hidden" name="log_id" value="'.$logId.'">'
			. '<button class="sc-btn sc-btn-danger" type="submit">Return to draft</button>'
			. '</form>';

		$this->_layout($title, $b);
	}

	/** Single-select dropdown of the user's own active characters. */
	private function _logCharSelect($selectedId, $uid)
	{
		$chars = $this->db
			->select('characters.charid, characters.first_name, characters.last_name, characters.display_name, ranks.rank_name')
			->from('characters')
			->join('ranks', 'ranks.rank_id = characters.rank', 'left')
			->where('characters.user', $uid)
			->where_in('characters.crew_type', array('active', 'npc'))
			->order_by('characters.last_name', 'asc')
			->get()->result();

		$out = '<select name="author_id">';
		foreach ($chars as $c) {
			$name = ! empty($c->display_name) ? $c->display_name
				: trim((string) $c->first_name.' '.(string) $c->last_name);
			$label = ($c->rank_name ? $c->rank_name.' ' : '').$name;
			$sel   = ((int) $c->charid === $selectedId) ? ' selected' : '';
			$out  .= '<option value="'.(int) $c->charid.'"'.$sel.'>'.$this->_esc($label).'</option>';
		}
		if (empty($chars)) {
			$out .= '<option value="0">(no characters)</option>';
		}
		return $out.'</select>';
	}

	private function _logValuesFromRow($row)
	{
		return array(
			'title'     => (string) $row->log_title,
			'body'      => (string) $row->log_content,
			'author_id' => (int) $row->log_author_character,
			'tags'      => isset($row->log_tags) ? (string) $row->log_tags : '',
		);
	}

	private function _logValuesFromInput()
	{
		return array(
			'title'     => (string) $this->input->post('log_title'),
			'body'      => \nova_ext_sim_central\PostWrite::editorHtmlToStored((string) $this->input->post('body')),
			'author_id' => (int) $this->input->post('author_id'),
			'tags'      => (string) $this->input->post('tags'),
		);
	}

	private function _logDefaultValues($uid)
	{
		$main = (int) $this->session->userdata('main_char');
		return array(
			'title'     => '',
			'body'      => '',
			'author_id' => $main > 0 ? $main : 0,
			'tags'      => '',
		);
	}

	// ========== MANIFEST ==========

	private function _manifestPage()
	{
		$chars = $this->db
			->select('characters.charid, characters.first_name, characters.last_name, characters.display_name, characters.images, characters.crew_type, ranks.rank_name, positions.pos_name')
			->from('characters')
			->join('ranks',     'ranks.rank_id = characters.rank',          'left')
			->join('positions', 'positions.pos_id = characters.position_1', 'left')
			->where_in('characters.crew_type', array('active', 'npc'))
			->order_by('characters.rank',      'asc')
			->order_by('characters.last_name', 'asc')
			->order_by('characters.first_name','asc')
			->get()->result();

		$players = array();
		$npcs    = array();
		foreach ($chars as $c) {
			if ($c->crew_type === 'npc') { $npcs[] = $c; } else { $players[] = $c; }
		}

		$body = '<h1>Manifest</h1>';

		if ( ! empty($players)) {
			$body .= '<p class="sc-section">Crew</p>';
			foreach ($players as $c) { $body .= $this->_charCard($c); }
		}
		if ( ! empty($npcs)) {
			$body .= '<p class="sc-section">NPCs</p>';
			foreach ($npcs as $c) { $body .= $this->_charCard($c); }
		}
		if (empty($players) && empty($npcs)) {
			$body .= '<p class="sc-meta">No characters found.</p>';
		}

		$this->_layout('Manifest', $body);
	}

	private function _charCard($c)
	{
		$name = ! empty($c->display_name) ? (string) $c->display_name
			: trim((string) $c->first_name.' '.(string) $c->last_name);
		$rank = ! empty($c->rank_name) ? $this->_esc($c->rank_name).' ' : '';
		$pos  = ! empty($c->pos_name)  ? $this->_esc($c->pos_name)   : '';

		$imgHtml = '';
		if ( ! empty($c->images)) {
			$imgs = array_filter(array_map('trim', explode(',', (string) $c->images)));
			if ( ! empty($imgs)) {
				$first = reset($imgs);
				// External URLs pass through; local filenames use Nova's asset path.
				$src = (strpos($first, '://') !== false)
					? $first
					: base_url().Location::asset('images/characters', $first);
				$imgHtml = '<img class="sc-char-img" src="'.$this->_esc($src).'" alt="'.$this->_esc($name).'" loading="lazy">';
			}
		}
		if ($imgHtml === '') {
			$imgHtml = '<div class="sc-char-ph">&#128100;</div>';
		}

		return '<div class="sc-char">'.$imgHtml
			. '<div class="sc-char-body">'
			. '<div class="sc-char-name">'.$rank.$this->_esc($name).'</div>'
			. ($pos !== '' ? '<div class="sc-char-pos">'.$pos.'</div>' : '')
			. '</div></div>';
	}

	// ========== MISSIONS ==========

	private function _missionList()
	{
		$all = $this->missions->get_all_missions()->result();

		$groups = array(
			'current'   => array(),
			'upcoming'  => array(),
			'completed' => array(),
		);
		foreach ($all as $m) {
			$key = isset($groups[$m->mission_status]) ? $m->mission_status : 'completed';
			$groups[$key][] = $m;
		}

		$body = '<h1>Missions</h1>';

		$labels = array('current' => 'Current', 'upcoming' => 'Upcoming', 'completed' => 'Completed');
		foreach ($labels as $key => $label) {
			if (empty($groups[$key])) { continue; }
			$body .= '<p class="sc-section">'.$label.'</p>';
			foreach ($groups[$key] as $m) {
				$badgeClass = ($m->mission_status === 'current') ? 'sc-badge-cur'
					: (($m->mission_status === 'upcoming') ? 'sc-badge-pend' : '');
				$badge = $badgeClass
					? '<span class="sc-badge '.$this->_esc($badgeClass).'">'.ucfirst($m->mission_status).'</span>'
					: '';
				$blurb = '';
				if ( ! empty($m->mission_desc)) {
					$plain = strip_tags((string) $m->mission_desc);
					$blurb = '<div class="sc-meta">'.mb_substr($this->_esc($plain), 0, 120)
						.(mb_strlen($plain) > 120 ? '&hellip;' : '').'</div>';
				}
				$href  = $this->_esc($this->_u('mission/'.(int) $m->mission_id));
				$body .= '<a class="sc-card" href="'.$href.'"><h3>'.$this->_esc($m->mission_title).$badge.'</h3>'.$blurb.'</a>';
			}
		}

		if (empty($all)) {
			$body .= '<p class="sc-meta">No missions found.</p>';
		}

		$this->_layout('Missions', $body);
	}

	private function _missionDetail($id)
	{
		$m = $this->missions->get_mission($id);
		if ( ! $m) {
			$this->session->set_flashdata('sc_mobile', 'Mission not found.');
			redirect($this->_u('missions'));
		}

		$posts = $this->db
			->select('post_id, post_title, post_date, post_authors')
			->where('post_mission', $id)
			->where('post_status', 'activated')
			->order_by('post_date', 'DESC')
			->limit(50)
			->get('posts')
			->result();

		$b = '<p><a class="sc-back" href="'.$this->_esc($this->_u('missions')).'">← Missions</a></p>';
		$b .= '<h1>'.$this->_esc($m->mission_title).'</h1>';

		// Status + dates
		$badgeClass = ($m->mission_status === 'current') ? 'sc-badge-cur'
			: (($m->mission_status === 'upcoming') ? 'sc-badge-pend' : '');
		$badge = $badgeClass
			? '<span class="sc-badge '.$this->_esc($badgeClass).'">'.ucfirst($this->_esc($m->mission_status)).'</span>'
			: '';
		$dates = '';
		if ( ! empty($m->mission_start)) {
			$dates .= ' &middot; '.date('M j, Y', (int) $m->mission_start);
		}
		if ( ! empty($m->mission_end)) {
			$dates .= ' &ndash; '.date('M j, Y', (int) $m->mission_end);
		}
		$b .= '<p class="sc-meta">'.$badge.$dates.'</p>';

		// Mission images
		if ( ! empty($m->mission_images)) {
			$imgs = array_filter(array_map('trim', explode(',', (string) $m->mission_images)));
			if ( ! empty($imgs)) {
				$first = reset($imgs);
				$src   = (strpos($first, '://') !== false) ? $first
					: base_url().Location::asset('images/missions', $first);
				$b .= '<img class="sc-img-main" src="'.$this->_esc($src).'" alt="'.$this->_esc($m->mission_title).'" loading="lazy">';
				$rest = array_slice($imgs, 1);
				if ( ! empty($rest)) {
					$b .= '<div class="sc-img-row">';
					foreach ($rest as $img) {
						$s = (strpos($img, '://') !== false) ? $img
							: base_url().Location::asset('images/missions', $img);
						$b .= '<img src="'.$this->_esc($s).'" alt="'.$this->_esc($m->mission_title).'" loading="lazy">';
					}
					$b .= '</div>';
				}
			}
		}

		// Description
		if ( ! empty($m->mission_desc)) {
			$b .= '<div class="sc-mission-body">'.nl2br($this->_esc($m->mission_desc)).'</div>';
		}
		if ( ! empty($m->mission_summary)) {
			$b .= '<p class="sc-section">Summary</p>';
			$b .= '<div class="sc-mission-body">'.nl2br($this->_esc($m->mission_summary)).'</div>';
		}

		// Posts
		$b .= '<p class="sc-section">Posts ('.count($posts).')</p>';
		if (empty($posts)) {
			$b .= '<p class="sc-meta">No posts yet.</p>';
		} else {
			foreach ($posts as $p) {
				$ptitle = ! empty($p->post_title) ? $p->post_title : '(untitled)';
				$when   = ! empty($p->post_date) ? date('M j, Y', (int) $p->post_date) : '';
				$href   = $this->_esc($this->_u('viewpost/'.(int) $p->post_id));
				$b .= '<a class="sc-card" href="'.$href.'">'
					. '<h3>'.$this->_esc($ptitle).'</h3>'
					. '<div class="sc-meta">'.$this->_esc($when).'</div>'
					. '</a>';
			}
		}

		$this->_layout((string) $m->mission_title, $b);
	}

	// ========== TOUR ==========

	private function _tourList()
	{
		$items = $this->tour->get_tour_items('y')->result();

		$body = '<h1>Ship Tour</h1>';

		if (empty($items)) {
			$body .= '<p class="sc-meta">No tour items found.</p>';
		} else {
			foreach ($items as $item) {
				$href = $this->_esc($this->_u('touritem/'.(int) $item->tour_id));
				$blurb = '';
				if ( ! empty($item->tour_summary)) {
					$plain = strip_tags((string) $item->tour_summary);
					$blurb = '<div class="sc-meta">'.mb_substr($this->_esc($plain), 0, 120)
						.(mb_strlen($plain) > 120 ? '&hellip;' : '').'</div>';
				}
				// Show thumbnail from first image if available
				$thumb = '';
				if ( ! empty($item->tour_images)) {
					$imgs = array_filter(array_map('trim', explode(',', (string) $item->tour_images)));
					if ( ! empty($imgs)) {
						$first = reset($imgs);
						$src = (strpos($first, '://') !== false) ? $first
							: base_url().Location::asset('images/tour', $first);
						$thumb = '<img style="width:64px;height:48px;object-fit:cover;border-radius:6px;float:right;margin:0 0 4px 10px" src="'.$this->_esc($src).'" alt="" loading="lazy">';
					}
				}
				$body .= '<a class="sc-card" href="'.$href.'">'.$thumb
					. '<h3>'.$this->_esc($item->tour_name).'</h3>'.$blurb
					. '<div style="clear:both"></div></a>';
			}
		}

		$this->_layout('Ship Tour', $body);
	}

	private function _tourDetail($id)
	{
		$res = $this->tour->get_tour_item($id);
		if ( ! $res || $res->num_rows() < 1) {
			$this->session->set_flashdata('sc_mobile', 'Tour item not found.');
			redirect($this->_u('tour'));
		}
		$item = $res->row();

		$b = '<p><a class="sc-back" href="'.$this->_esc($this->_u('tour')).'">← Tour</a></p>';
		$b .= '<h1>'.$this->_esc($item->tour_name).'</h1>';

		// Images
		if ( ! empty($item->tour_images)) {
			$imgs = array_filter(array_map('trim', explode(',', (string) $item->tour_images)));
			if ( ! empty($imgs)) {
				$first = reset($imgs);
				$src   = (strpos($first, '://') !== false) ? $first
					: base_url().Location::asset('images/tour', $first);
				$b .= '<img class="sc-img-main" src="'.$this->_esc($src).'" alt="'.$this->_esc($item->tour_name).'" loading="lazy">';
				$rest = array_slice($imgs, 1);
				if ( ! empty($rest)) {
					$b .= '<div class="sc-img-row">';
					foreach ($rest as $img) {
						$s = (strpos($img, '://') !== false) ? $img
							: base_url().Location::asset('images/tour', $img);
						$b .= '<img src="'.$this->_esc($s).'" alt="'.$this->_esc($item->tour_name).'" loading="lazy">';
					}
					$b .= '</div>';
				}
			}
		}

		// Summary / description
		if ( ! empty($item->tour_summary)) {
			$b .= '<div class="sc-mission-body">'.nl2br($this->_esc($item->tour_summary)).'</div>';
		}

		// Dynamic fields from tour_fields / tour_data
		$fields = $this->tour->get_tour_fields('y');
		if ($fields && $fields->num_rows() > 0) {
			$b .= '<p class="sc-section">Details</p>';
			foreach ($fields->result() as $field) {
				$info = $this->tour->get_tour_data($id, $field->field_id);
				if ( ! $info || empty($info->data_value)) { continue; }
				$b .= '<div class="sc-field">'
					. '<div class="sc-field-label">'.$this->_esc($field->field_label_page).'</div>'
					. '<div class="sc-field-val">'.$this->_esc($info->data_value).'</div>'
					. '</div>';
			}
		}

		$this->_layout((string) $item->tour_name, $b);
	}
}
