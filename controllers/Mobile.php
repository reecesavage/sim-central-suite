<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

require_once MODPATH.'core/libraries/Nova_controller_main.php';

/**
 * Sim Central Suite - Mobile site.
 *
 * A lightweight, phone-friendly UI served at /mobile (a pre_system hook
 * rewrites that to this controller; the full extension URL also works).
 * Members log in and manage THEIR mission posts - list, create, edit, save,
 * post (activate), delete - under Nova's normal permissions.
 *
 * Unlike the REST API (Api.php, token-authenticated and sessionless), this is
 * a logged-in browser experience, so it extends Nova_controller_main to get
 * session, Auth, $this->options and the core models, and it honours Nova's
 * CSRF protection (every form carries the token). It renders its own minimal
 * mobile-first HTML rather than the desktop skin, and reuses PostWrite for all
 * writes so webhooks/emails/timelines/moderation behave exactly like the API
 * and the desktop site.
 */
class __extensions__nova_ext_sim_central__Mobile extends Nova_controller_main
{
	public function __construct()
	{
		parent::__construct();
		$this->load->model('posts_model', 'posts');
		$this->load->model('missions_model', 'missions');
	}

	// ---------- pages ----------

	public function index()
	{
		$this->_gate();
		$this->_requireLogin();
		// Filled in by the post-list step.
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
				// Honour a "must link Discord" policy before landing them in.
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

	// ---------- login rendering ----------

	private function _loginForm($error)
	{
		$discordOn   = $this->_discordEnabled();
		$discordOnly = $discordOn && \nova_ext_sim_central\DiscordAuth::loginDiscordOnly();

		$discordBtn = '';
		if ($discordOn) {
			// Our own compact button (Nova's brandedButtonHtml ships an unsized
			// SVG that fills the screen in this minimal layout). intent=mobile
			// makes the Discord login return to /mobile after sign-in.
			$url = site_url('extensions/nova_ext_sim_central/DiscordAuth/start').'?intent=mobile';
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

	// ---------- internals ----------

	/** 404 when the Mobile feature is off (checked per-action; the suite
	 *  namespace isn't loaded until after the constructor). */
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
		if ($uid <= 0) {
			return false;
		}
		$row = $this->db->select('nova_ext_discord_auth_id')->get_where('users', array('userid' => $uid))->row();
		return $row && ! empty($row->nova_ext_discord_auth_id);
	}

	private function _requireLogin()
	{
		if ( ! Auth::is_logged_in()) {
			redirect($this->_u('login'));
		}
	}

	/** Clean mobile URL: /mobile, /mobile/<path>. */
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

	/** Hidden CSRF field matching Nova's protection (mobile uses real sessions). */
	private function _csrf()
	{
		return '<input type="hidden" name="'.$this->_esc($this->security->get_csrf_token_name())
			.'" value="'.$this->_esc($this->security->get_csrf_hash()).'">';
	}

	/**
	 * Render a complete mobile HTML page and stop. Keeps the markup tiny and
	 * fully responsive instead of fighting the desktop skin.
	 */
	private function _layout($title, $bodyHtml, $showNav = true)
	{
		$sim = $this->_esc($this->_simName());
		$nav = '';
		if ($showNav && Auth::is_logged_in()) {
			$nav = '<nav class="sc-nav">'
				. '<a href="'.$this->_esc($this->_u()).'">Posts</a>'
				. '<a href="'.$this->_esc($this->_u('post')).'">New</a>'
				. '<a href="'.$this->_esc($this->_u('logout')).'">Log out</a>'
				. '</nav>';
		}

		header('Content-Type: text/html; charset=utf-8');
		echo '<!DOCTYPE html><html lang="en"><head>'
			. '<meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>'.$this->_esc($title).' &middot; '.$sim.'</title>'
			. '<style>'.$this->_css().'</style>'
			. '</head><body><div class="sc-wrap">'
			. '<header class="sc-head"><a class="sc-brand" href="'.$this->_esc($this->_u()).'">'.$sim.'</a>'.$nav.'</header>'
			. '<main class="sc-main">'.$bodyHtml.'</main>'
			. '</div></body></html>';
		exit;
	}

	private function _css()
	{
		return '*{box-sizing:border-box}'
			. 'body{margin:0;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#0f1115;color:#e6e8eb}'
			. '.sc-wrap{max-width:680px;margin:0 auto;padding:0 14px 48px}'
			. '.sc-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:14px 0;border-bottom:1px solid #232733;position:sticky;top:0;background:#0f1115}'
			. '.sc-brand{color:#fff;font-weight:700;text-decoration:none;font-size:18px}'
			. '.sc-nav a{color:#8ab4f8;text-decoration:none;margin-left:14px;font-size:14px}'
			. '.sc-main{padding:18px 0}'
			. 'h1{font-size:22px;margin:0 0 4px}'
			. '.sc-sub{color:#9aa0aa;margin:0 0 16px;font-size:14px}'
			. 'label{display:block;margin:0 0 12px;font-size:14px;color:#c4c8d0}'
			. 'input,textarea,select{width:100%;margin-top:4px;padding:11px 12px;border:1px solid #2b3040;border-radius:8px;background:#161a22;color:#e6e8eb;font:inherit}'
			. 'textarea{min-height:240px;resize:vertical}'
			. '.sc-btn{display:inline-block;text-align:center;padding:12px 16px;border-radius:8px;border:0;font:inherit;font-weight:600;cursor:pointer;text-decoration:none;margin:6px 0}'
			. '.sc-btn-main{background:#3b82f6;color:#fff;width:100%}'
			. '.sc-btn-sec{background:#262b36;color:#e6e8eb}'
			. '.sc-btn-danger{background:#3a1d1d;color:#ff9b9b}'
			. '.sc-btn-discord{background:#5865F2;color:#fff;width:100%}'
			. '.sc-or{color:#6b7280;text-align:center;margin:14px 0;font-size:13px}'
			. '.sc-error{background:#3a1d1d;color:#ffb4b4;padding:10px 12px;border-radius:8px;margin:0 0 14px;font-size:14px}'
			. '.sc-ok{background:#13301f;color:#9ff0c0;padding:10px 12px;border-radius:8px;margin:0 0 14px;font-size:14px}'
			. '.sc-card{display:block;padding:14px;border:1px solid #232733;border-radius:10px;margin:0 0 10px;text-decoration:none;color:inherit;background:#141821}'
			. '.sc-card h3{margin:0 0 4px;font-size:16px;color:#fff}'
			. '.sc-meta{color:#9aa0aa;font-size:13px}'
			. '.sc-badge{display:inline-block;font-size:11px;padding:2px 8px;border-radius:999px;background:#262b36;color:#c4c8d0;margin-left:6px}'
			. '.sc-badge-draft{background:#3a2f12;color:#ffd479}'
			. '.sc-section{margin:0 0 8px;color:#9aa0aa;font-size:13px;text-transform:uppercase;letter-spacing:.04em}'
			. '.sc-row{display:flex;gap:10px}.sc-row>*{flex:1}'
			. '.sc-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}.sc-actions>form{flex:1}.sc-actions .sc-btn{width:100%}'
			. '.sc-authors{margin:0 0 12px}'
			. '.sc-search{margin:6px 0 10px}'
			. '.sc-colist{max-height:280px;overflow:auto;border:1px solid #232733;border-radius:8px;padding:4px 10px}'
			. '.sc-toggle{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid #1c2029}'
			. '.sc-toggle:last-child{border-bottom:0}'
			. '.sc-toggle-label{font-size:15px;color:#e6e8eb}'
			. '.sc-switch{position:relative;display:inline-block;width:46px;height:26px;flex:0 0 auto}'
			. '.sc-switch input{opacity:0;width:0;height:0;position:absolute;margin:0}'
			. '.sc-slider{position:absolute;inset:0;background:#2b3040;border-radius:26px;transition:.15s;cursor:pointer}'
			. '.sc-slider:before{content:"";position:absolute;height:20px;width:20px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.15s}'
			. '.sc-switch input:checked + .sc-slider{background:#3b82f6}'
			. '.sc-switch input:checked + .sc-slider:before{transform:translateX(20px)}'
			. '.sc-btn-discord{display:flex;align-items:center;justify-content:center;gap:8px;background:#5865F2;color:#fff;width:100%;padding:12px 16px;border-radius:8px;text-decoration:none;font-weight:600}'
			. '.sc-btn-discord svg{width:20px;height:20px;flex:0 0 auto}'
			// Rich-text editor toolbar
			. '.sc-toolbar{display:flex;gap:6px;margin-bottom:4px}'
			. '.sc-toolbar-btn{background:#262b36;border:1px solid #2b3040;color:#e6e8eb;border-radius:6px;padding:5px 12px;font-weight:700;cursor:pointer;font-size:15px;line-height:1.4;font-family:inherit}'
			. '.sc-toolbar-btn:active{background:#3b82f6;color:#fff}'
			. '.sc-editor{display:block;width:100%;min-height:240px;padding:11px 12px;border:1px solid #2b3040;border-radius:8px;background:#161a22;color:#e6e8eb;font:inherit;outline:none;overflow-y:auto;word-break:break-word;line-height:1.5;box-sizing:border-box}'
			. '.sc-editor:focus{border-color:#3b82f6}'
			// Read-only notice and post body display
			. '.sc-notice{background:#0d2137;color:#7dc4f0;padding:10px 12px;border-radius:8px;margin:0 0 14px;font-size:14px}'
			. '.sc-post-body{margin:16px 0;line-height:1.7;font-size:15px;border-top:1px solid #1c2029;padding-top:14px}';
	}

	// ---------- post list ----------

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

	// ---------- post editor ----------

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

			// Non-edit access to a posted post → read-only view.
			if ($isPosted && ! $editFlag) {
				$this->_readonlyPost($row, $uid);
				return;
			}

			// Check lock before entering edit mode.
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

		// Fetch existing post early so we can pass $isActivated to _editor() on
		// validation errors and have the correct button labels on redisplay.
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

		// --- validate ---
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

		// --- assemble ---
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
			// insert_id() can be clobbered by the ordered db.prepare listener's
			// nested query (same as the API); recover by the row we just wrote.
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

	/** Return a posted/pending post to saved (draft) status without editing content. */
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

	/** Release lock and return to the post view (no content changes). */
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

	// ---------- editor helpers ----------

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
			. '<div class="sc-toolbar" role="toolbar" aria-label="Formatting">'
			. '<button type="button" class="sc-toolbar-btn" data-cmd="bold" title="Bold"><b>B</b></button>'
			. '<button type="button" class="sc-toolbar-btn" data-cmd="italic" title="Italic"><i>I</i></button>'
			. '<button type="button" class="sc-toolbar-btn" data-cmd="underline" title="Underline"><u>U</u></button>'
			. '</div>'
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
			// Editing an already-posted post: save keeps it active, or demote to draft.
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

		$b .= '<script>(function(){'
			// Mission timeline field visibility
			. 'var sel=document.getElementById("sc-mission");'
			. 'function tl(){var c=(sel&&sel.options[sel.selectedIndex])?sel.options[sel.selectedIndex].getAttribute("data-config"):"";'
			. 'var w=document.querySelectorAll("[data-tl]");for(var i=0;i<w.length;i++){w[i].style.display=(w[i].getAttribute("data-tl")===c)?"":"none";}}'
			. 'if(sel){sel.addEventListener("change",tl);}tl();'
			// Co-author search filter
			. 'var s=document.getElementById("sc-author-search");if(s){s.addEventListener("input",function(){var q=this.value.toLowerCase();'
			. 'var r=document.querySelectorAll("#sc-coauthors .sc-toggle");for(var j=0;j<r.length;j++){var n=r[j].getAttribute("data-name")||"";r[j].style.display=(n.indexOf(q)!==-1)?"":"none";}});}'
			// Formatting toolbar: B / I / U
			. 'document.querySelectorAll(".sc-toolbar-btn").forEach(function(b){b.addEventListener("mousedown",function(e){'
			. 'e.preventDefault();document.execCommand(this.getAttribute("data-cmd"),false,null);});});'
			// Serialize contenteditable → hidden field on submit
			. 'var form=document.getElementById("sc-post-form");'
			. 'var ed=document.getElementById("sc-editor");'
			. 'var hid=document.getElementById("sc-body-hidden");'
			. 'if(form&&ed&&hid){form.addEventListener("submit",function(){hid.value=ed.innerHTML;});}'
			. '})();</script>';

		$this->_layout($isEdit ? 'Edit post' : 'New post', $b);
	}

	/** Render the read-only view for an activated/pending post. */
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

		// Post body: display via Nova's own pipeline (nl2br + HTMLPurifier).
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

		$mine = '';
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
}
