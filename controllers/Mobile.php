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
			$url = site_url('extensions/nova_ext_sim_central/DiscordAuth/start').'?intent=mobile';
			$discordBtn = method_exists('nova_ext_sim_central\\DiscordAuth', 'brandedButtonHtml')
				? \nova_ext_sim_central\DiscordAuth::brandedButtonHtml('Sign in with Discord', $url)
				: '<a class="sc-btn sc-btn-discord" href="'.$this->_esc($url).'">Sign in with Discord</a>';
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
			. '.sc-check{display:flex;align-items:center;gap:8px;margin:0 0 6px;font-size:15px;color:#e6e8eb}'
			. '.sc-check input{width:auto;margin:0}';
	}

	// ---------- post list ----------

	private function _postList()
	{
		$uid     = (int) $this->session->userdata('userid');
		$charIds = \nova_ext_sim_central\PostWrite::userCharacterIds($uid);

		$drafts = ! empty($charIds) ? $this->posts->get_saved_posts($charIds)->result() : array();
		$recent = $this->posts->get_user_posts($uid, 15, 'activated')->result();

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

		$uid = (int) $this->session->userdata('userid');
		$id  = $this->uri->segment(5);
		$id  = ($id !== null && ctype_digit((string) $id)) ? (int) $id : 0;

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
			$values = $this->_valuesFromPost($row);
		} else {
			$values = $this->_defaultValues($uid);
		}

		$this->_editor($id, $values, '');
	}

	public function save()
	{
		$this->_gate();
		$this->_requireLogin();

		$uid     = (int) $this->session->userdata('userid');
		$postId  = (int) $this->input->post('post_id');
		$publish = ($this->input->post('action') === 'post');
		$values  = $this->_valuesFromInput();

		// --- validate ---
		$title = trim($values['title']);
		if ($title === '') {
			return $this->_editor($postId, $values, 'A title is required.');
		}

		$authorIds = array();
		foreach ((array) $values['authors'] as $a) {
			$n = (int) $a;
			if ($n > 0) { $authorIds[$n] = true; }
		}
		$authorIds = array_keys($authorIds);
		if (empty($authorIds) || ! $this->_charactersExist($authorIds)) {
			return $this->_editor($postId, $values, 'Pick at least one valid author.');
		}

		$userChars = \nova_ext_sim_central\PostWrite::userCharacterIds($uid);
		if (empty(array_intersect($authorIds, $userChars))) {
			return $this->_editor($postId, $values, 'At least one of your own characters must be on the post.');
		}

		$missionId = (int) $values['mission_id'];
		if ($missionId <= 0 || $this->db->where('mission_id', $missionId)->count_all_results('missions') < 1) {
			return $this->_editor($postId, $values, 'Choose a mission.');
		}

		$tlErrors = \nova_ext_sim_central\PostWrite::timelineErrors($missionId, $values);
		if ( ! empty($tlErrors)) {
			return $this->_editor($postId, $values, $tlErrors[0]);
		}

		$existing = null;
		if ($postId > 0) {
			$existing = $this->posts->get_post($postId);
			if ( ! $existing || ! $this->_userOnPost($existing, $uid)) {
				$this->session->set_flashdata('sc_mobile', 'You can only edit posts you are an author on.');
				redirect($this->_u());
			}
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
		$this->posts->delete_post($postId);
		$this->session->set_flashdata('sc_mobile', 'Post deleted.');
		redirect($this->_u());
	}

	// ---------- editor helpers ----------

	private function _editor($postId, $values, $error)
	{
		$uid       = (int) $this->session->userdata('userid');
		$orderedOn = $this->_featureOn('ordered_mission_posts');
		$isEdit    = ($postId > 0);

		$b  = '<h1>'.($isEdit ? 'Edit post' : 'New post').'</h1>';
		if ($error !== '') {
			$b .= '<div class="sc-error">'.$this->_esc($error).'</div>';
		}

		$b .= '<form method="post" action="'.$this->_esc($this->_u('save')).'">'.$this->_csrf()
			. '<input type="hidden" name="post_id" value="'.(int) $postId.'">'
			. '<label>Title<input type="text" name="title" value="'.$this->_esc($values['title']).'" required></label>'
			. '<label>Mission'.$this->_missionSelect((int) $values['mission_id']).'</label>'
			. '<div class="sc-section">Authors</div>'
			. '<p class="sc-meta">At least one of your characters is required; add co-authors as needed.</p>'
			. $this->_authorChecks((array) $values['authors'], $uid)
			. '<label>Post<textarea name="body">'.$this->_esc($values['body']).'</textarea></label>'
			. '<label>Location<input type="text" name="location" value="'.$this->_esc($values['location']).'"></label>';

		if ($orderedOn) {
			$b .= '<div class="sc-section">Timeline</div>'
				. '<p class="sc-meta">Fill the field your mission uses (Day, Date, or Stardate). Time applies to all.</p>'
				. '<div class="sc-row">'
				. '<label>Time<input type="time" name="ordered_time" value="'.$this->_esc($values['ordered_time']).'"></label>'
				. '<label>Day<input type="number" name="ordered_day" value="'.$this->_esc($values['ordered_day']).'"></label>'
				. '</div><div class="sc-row">'
				. '<label>Date<input type="date" name="ordered_date" value="'.$this->_esc($values['ordered_date']).'"></label>'
				. '<label>Stardate<input type="text" name="ordered_stardate" value="'.$this->_esc($values['ordered_stardate']).'"></label>'
				. '</div>';
		} else {
			$b .= '<label>Timeline<input type="text" name="timeline" value="'.$this->_esc($values['timeline']).'"></label>';
		}

		$b .= '<label>Tags<input type="text" name="tags" value="'.$this->_esc($values['tags']).'" placeholder="comma, separated"></label>'
			. '<div class="sc-actions">'
			. '<button class="sc-btn sc-btn-sec" name="action" value="save" type="submit">Save draft</button>'
			. '<button class="sc-btn sc-btn-main" name="action" value="post" type="submit">Post</button>'
			. '</div></form>';

		if ($isEdit) {
			$b .= '<form method="post" action="'.$this->_esc($this->_u('delete')).'" onsubmit="return confirm(\'Delete this post? This cannot be undone.\');">'
				. $this->_csrf()
				. '<input type="hidden" name="post_id" value="'.(int) $postId.'">'
				. '<div class="sc-actions"><button class="sc-btn sc-btn-danger" type="submit">Delete</button></div>'
				. '</form>';
		}

		$this->_layout($isEdit ? 'Edit post' : 'New post', $b);
	}

	private function _missionSelect($selected)
	{
		$rows = $this->missions->get_all_missions('current')->result();
		$out  = '<select name="mission_id">';
		if (empty($rows)) {
			$out .= '<option value="">(no current missions)</option>';
		}
		foreach ($rows as $m) {
			$sel = ((int) $m->mission_id === (int) $selected) ? ' selected' : '';
			$out .= '<option value="'.(int) $m->mission_id.'"'.$sel.'>'.$this->_esc($m->mission_title).'</option>';
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
			$label = ($c->rank_name ? $this->_esc($c->rank_name).' ' : '').$this->_esc($name);
			$checked = in_array((int) $c->charid, $selectedIds, true) ? ' checked' : '';
			$row = '<label class="sc-check"><input type="checkbox" name="authors[]" value="'.(int) $c->charid.'"'.$checked.'> '.$label.'</label>';
			if ((int) $c->user === $uid) { $mine .= $row; } else { $others .= $row; }
		}

		$html = '<div class="sc-authors">';
		$html .= '<div class="sc-section">Your characters</div>'.($mine !== '' ? $mine : '<p class="sc-meta">You have no characters.</p>');
		if ($others !== '') {
			$html .= '<div class="sc-section">Co-authors</div>'.$others;
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
			'body'             => (string) $this->input->post('body'),
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
