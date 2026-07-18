<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Sim Central Suite - REST API controller.
 *
 * Public, token-authenticated HTTP endpoints intended for external consumers
 * (n8n flows, scripts, dashboards). Unlike Ajax.php this controller does NOT
 * call Auth::is_logged_in() in the constructor - sessions are irrelevant
 * here. Every method authenticates against the api_tokens table via
 * \nova_ext_sim_central\ApiAuth and emits JSON.
 *
 * URL shape (Nova's extension router):
 *   /extensions/nova_ext_sim_central/Api/<method>
 *
 * Endpoints (v1):
 *   GET  ping              - sanity check. Any valid token.
 *   GET  posts             - list posts (activated only). Scope: posts:read.
 *   GET  posts/{id}        - single post. Scope: posts:read.
 *   GET  characters        - list characters. Scope: characters:read.
 *   GET  characters/{id}   - single character. Scope: characters:read.
 *   GET  missions          - list missions. Scope: missions:read.
 *   GET  missions/{id}     - single mission. Scope: missions:read.
 *   GET  positions         - list open positions (?top=1 for top open). Scope: positions:read.
 *
 * List endpoints accept ?page=N&per_page=M (per_page capped at 100) and
 * resource-specific filters documented on each method below. Responses are
 * always JSON with a stable, whitelisted set of fields so consumers don't
 * inadvertently couple to internal schema columns that may move.
 */
class __extensions__nova_ext_sim_central__Api extends CI_Controller
{
	public function __construct()
	{
		parent::__construct();

		$this->load->database();

		// NOTE: the suite's namespace (\nova_ext_sim_central\Config, ApiAuth, ...)
		// is NOT available here. Nova loads each extension's init.php via the
		// `extensions` hook on post_controller_constructor, which fires AFTER
		// this constructor. Any reference to the suite namespace from here would
		// fatal with "class not found". The feature-toggle 404 gate lives in
		// _gate() and runs as the first call of every endpoint method instead.
	}

	/**
	 * GET /extensions/nova_ext_sim_central/Api/ping
	 *
	 * Returns {ok: true, token_label: "..."} on success. Used by n8n to
	 * validate that a token is good before wiring up the rest of a flow.
	 */
	/**
	 * GET /extensions/nova_ext_sim_central/Api/openapi
	 *
	 * Returns the OpenAPI 3.0 spec for this API. No authentication required -
	 * the spec is a public document by convention (Postman, n8n, etc. fetch
	 * specs unauthenticated to bootstrap integrations). Still gated by the
	 * feature toggle: 404 when REST API is off, so no surface leaks on sims
	 * that haven't opted in.
	 */
	public function openapi()
	{
		$this->_gate();
		$baseUrl = site_url('extensions/nova_ext_sim_central/Api');
		$baseUrl = rtrim($baseUrl, '/');
		$this->_emit(200, \nova_ext_sim_central\ApiEndpoints::toOpenApi($baseUrl));
	}

	public function ping()
	{
		$this->_gate();
		$token = $this->_authenticate(null);
		$this->_emit(200, array(
			'ok'          => true,
			'token_label' => $token->label,
			'now'         => date('c'),
		));
	}

	/**
	 * GET /Api/posts          - list (filters: ?mission=, ?status=, ?page=, ?per_page=)
	 * GET /Api/posts/{id}     - single
	 *
	 * Default status filter is 'activated' (i.e. public). Override with
	 * ?status=any to include drafts/saved, or ?status=saved etc.
	 */
	/**
	 * Mission posts.
	 *
	 *   GET    /Api/posts        - list   (posts:read public, posts:read.own, posts:read.all)
	 *   GET    /Api/posts/{id}   - single
	 *   POST   /Api/posts        - create (posts:write)
	 *   PATCH  /Api/posts/{id}   - update (posts:write / posts:write.all); PUT alias
	 *   DELETE /Api/posts/{id}   - delete (posts:delete / posts:delete.all)
	 *
	 * Read access tiers:
	 *   posts:read       public, activated posts only (legacy behaviour preserved)
	 *   posts:read.own   the bound user's posts, including drafts
	 *   posts:read.all   any post incl. others' drafts (sysadmin bypass)
	 */
	public function posts()
	{
		$this->_gate();
		$this->load->model('posts_model', 'posts');

		$method = $this->_method();

		// Sub-resource: GET|POST|PUT|DELETE /posts/{id}/lock
		if ($this->uri->segment(6) === 'lock') {
			$this->_postsLock($method);
			return;
		}

		if ($method !== 'GET') {
			$this->_postsWrite($method);
			return;
		}

		$token = $this->_authenticate(null);

		// Resolve the strongest read tier this token is entitled to.
		$level = null;
		$uid   = 0;
		if ($this->_tokenHasScope($token, 'posts:read.all')) {
			$user = $this->_requireBoundUser($token);
			if ($this->_canUseAll($token, $user, 'read')) { $level = 'all'; }
		}
		if ($level === null && $this->_tokenHasScope($token, 'posts:read.own')) {
			$user  = $this->_requireBoundUser($token);
			$level = 'own';
			$uid   = (int) $user->userid;
		}
		if ($level === null && $this->_tokenHasScope($token, 'posts:read')) {
			$level = 'public';
		}
		if ($level === null) {
			$this->_emit(403, array('error' => 'Token lacks a posts read scope (posts:read, posts:read.own, or posts:read.all).'));
		}

		$id = $this->uri->segment(5);
		if ($id !== null && $id !== '' && ctype_digit((string) $id)) {
			$row = $this->posts->get_post((int) $id);
			if ( ! $row) { $this->_emit(404, array('error' => 'Post not found.')); }
			if ($level === 'public' && ! $this->_postIsVisible($row)) {
				$this->_emit(404, array('error' => 'Post not found.'));
			}
			if ($level === 'own' && ! $this->_userOnPost($row, $uid)) {
				$this->_emit(404, array('error' => 'Post not found.'));
			}
			$this->_emit(200, $this->_projectPost($row));
		}

		$status  = $this->input->get('status', true);
		$mission = $this->input->get('mission', true);
		list($page, $perPage, $offset) = $this->_paging();

		// Public tokens default to activated (and may still opt into ?status=any
		// as before); own/all default to every status so drafts are included.
		if ($status === null || $status === '') {
			$status = ($level === 'public') ? 'activated' : 'any';
		}

		$this->db->from('posts');
		if ( ! empty($mission)) {
			$this->db->where('post_mission', (int) $mission);
		}
		if ($status !== 'any') {
			$this->db->where('post_status', $status);
		}
		if ($level === 'own') {
			$this->db->where($this->_authorsUsersClause($uid), null, false);
		}
		$total = (int) $this->db->count_all_results('', false);
		$rows  = $this->db->order_by('post_date', 'desc')->limit($perPage, $offset)->get()->result();

		$data = array();
		foreach ($rows as $row) {
			$data[] = $this->_projectPost($row);
		}
		$this->_emit(200, $this->_paginate($data, $total, $page, $perPage));
	}

	// ---------- post write (create / update / delete) ----------

	/** Dispatch non-GET /posts requests by verb. */
	private function _postsWrite($method)
	{
		$token = $this->_authenticate(null);

		$id    = $this->uri->segment(5);
		$hasId = ($id !== null && $id !== '' && ctype_digit((string) $id));
		$id    = $hasId ? (int) $id : 0;

		// Surface unexpected errors as a clean JSON 500 instead of a blank
		// fatal page - a privileged write client should get something it can
		// act on. (_emit() exits, so successful handlers never reach the catch.)
		try {
			switch ($method) {
				case 'POST':
					if ($hasId) {
						$this->_emit(405, array('error' => 'Use PATCH /posts/{id} to update, POST /posts (no id) to create.'));
					}
					$this->_postCreate($token);
					break;
				case 'PUT':
				case 'PATCH':
					if ( ! $hasId) { $this->_emit(400, array('error' => 'Post id required in path.')); }
					$this->_postUpdate($token, $id);
					break;
				case 'DELETE':
					if ( ! $hasId) { $this->_emit(400, array('error' => 'Post id required in path.')); }
					$this->_postDelete($token, $id);
					break;
				default:
					$this->_emit(405, array('error' => 'Method not allowed. Use GET, POST, PATCH, PUT, or DELETE.'));
			}
		} catch (\Throwable $e) {
			$this->_emit(500, array(
				'error'  => 'Server error while handling the post write.',
				'detail' => $e->getMessage(),
				'at'     => basename($e->getFile()).':'.$e->getLine(),
			));
		}
	}

	private function _postCreate($token)
	{
		$this->_requireTokenScope($token, 'posts:write');
		$user  = $this->_requireBoundUser($token);
		$input = $this->_readInput();

		$title = isset($input['title']) ? trim((string) $input['title']) : '';
		if ($title === '') {
			$this->_emit(422, array('error' => 'title is required.'));
		}

		$authorIds = $this->_resolveAuthorIds($input);
		if (empty($authorIds)) {
			$this->_emit(422, array('error' => 'authors is required (array of character ids).'));
		}

		$missionId = isset($input['mission_id']) ? (int) $input['mission_id'] : 0;
		if ($missionId <= 0 || ! $this->_missionExists($missionId)) {
			$this->_emit(422, array('error' => 'mission_id is required and must reference an existing mission.'));
		}

		$tlErrors = \nova_ext_sim_central\PostWrite::timelineErrors($missionId, $input);
		if ( ! empty($tlErrors)) {
			$this->_emit(422, array('error' => $tlErrors[0], 'details' => $tlErrors));
		}

		// Authorization: at least one author must be one of the user's own
		// characters, unless this is a sysadmin write.all token.
		$canAll = $this->_canUseAll($token, $user, 'write');
		$userChars = \nova_ext_sim_central\PostWrite::userCharacterIds((int) $user->userid);
		if ( ! $canAll && empty(array_intersect($authorIds, $userChars))) {
			$this->_emit(403, array('error' => 'At least one author must be one of your own characters.'));
		}

		$authorsCsv = implode(',', $authorIds);
		$usersCsv   = \nova_ext_sim_central\PostWrite::authorsUsersCsv($authorIds);
		$reqStatus  = $this->_normalizeStatus($input);
		$status     = \nova_ext_sim_central\PostWrite::moderatedStatus($reqStatus, $authorsCsv);
		$actor      = $this->_resolveActorOrFallback($user, $authorIds);

		\nova_ext_sim_central\PostWrite::populateRequestInputs($input);

		$fields = array(
			'post_authors'       => $authorsCsv,
			'post_authors_users' => $usersCsv,
			'post_date'          => now(),
			'post_title'         => $title,
			'post_content'       => isset($input['body']) ? (string) $input['body'] : '',
			'post_tags'          => $this->_tagsCsv($input),
			'post_status'        => $status,
			'post_timeline'      => isset($input['timeline']) ? (string) $input['timeline'] : '',
			'post_location'      => isset($input['location']) ? (string) $input['location'] : '',
			'post_mission'       => $missionId,
			'post_saved'         => $actor,
			'post_participants'  => $usersCsv,
			'post_lock_user'     => 0,
			'post_lock_date'     => 0,
		);

		$this->posts->create_mission_entry($fields);

		// Resolve the new post's id. insert_id() is unreliable here: the Ordered
		// Mission Posts db.insert.prepare listener runs a nested mission lookup
		// *during* the insert, which clobbers the driver's last-insert-id. If
		// the id doesn't resolve, recover the row we just wrote (unique enough
		// by title + saving character + exact post_date timestamp).
		$newId = (int) $this->db->insert_id();
		$row   = ($newId > 0) ? $this->posts->get_post($newId) : false;
		if ( ! $row) {
			$recovered = $this->db
				->where('post_title', $title)
				->where('post_saved', $actor)
				->where('post_date', $fields['post_date'])
				->order_by('post_id', 'desc')
				->limit(1)
				->get('posts')->row();
			if ($recovered) {
				$newId = (int) $recovered->post_id;
				$row   = $this->posts->get_post($newId);
			}
		}
		if ( ! $row) {
			$this->_emit(500, array('error' => 'Post was created but could not be reloaded.'));
		}

		if ($status === 'activated') {
			\nova_ext_sim_central\PostWrite::afterActivate($newId, $authorIds, $actor);
		} elseif ($status === 'saved') {
			\nova_ext_sim_central\PostWrite::afterSave($newId, $actor);
		}

		$this->_emit(201, $this->_projectPost($row));
	}

	private function _postUpdate($token, $id)
	{
		$this->_requireTokenScope($token, 'posts:write');
		$user     = $this->_requireBoundUser($token);
		$existing = $this->posts->get_post($id);
		if ( ! $existing) {
			$this->_emit(404, array('error' => 'Post not found.'));
		}

		$canAll = $this->_canUseAll($token, $user, 'write');
		if ( ! $canAll && ! $this->_userOnPost($existing, (int) $user->userid)) {
			$this->_emit(403, array('error' => 'You can only edit posts you author.'));
		}

		// Respect post locking: another user's active lock blocks the update.
		$lockInfo = \nova_ext_sim_central\PostWrite::lockProjection($existing);
		if ($lockInfo['locked'] && (int) $existing->post_lock_user !== (int) $user->userid) {
			$l = $lockInfo['lock'];
			$this->_emit(423, array_merge(
				array('error' => 'Post is locked by '.$l['owner']
					.' ('.$l['age_minutes'].' min ago).'
					.' Use POST /posts/'.$id.'/lock to acquire the lock,'
					.' or wait '.$l['expires_in_minutes'].' min for it to expire.'),
				$lockInfo
			));
		}

		$input = $this->_readInput();

		// Author set: keep existing unless the request supplies a new one.
		if (array_key_exists('authors', $input)) {
			$authorIds = $this->_resolveAuthorIds($input);
			if (empty($authorIds)) {
				$this->_emit(422, array('error' => 'authors, if provided, must be a non-empty array of character ids.'));
			}
			$userChars = \nova_ext_sim_central\PostWrite::userCharacterIds((int) $user->userid);
			if ( ! $canAll && empty(array_intersect($authorIds, $userChars))) {
				$this->_emit(403, array('error' => 'At least one author must be one of your own characters.'));
			}
			$authorsCsv = implode(',', $authorIds);
			$usersCsv   = \nova_ext_sim_central\PostWrite::authorsUsersCsv($authorIds);
		} else {
			$authorsCsv = (string) $existing->post_authors;
			$authorIds  = array_values(array_filter(array_map('intval', explode(',', $authorsCsv))));
			$usersCsv   = (string) $existing->post_authors_users;
		}

		// Body: replace (default) or append.
		$content = (string) $existing->post_content;
		if (array_key_exists('body', $input)) {
			$mode = isset($input['body_mode']) ? strtolower((string) $input['body_mode']) : 'replace';
			$content = ($mode === 'append')
				? (string) $existing->post_content.(string) $input['body']
				: (string) $input['body'];
		}

		$reqStatus = array_key_exists('status', $input) ? $this->_normalizeStatus($input) : $existing->post_status;
		$status    = ($reqStatus === 'activated')
			? \nova_ext_sim_central\PostWrite::moderatedStatus('activated', $authorsCsv)
			: $reqStatus;

		$actor = $this->_resolveActorOrFallback($user, $authorIds, (int) $existing->post_saved);

		$fields = array(
			'post_content'       => $content,
			'post_authors'       => $authorsCsv,
			'post_authors_users' => $usersCsv,
			'post_status'        => $status,
			'post_saved'         => $actor,
			'post_last_update'   => now(),
		);
		if (array_key_exists('title', $input)) {
			$t = trim((string) $input['title']);
			if ($t === '') { $this->_emit(422, array('error' => 'title cannot be blank.')); }
			$fields['post_title'] = $t;
		}
		if (array_key_exists('mission_id', $input)) {
			$mid = (int) $input['mission_id'];
			if ($mid <= 0 || ! $this->_missionExists($mid)) {
				$this->_emit(422, array('error' => 'mission_id must reference an existing mission.'));
			}
			$fields['post_mission'] = $mid;
		}

		// Validate ordered-timeline fields against the post's effective mission
		// (the new one if changing missions, else the existing one).
		$effectiveMission = array_key_exists('mission_id', $input)
			? (int) $input['mission_id'] : (int) $existing->post_mission;
		$tlErrors = \nova_ext_sim_central\PostWrite::timelineErrors($effectiveMission, $input);
		if ( ! empty($tlErrors)) {
			$this->_emit(422, array('error' => $tlErrors[0], 'details' => $tlErrors));
		}

		if (array_key_exists('location', $input)) { $fields['post_location'] = (string) $input['location']; }
		if (array_key_exists('timeline', $input)) { $fields['post_timeline'] = (string) $input['timeline']; }
		if (array_key_exists('tags', $input))     { $fields['post_tags']     = $this->_tagsCsv($input); }

		// Publish date is set when a draft actually goes live.
		$wasActivated = ($existing->post_status === 'activated');
		if ($status === 'activated' && ! $wasActivated) {
			$fields['post_date'] = now();
		}

		\nova_ext_sim_central\PostWrite::populateRequestInputs($input);

		$this->posts->update_post($id, $fields);

		if ($status === 'activated' && ! $wasActivated) {
			\nova_ext_sim_central\PostWrite::afterActivate($id, $authorIds, $actor);
		} elseif ($status === 'saved') {
			\nova_ext_sim_central\PostWrite::afterSave($id, $actor);
		}

		// Auto-release the caller's lock now that the write is committed.
		if ((int) $existing->post_lock_user === (int) $user->userid) {
			\nova_ext_sim_central\PostWrite::releaseLock($id);
		}

		$row = $this->posts->get_post($id);
		$this->_emit(200, $this->_projectPost($row));
	}

	private function _postDelete($token, $id)
	{
		$this->_requireTokenScope($token, 'posts:delete');
		$user     = $this->_requireBoundUser($token);
		$existing = $this->posts->get_post($id);
		if ( ! $existing) {
			$this->_emit(404, array('error' => 'Post not found.'));
		}
		$canAll = $this->_canUseAll($token, $user, 'delete');
		if ( ! $canAll && ! $this->_userOnPost($existing, (int) $user->userid)) {
			$this->_emit(403, array('error' => 'You can only delete posts you author.'));
		}
		$this->posts->delete_post($id);
		$this->_emit(200, array('deleted' => true, 'id' => $id));
	}

	// ---------- post lock sub-resource ----------

	/**
	 * Handles GET|POST|PUT|DELETE /posts/{id}/lock.
	 *
	 * GET    — return current lock state.
	 * POST   — acquire (or re-acquire own / stale) lock. 409 if held by another.
	 * PUT    — heartbeat: renew expiry. 409 if you don't own the lock.
	 * DELETE — release. posts:write.all sysadmin tokens may force-release any lock.
	 *
	 * All verbs require posts:write scope + a bound user.
	 * PATCH /posts/{id} honours the lock (423 if another user holds it) and
	 * auto-releases your lock on a successful save.
	 */
	private function _postsLock($method)
	{
		$token = $this->_authenticate(null);
		$this->_requireTokenScope($token, 'posts:write');
		$user = $this->_requireBoundUser($token);
		$uid  = (int) $user->userid;

		$seg = $this->uri->segment(5);
		if ($seg === null || ! ctype_digit((string) $seg)) {
			$this->_emit(400, array('error' => 'Post id required in path before /lock.'));
		}
		$id = (int) $seg;

		$row = $this->posts->get_post($id);
		if ( ! $row) {
			$this->_emit(404, array('error' => 'Post not found.'));
		}

		$canAll = $this->_canUseAll($token, $user, 'write');
		if ( ! $canAll && ! $this->_userOnPost($row, $uid)) {
			$this->_emit(403, array('error' => 'You can only manage locks on posts you author.'));
		}

		$info = \nova_ext_sim_central\PostWrite::lockProjection($row);
		$stale = (int) ceil(\nova_ext_sim_central\PostWrite::LOCK_STALE_SECS / 60);

		switch ($method) {

			case 'GET':
				$info['yours'] = ($info['locked'] && (int) $row->post_lock_user === $uid);
				$this->_emit(200, $info);
				break;

			case 'POST':
				// Block only if another user holds a fresh lock.
				if ($info['locked'] && (int) $row->post_lock_user !== $uid) {
					$l = $info['lock'];
					$this->_emit(409, array_merge(
						array('error' => 'Post is locked by '.$l['owner']
							.' ('.$l['age_minutes'].' min ago).'
							.' Expires in '.$l['expires_in_minutes'].' min.'),
						$info
					));
				}
				\nova_ext_sim_central\PostWrite::acquireLock($id, $uid);
				$this->_emit(200, array(
					'locked'             => true,
					'acquired'           => true,
					'yours'              => true,
					'post_id'            => $id,
					'expires_in_minutes' => $stale,
				));
				break;

			case 'PUT':
				// Heartbeat: renew expiry. Must own the lock (active or stale-but-unclaimed).
				if ((int) $row->post_lock_user !== $uid) {
					$this->_emit(409, array_merge(
						array('error' => 'You do not hold the lock on this post. Acquire it first via POST /posts/'.$id.'/lock.'),
						$info
					));
				}
				\nova_ext_sim_central\PostWrite::acquireLock($id, $uid);
				$this->_emit(200, array(
					'locked'             => true,
					'renewed'            => true,
					'yours'              => true,
					'post_id'            => $id,
					'expires_in_minutes' => $stale,
				));
				break;

			case 'DELETE':
				$lockUser = (int) $row->post_lock_user;
				if ($lockUser !== 0 && $lockUser !== $uid && ! $canAll) {
					$this->_emit(403, array_merge(
						array('error' => 'You do not hold the lock on this post.'),
						$info
					));
				}
				\nova_ext_sim_central\PostWrite::releaseLock($id);
				$this->_emit(200, array(
					'locked'   => false,
					'released' => true,
					'post_id'  => $id,
				));
				break;

			default:
				$this->_emit(405, array(
					'error'   => 'Method not allowed on /posts/{id}/lock.',
					'allowed' => array('GET', 'POST', 'PUT', 'DELETE'),
				));
		}
	}

	// ---------- post write helpers ----------

	private function _requireTokenScope($token, $scope)
	{
		if ( ! $this->_tokenHasScope($token, $scope)) {
			$this->_emit(403, array('error' => 'Token lacks required scope: '.$scope));
		}
	}

	/**
	 * Normalise + validate the `authors` field (array of charids or CSV) into a
	 * list of existing character ids. Emits 422 on unknown ids.
	 */
	private function _resolveAuthorIds($input)
	{
		$raw = isset($input['authors']) ? $input['authors'] : null;
		if (is_string($raw)) {
			$raw = explode(',', $raw);
		}
		if ( ! is_array($raw)) {
			return array();
		}
		$ids = array();
		foreach ($raw as $v) {
			$n = (int) $v;
			if ($n > 0) { $ids[$n] = true; }
		}
		$ids = array_keys($ids);
		if (empty($ids)) {
			return array();
		}
		$found = $this->db->select('charid')->where_in('charid', $ids)->get('characters')->result();
		$foundIds = array();
		foreach ($found as $r) { $foundIds[] = (int) $r->charid; }
		$missing = array_diff($ids, $foundIds);
		if ( ! empty($missing)) {
			$this->_emit(422, array(
				'error'   => 'Unknown character id(s) in authors.',
				'missing' => array_values($missing),
			));
		}
		return $ids;
	}

	private function _missionExists($missionId)
	{
		return $this->db->where('mission_id', (int) $missionId)->count_all_results('missions') > 0;
	}

	/** Map request status synonyms to the stored enum ('saved' | 'activated'). */
	private function _normalizeStatus($input)
	{
		$s = isset($input['status']) ? strtolower(trim((string) $input['status'])) : 'saved';
		if (in_array($s, array('activated', 'activate', 'post', 'posted', 'publish', 'published'), true)) {
			return 'activated';
		}
		if (in_array($s, array('', 'saved', 'save', 'draft'), true)) {
			return 'saved';
		}
		$this->_emit(422, array('error' => 'status must be "saved" or "activated".'));
	}

	private function _tagsCsv($input)
	{
		$raw = isset($input['tags']) ? $input['tags'] : '';
		if (is_array($raw)) {
			$raw = implode(',', $raw);
		}
		$parts = array_filter(array_map('trim', explode(',', (string) $raw)), 'strlen');
		return implode(',', $parts);
	}

	/**
	 * Resolve the posting character, with a fallback chain for the write.all
	 * case where none of the post's authors belong to the bound user.
	 */
	private function _resolveActorOrFallback($user, array $authorIds, $existingActor = 0)
	{
		$actor = \nova_ext_sim_central\PostWrite::resolveActor((int) $user->userid, $authorIds);
		if ($actor > 0) {
			return $actor;
		}
		if ($existingActor > 0) {
			return $existingActor;
		}
		if ( ! empty($user->main_char)) {
			return (int) $user->main_char;
		}
		return ! empty($authorIds) ? (int) $authorIds[0] : 0;
	}

	/**
	 * GET /Api/characters          - list (filters: ?status=, ?page=, ?per_page=)
	 * GET /Api/characters/{id}     - single
	 *
	 * Default status filter is 'active'. ?status=any returns every character.
	 */
	public function characters()
	{
		$this->_gate();
		$this->_authenticate('characters:read');
		$this->load->model('characters_model', 'characters');

		$id = $this->uri->segment(5);
		if ($id !== null && $id !== '' && ctype_digit((string) $id)) {
			$row = $this->characters->get_character((int) $id);
			if ( ! $row) {
				$this->_emit(404, array('error' => 'Character not found.'));
			}
			$this->_emit(200, $this->_projectCharacter($row));
		}

		$status = $this->input->get('status', true);
		if ($status === null || $status === '') {
			$status = 'active';
		}
		list($page, $perPage, $offset) = $this->_paging();

		$this->db->from('characters');
		if ($status !== 'any') {
			$this->db->where('crew_type', $status);
		}
		$this->db->order_by('last_name', 'asc');
		$this->db->order_by('first_name', 'asc');
		$total = (int) $this->db->count_all_results('', false);
		$rows  = $this->db->limit($perPage, $offset)->get()->result();

		$data = array();
		foreach ($rows as $row) {
			$data[] = $this->_projectCharacter($row);
		}
		$this->_emit(200, $this->_paginate($data, $total, $page, $perPage));
	}

	/**
	 * GET /Api/missions          - list (filters: ?status=, ?page=, ?per_page=)
	 * GET /Api/missions/{id}     - single
	 *
	 * Default status filter is 'any' since most sims have a small mission
	 * list and consumers usually want all of them.
	 */
	public function missions()
	{
		$this->_gate();
		$this->_authenticate('missions:read');
		$this->load->model('missions_model', 'missions');

		$id = $this->uri->segment(5);
		if ($id !== null && $id !== '' && ctype_digit((string) $id)) {
			$row = $this->missions->get_mission((int) $id);
			if ( ! $row) {
				$this->_emit(404, array('error' => 'Mission not found.'));
			}
			$this->_emit(200, $this->_projectMission($row));
		}

		$status = $this->input->get('status', true);
		list($page, $perPage, $offset) = $this->_paging();

		$this->db->from('missions');
		if ( ! empty($status) && $status !== 'any') {
			$this->db->where('mission_status', $status);
		}
		$this->db->order_by('mission_start', 'desc');
		$total = (int) $this->db->count_all_results('', false);
		$rows  = $this->db->limit($perPage, $offset)->get()->result();

		$data = array();
		foreach ($rows as $row) {
			$data[] = $this->_projectMission($row);
		}
		$this->_emit(200, $this->_paginate($data, $total, $page, $perPage));
	}

	/**
	 * GET /Api/positions   - list open crew positions.
	 *
	 * "Open" means the position has one or more unfilled slots (pos_open > 0).
	 * Filters:
	 *   ?top=1        - only positions flagged "top open" in the roster admin
	 *                   (pos_top_open = y): the sim's headline open billets.
	 *   ?display=y|n  - include hidden positions (pos_display). Defaults to y.
	 *   ?page=, ?per_page=
	 *
	 * Reuses Nova's positions_model::get_open_positions() so the open / top-open
	 * semantics match what the site shows on the join page.
	 */
	public function positions()
	{
		$this->_gate();
		$this->_authenticate('positions:read');
		$this->load->model('positions_model', 'pos');
		$this->load->model('depts_model', 'dept');

		$topParam = $this->input->get('top', true);
		$top      = ($topParam === '1' || $topParam === 'true' || $topParam === 'yes');

		$display = $this->input->get('display', true);
		if ($display !== 'y' && $display !== 'n') { $display = 'y'; }

		$rows = $this->pos->get_open_positions($display, $top)->result();

		list($page, $perPage, $offset) = $this->_paging();
		$total = count($rows);
		$slice = array_slice($rows, $offset, $perPage);

		$deptNames = array();
		$data      = array();
		foreach ($slice as $row) {
			$data[] = $this->_projectPosition($row, $deptNames);
		}
		$this->_emit(200, $this->_paginate($data, $total, $page, $perPage));
	}

	/**
	 * GET /Api/snapshot
	 *
	 * The Astrolabe snapshot: one read-only aggregate of this sim's public data
	 * (game info, crew manifest, stories, recent posts, counts) for the
	 * Astrolabe platform to mirror on its per-game page. Scope: astrolabe:read.
	 * A token scoped to only this reads nothing else. Served from a short-TTL
	 * cache since Astrolabe polls on a schedule. See ASTROLABE.md.
	 */
	public function snapshot()
	{
		$this->_gate();
		$this->_requireMethod('GET');
		$this->_authenticate('astrolabe:read');

		// The library returns a JSON string (whitelisted shape, absolute https
		// URLs, HTML-stripped text). Emit it verbatim so we don't re-encode.
		$this->_emitRaw(200, \nova_ext_sim_central\AstrolabeSnapshot::cached());
	}

	/**
	 * GET /Api/me
	 *
	 * Identity of the bound user behind this token: the user, their characters
	 * (split PC vs NPC), and the token's scopes. Drives a writing app's
	 * "posting as..." screen. Requires a user-bound token (409 otherwise).
	 */
	public function me()
	{
		$this->_gate();
		$this->_requireMethod('GET');
		$token = $this->_authenticate(null);
		$user  = $this->_requireBoundUser($token);

		$mainChar = isset($user->main_char) ? (int) $user->main_char : 0;
		$rows = $this->db
			->select('characters.charid, characters.first_name, characters.last_name, characters.suffix, characters.crew_type, characters.rank, ranks.rank_name, ranks.rank_order'
				.(\nova_ext_sim_central\Migrations::hasColumn('characters', 'display_name') ? ', characters.display_name' : ''))
			->from('characters')
			->join('ranks', 'ranks.rank_id = characters.rank', 'left')
			->where('characters.user', (int) $user->userid)
			->order_by('ranks.rank_order', 'asc')
			->order_by('characters.charid', 'asc')
			->get()->result();

		$pcs = array();
		$npcs = array();
		foreach ($rows as $r) {
			$entry = array(
				'id'         => (int) $r->charid,
				'name'       => $this->_characterName($r),
				'rank'       => $r->rank_name ?: null,
				'rank_order' => isset($r->rank_order) ? (int) $r->rank_order : null,
				'crew_type'  => $r->crew_type,
				'is_main'    => ((int) $r->charid === $mainChar),
			);
			if ($r->crew_type === 'npc') { $npcs[] = $entry; } else { $pcs[] = $entry; }
		}

		$this->_emit(200, array(
			'user' => array(
				'id'          => (int) $user->userid,
				'name'        => $user->name,
				'is_sysadmin' => (isset($user->is_sysadmin) && $user->is_sysadmin === 'y'),
			),
			'characters' => array('pc' => $pcs, 'npc' => $npcs),
			'scopes'     => $this->_tokenScopes($token),
		));
	}

	/**
	 * POST /Api/users/disable      - put a user + their linked characters inactive
	 * POST /Api/users/reactivate   - put a user active (+ characters unless opted out)
	 *
	 * Identify the user by `user_id` (int) OR `discord_id` (the user's linked
	 * Discord account id). `user_id` always works; `discord_id` requires the
	 * Discord Auth feature to be enabled (it's the column that stores the link)
	 * and returns a clear 409 if that feature is off.
	 *
	 * disable     -> users.status = inactive; every currently-active linked
	 *                character -> crew_type = inactive.
	 * reactivate  -> users.status = active; every previously-inactive linked
	 *                character -> crew_type = active, UNLESS the request sets
	 *                reactivate_characters=false (then only the user is touched).
	 *
	 * Body accepted as JSON, form-encoded, or query string.
	 * Scope: users:write.
	 */
	public function users()
	{
		$this->_gate();
		$this->_requireMethod('POST');
		$this->_authenticate('users:write');

		$action = $this->uri->segment(5);
		if ($action !== 'disable' && $action !== 'reactivate') {
			$this->_emit(404, array('error' => 'Unknown user action. Use /users/disable or /users/reactivate.'));
		}

		$input = $this->_readInput();
		$user  = $this->_resolveUser($input);

		$now = time();

		if ($action === 'disable') {
			$charIds = $this->_linkedCharIds((int) $user->userid, 'active');
			if ( ! empty($charIds)) {
				$this->db->where('user', (int) $user->userid)
					->where('crew_type', 'active')
					->update('characters', array('crew_type' => 'inactive', 'date_deactivate' => $now));
			}
			$this->db->where('userid', (int) $user->userid)
				->update('users', array('status' => 'inactive', 'leave_date' => $now));

			$this->_emit(200, array(
				'user_id'    => (int) $user->userid,
				'discord_id' => isset($user->nova_ext_discord_auth_id) ? ($user->nova_ext_discord_auth_id ?: null) : null,
				'status'     => 'inactive',
				'characters' => array(
					'status'   => 'inactive',
					'affected' => count($charIds),
					'ids'      => $charIds,
				),
			));
		}

		// reactivate
		$reactivateChars = $this->_boolParam($input, 'reactivate_characters', true);
		$this->db->where('userid', (int) $user->userid)
			->update('users', array('status' => 'active', 'leave_date' => null));

		$charIds = array();
		if ($reactivateChars) {
			$charIds = $this->_linkedCharIds((int) $user->userid, 'inactive');
			if ( ! empty($charIds)) {
				$this->db->where('user', (int) $user->userid)
					->where('crew_type', 'inactive')
					->update('characters', array('crew_type' => 'active', 'date_activate' => $now));
			}
		}

		$this->_emit(200, array(
			'user_id'    => (int) $user->userid,
			'discord_id' => isset($user->nova_ext_discord_auth_id) ? ($user->nova_ext_discord_auth_id ?: null) : null,
			'status'     => 'active',
			'characters' => array(
				'status'   => $reactivateChars ? 'active' : 'unchanged',
				'affected' => count($charIds),
				'ids'      => $charIds,
			),
		));
	}

	/**
	 * Event Webhooks management.
	 *
	 *   GET    /Api/webhooks         - list webhooks                  (webhooks:read)
	 *   GET    /Api/webhooks/{id}    - single webhook                 (webhooks:read)
	 *   POST   /Api/webhooks         - create a webhook               (webhooks:write)
	 *   PATCH  /Api/webhooks/{id}    - update a webhook (partial)     (webhooks:write)
	 *   PUT    /Api/webhooks/{id}    - alias of PATCH                 (webhooks:write)
	 *   DELETE /Api/webhooks/{id}    - delete a webhook               (webhooks:write)
	 *
	 * All of these require the Event Webhooks feature to be enabled on the sim;
	 * if it isn't, every verb returns 409 with a clear message. Validation of
	 * create/update bodies is shared with the ACP via Webhooks::validateWebhookInput.
	 */
	public function webhooks()
	{
		$this->_gate();
		$this->_requireWebhooksFeature();

		$method = $this->_method();
		$id     = $this->uri->segment(5);
		$hasId  = ($id !== null && $id !== '' && ctype_digit((string) $id));
		$id     = $hasId ? (int) $id : 0;

		switch ($method) {
			case 'GET':
				$this->_authenticate('webhooks:read');
				if ($hasId) {
					$row = $this->db->get_where('sim_central_webhooks', array('id' => $id))->row();
					if ( ! $row) { $this->_emit(404, array('error' => 'Webhook not found.')); }
					$this->_emit(200, $this->_projectWebhook($row));
				}
				$rows = $this->db->order_by('enabled', 'DESC')->order_by('created_at', 'DESC')
					->get('sim_central_webhooks')->result();
				$data = array();
				foreach ($rows as $row) { $data[] = $this->_projectWebhook($row); }
				$this->_emit(200, array('data' => $data, 'total' => count($data)));
				break;

			case 'POST':
				$this->_authenticate('webhooks:write');
				if ($hasId) {
					$this->_emit(405, array('error' => 'Use PATCH /webhooks/{id} to update, POST /webhooks (no id) to create.'));
				}
				$input  = $this->_readInput();
				$result = \nova_ext_sim_central\Webhooks::validateWebhookInput($this->_webhookInputDefaults($input));
				if ( ! empty($result['errors'])) {
					$this->_emit(422, array('error' => 'Validation failed.', 'details' => $result['errors']));
				}
				$data = $result['data'];
				$data['created_at'] = date('Y-m-d H:i:s');
				$data['created_by'] = null; // API-created; no ACP session user
				$this->db->insert('sim_central_webhooks', $data);
				$newId = (int) $this->db->insert_id();
				$row = $this->db->get_where('sim_central_webhooks', array('id' => $newId))->row();
				$this->_emit(201, $this->_projectWebhook($row));
				break;

			case 'PUT':
			case 'PATCH':
				$this->_authenticate('webhooks:write');
				if ( ! $hasId) { $this->_emit(400, array('error' => 'Webhook id required in path.')); }
				$existing = $this->db->get_where('sim_central_webhooks', array('id' => $id))->row();
				if ( ! $existing) { $this->_emit(404, array('error' => 'Webhook not found.')); }
				$merged = $this->_mergeWebhookInput($existing, $this->_readInput());
				$result = \nova_ext_sim_central\Webhooks::validateWebhookInput($merged);
				if ( ! empty($result['errors'])) {
					$this->_emit(422, array('error' => 'Validation failed.', 'details' => $result['errors']));
				}
				$this->db->where('id', $id)->update('sim_central_webhooks', $result['data']);
				$row = $this->db->get_where('sim_central_webhooks', array('id' => $id))->row();
				$this->_emit(200, $this->_projectWebhook($row));
				break;

			case 'DELETE':
				$this->_authenticate('webhooks:write');
				if ( ! $hasId) { $this->_emit(400, array('error' => 'Webhook id required in path.')); }
				$existing = $this->db->get_where('sim_central_webhooks', array('id' => $id))->row();
				if ( ! $existing) { $this->_emit(404, array('error' => 'Webhook not found.')); }
				$this->db->where('id', $id)->delete('sim_central_webhooks');
				$this->_emit(200, array('deleted' => true, 'id' => $id));
				break;

			default:
				$this->_emit(405, array('error' => 'Method not allowed. Use GET, POST, PATCH, PUT, or DELETE.'));
		}
	}

	/**
	 * API token management (the same actions as the ACP token page).
	 *
	 *   GET    /Api/tokens        - list tokens (metadata only)        (tokens:read)
	 *   GET    /Api/tokens/{id}   - single token metadata             (tokens:read)
	 *   POST   /Api/tokens        - create a token (raw shown once)    (tokens:write)
	 *   PATCH  /Api/tokens/{id}   - revoke / un-revoke a token         (tokens:write)
	 *   DELETE /Api/tokens/{id}   - delete a token                     (tokens:write)
	 *
	 * Every verb requires the calling token to carry the tokens:* scope AND be
	 * bound to a sysadmin user - mirroring the ACP, where only sysadmins manage
	 * tokens. The raw token value is only ever returned once, at creation; the
	 * stored hash is never exposed.
	 */
	public function tokens()
	{
		$this->_gate();
		$token  = $this->_authenticate(null);
		$method = $this->_method();

		$id    = $this->uri->segment(5);
		$hasId = ($id !== null && $id !== '' && ctype_digit((string) $id));
		$id    = $hasId ? (int) $id : 0;

		switch ($method) {
			case 'GET':
				$this->_requireSysadminToken($token, 'tokens:read');
				if ($hasId) {
					$row = $this->db->get_where('sim_central_api_tokens', array('id' => $id))->row();
					if ( ! $row) { $this->_emit(404, array('error' => 'Token not found.')); }
					$this->_emit(200, $this->_projectToken($row));
				}
				$rows = $this->db->order_by('revoked_at IS NULL', 'DESC', false)
					->order_by('created_at', 'DESC')
					->get('sim_central_api_tokens')->result();
				$data = array();
				foreach ($rows as $r) { $data[] = $this->_projectToken($r); }
				$this->_emit(200, array('data' => $data, 'total' => count($data)));
				break;

			case 'POST':
				$user = $this->_requireSysadminToken($token, 'tokens:write');
				if ($hasId) {
					$this->_emit(405, array('error' => 'Use PATCH/DELETE /tokens/{id}; POST /tokens (no id) to create.'));
				}
				$input = $this->_readInput();

				// v1.32.0: bind by discord_id as an alternative to user_id, so
				// consumers that only know a member's Discord identity (e.g.
				// Astrolabe minting a writer token) can create user-bound
				// tokens. Mutually exclusive with user_id; resolving mirrors
				// POST /users/disable (409 when the feature is off/unlinked).
				// Omitting BOTH still creates an unbound token, as always.
				$userIdInput   = isset($input['user_id']) ? trim((string) $input['user_id']) : '';
				$discordIdBind = isset($input['discord_id']) ? trim((string) $input['discord_id']) : '';
				if ($discordIdBind !== '') {
					if ($userIdInput !== '') {
						$this->_emit(422, array('error' => 'Provide user_id OR discord_id, not both.'));
					}
					$features = $this->_suiteFeatures();
					if (empty($features['discord_auth'])) {
						$this->_emit(409, array(
							'error'   => 'Cannot bind by Discord ID: the Discord Auth feature is not enabled on this sim. Use user_id instead.',
							'feature' => 'discord_auth',
							'enabled' => false,
						));
					}
					if ( ! ctype_digit($discordIdBind)) {
						$this->_emit(400, array('error' => 'discord_id must be a numeric Discord account ID.'));
					}
					$linked = $this->db->get_where('users', array('nova_ext_discord_auth_id' => $discordIdBind))->row();
					if ( ! $linked) {
						$this->_emit(409, array('error' => 'No user is linked to that Discord ID.'));
					}
					$userIdInput = (string) (int) $linked->userid;
				}

				$result = \nova_ext_sim_central\ApiAuth::validateTokenInput(array(
					'label'      => isset($input['label']) ? $input['label'] : '',
					'scopes'     => isset($input['scopes']) ? $input['scopes'] : array(),
					'user_id'    => $userIdInput,
					'expires_at' => isset($input['expires_at']) ? $input['expires_at'] : '',
				));
				if ( ! empty($result['errors'])) {
					$this->_emit(422, array('error' => 'Validation failed.', 'details' => $result['errors']));
				}
				$d   = $result['data'];
				$gen = \nova_ext_sim_central\ApiAuth::generateToken();
				$this->db->insert('sim_central_api_tokens', array(
					'label'        => $d['label'],
					'token_hash'   => $gen['hash'],
					'token_prefix' => $gen['prefix'],
					'scopes'       => json_encode($d['scopes']),
					'user_id'      => $d['user_id'],
					'created_by'   => (int) $user->userid,
					'created_at'   => date('Y-m-d H:i:s'),
					'expires_at'   => $d['expires_at'],
				));
				// token_hash is unique, so it's a reliable fallback if insert_id()
				// is unavailable for any reason.
				$newId = (int) $this->db->insert_id();
				$row   = ($newId > 0) ? $this->db->get_where('sim_central_api_tokens', array('id' => $newId))->row() : false;
				if ( ! $row) {
					$row = $this->db->get_where('sim_central_api_tokens', array('token_hash' => $gen['hash']))->row();
				}
				$out = $this->_projectToken($row);
				$out['token'] = $gen['raw']; // shown exactly once
				$this->_emit(201, $out);
				break;

			case 'PUT':
			case 'PATCH':
				$this->_requireSysadminToken($token, 'tokens:write');
				if ( ! $hasId) { $this->_emit(400, array('error' => 'Token id required in path.')); }
				$existing = $this->db->get_where('sim_central_api_tokens', array('id' => $id))->row();
				if ( ! $existing) { $this->_emit(404, array('error' => 'Token not found.')); }
				$input = $this->_readInput();

				// Build the update from the fields present. Scopes editing is
				// additive (v1.28.0); revocation keeps its original contract.
				$scopesProvided = array_key_exists('scopes', $input);
				$update   = array();
				$doRevoke = false;

				if ($scopesProvided) {
					if (\nova_ext_sim_central\SimCentralAccess::isSimCentralToken($id)) {
						$this->_emit(409, array('error' => 'The Sim Central access token is managed automatically; its scopes cannot be edited here.'));
					}
					$scopeResult = \nova_ext_sim_central\ApiAuth::validateScopeSet($input['scopes'], (int) $existing->user_id);
					if ( ! empty($scopeResult['errors'])) {
						$this->_emit(422, array('error' => implode(' ', $scopeResult['errors']), 'errors' => $scopeResult['errors']));
					}
					$update['scopes'] = json_encode($scopeResult['scopes']);
				}

				// Revocation: original behaviour is "PATCH revokes unless
				// revoked=false". Preserve that when no scopes are supplied; when
				// editing scopes only, leave revocation untouched unless the
				// caller passes an explicit `revoked`.
				if (array_key_exists('revoked', $input) || ! $scopesProvided) {
					$doRevoke = $this->_boolParam($input, 'revoked', true);
					$update['revoked_at'] = $doRevoke ? date('Y-m-d H:i:s') : null;
				}

				$this->db->where('id', $id)->update('sim_central_api_tokens', $update);
				// If this is the Sim Central access token, tell the broker it's
				// been revoked so Sim Central stops using a dead credential.
				if ($doRevoke) {
					\nova_ext_sim_central\SimCentralAccess::onTokenRevoked($id);
				}
				$row = $this->db->get_where('sim_central_api_tokens', array('id' => $id))->row();
				$this->_emit(200, $this->_projectToken($row));
				break;

			case 'DELETE':
				$this->_requireSysadminToken($token, 'tokens:write');
				if ( ! $hasId) { $this->_emit(400, array('error' => 'Token id required in path.')); }
				$existing = $this->db->get_where('sim_central_api_tokens', array('id' => $id))->row();
				if ( ! $existing) { $this->_emit(404, array('error' => 'Token not found.')); }
				// Notify the broker before the row vanishes if this is the
				// Sim Central access token.
				\nova_ext_sim_central\SimCentralAccess::onTokenDeleted($id);
				$this->db->where('id', $id)->delete('sim_central_api_tokens');
				$this->_emit(200, array('deleted' => true, 'id' => $id));
				break;

			default:
				$this->_emit(405, array('error' => 'Method not allowed. Use GET, POST, PATCH, PUT, or DELETE.'));
		}
	}

	/**
	 * Suite self-management.
	 *
	 *   GET  /Api/suite   - installed version, latest available, whether an
	 *                       update is pending. Any valid token.
	 *   POST /Api/suite   - run the one-click updater to a target version
	 *                       (body `version`, default = latest release).
	 *                       Requires a sysadmin-bound token with suite:update.
	 *
	 * Routing is by HTTP method only - it does NOT depend on a path segment, so
	 * it behaves the same regardless of how a host rewrites URLs. The legacy
	 * POST /Api/suite/update path still works (any POST to /suite triggers the
	 * updater), but POST /Api/suite is preferred: the literal word "update" in a
	 * URL trips some hosts' mod_security SQL-injection rules and gets the request
	 * blocked at the web server before it ever reaches the app.
	 *
	 * Lets Sim Central see what each sim is running and push an upgrade without
	 * an admin logging into the ACP. The update swaps the extension on disk, so
	 * the response is the LAST thing the old code does - the caller should re-
	 * read GET /suite afterwards to confirm the new version.
	 */
	public function suite()
	{
		$this->_gate();

		$method = $this->_method();

		// GET /suite - status (any valid token).
		if ($method === 'GET') {
			$this->_authenticate(null);
			$update  = \nova_ext_sim_central\UpdateCheck::latest();
			$current = \nova_ext_sim_central\Config::version();
			$latest  = isset($update['latest_version']) ? $update['latest_version'] : null;
			$this->_emit(200, array(
				'version'          => $current,
				'latest_version'   => $latest,
				'update_available' => \nova_ext_sim_central\UpdateCheck::isNewer($latest, $current),
				'checked_at'       => ( ! empty($update['checked_at'])) ? date('c', (int) $update['checked_at']) : null,
				'release_url'      => isset($update['release_url']) ? $update['release_url'] : null,
			));
		}

		// POST /suite (or the legacy /suite/update) - run the updater
		// (sysadmin-bound suite:update token).
		if ($method === 'POST') {
			$token = $this->_authenticate(null);
			$this->_requireSysadminToken($token, 'suite:update');

			$input   = $this->_readInput();
			$version = isset($input['version']) ? trim((string) $input['version']) : '';
			if ($version === '') {
				// Default to the newest published release; force a fresh check so
				// we don't act on a stale 24h cache.
				$update  = \nova_ext_sim_central\UpdateCheck::latest(true);
				$version = isset($update['latest_version']) ? (string) $update['latest_version'] : '';
			}
			if ($version === '') {
				$this->_emit(422, array('error' => 'No target version given and the latest release could not be determined.'));
			}

			$current = \nova_ext_sim_central\Config::version();
			if (ltrim($version, 'vV') === ltrim($current, 'vV') && ! $this->_boolParam($input, 'force', false)) {
				$this->_emit(200, array(
					'status'  => 'noop',
					'message' => 'Already on '.$current.'. Pass "force": true to reinstall.',
					'version' => $current,
				));
			}

			$result  = \nova_ext_sim_central\Updater::update($version);
			$status  = $result[0];
			$message = $result[1];
			$context = isset($result[2]) ? $result[2] : array();

			if ($status === 'success') {
				$this->_emit(200, array(
					'status'  => 'success',
					'message' => $message,
					'version' => isset($context['version']) ? $context['version'] : $version,
					'backup'  => isset($context['backup']) ? $context['backup'] : null,
				));
			}
			$this->_emit(500, array('status' => 'error', 'error' => $message));
		}

		$this->_emit(405, array(
			'error'   => 'Use GET /suite for status or POST /suite to upgrade.',
			'allowed' => array('GET /suite', 'POST /suite'),
		));
	}

	// ---------- internals ----------

	/**
	 * Hard 404 if the rest_api feature toggle is off. Called as the first
	 * line of every endpoint method - NOT from the constructor, because
	 * Nova's `extensions` hook loads the suite's namespace on
	 * post_controller_constructor (which fires AFTER __construct), so
	 * \nova_ext_sim_central\Config isn't available in the constructor.
	 *
	 * By the time any action method runs, the hook has fired and the suite
	 * namespace is fully loaded.
	 */
	private function _gate()
	{
		$features = \nova_ext_sim_central\Config::features();
		if (empty($features['rest_api'])) {
			$this->_emit(404, array('error' => 'Not found.'));
		}
	}

	/**
	 * Webhook endpoints require the Event Webhooks feature to be enabled AND
	 * its table to exist. 409 (feature off) and 503 (enabled but un-migrated)
	 * are surfaced distinctly so the consumer knows whether to ask the admin
	 * to flip the toggle or to run "Setup database".
	 */
	private function _requireWebhooksFeature()
	{
		$features = $this->_suiteFeatures();
		if (empty($features['webhooks'])) {
			$this->_emit(409, array(
				'error'   => 'The Event Webhooks feature is not enabled on this sim.',
				'feature' => 'webhooks',
				'enabled' => false,
			));
		}
		$prefix = $this->db->dbprefix;
		if ( ! $this->db->table_exists($prefix.'sim_central_webhooks')
			&& ! $this->db->table_exists('sim_central_webhooks')) {
			$this->_emit(503, array(
				'error' => 'Event Webhooks is enabled but its table is missing. Ask the sim admin to run "Setup database" on the Event Webhooks feature.',
			));
		}
	}

	/** Decoded scope list for an authenticated token row. */
	private function _tokenScopes($token)
	{
		$scopes = isset($token->scopes) ? json_decode((string) $token->scopes, true) : null;
		return is_array($scopes) ? $scopes : array();
	}

	private function _tokenHasScope($token, $scope)
	{
		return in_array($scope, $this->_tokenScopes($token), true);
	}

	/**
	 * Resolve the Nova user a token is bound to. Write/own/delete endpoints
	 * act AS this user. 409 when the token has no binding or the user is gone.
	 */
	private function _requireBoundUser($token)
	{
		$uid = isset($token->user_id) ? (int) $token->user_id : 0;
		if ($uid <= 0) {
			$this->_emit(409, array(
				'error' => 'This token is not bound to a user. Assign a user to the token (ACP -> REST API -> Configure) to use endpoints that act as a user.',
			));
		}
		$user = $this->db->get_where('users', array('userid' => $uid))->row();
		if ( ! $user) {
			$this->_emit(409, array('error' => 'The user this token is bound to no longer exists.'));
		}
		return $user;
	}

	/**
	 * Whether the token may use a `.all` sysadmin bypass for the given family
	 * ('read'|'write'|'delete'): needs BOTH the .all scope on the token AND the
	 * bound user flagged sysadmin. The scope is the security boundary; the
	 * sysadmin flag is the permission.
	 */
	private function _canUseAll($token, $user, $family)
	{
		return $this->_tokenHasScope($token, 'posts:'.$family.'.all')
			&& isset($user->is_sysadmin) && $user->is_sysadmin === 'y';
	}

	/**
	 * SQL fragment matching a user id inside the post_authors_users CSV column,
	 * covering single / first / middle / last positions. $userId is cast to int
	 * so it's safe to interpolate.
	 */
	private function _authorsUsersClause($userId)
	{
		$u = (int) $userId;
		return "(post_authors_users = '{$u}'"
			. " OR post_authors_users LIKE '{$u},%'"
			. " OR post_authors_users LIKE '%,{$u}'"
			. " OR post_authors_users LIKE '%,{$u},%')";
	}

	/** True if the given user authors the post (matches post_authors_users CSV). */
	private function _userOnPost($post, $userId)
	{
		$csv = isset($post->post_authors_users) ? (string) $post->post_authors_users : '';
		if ($csv === '') {
			return false;
		}
		$ids = array_map('intval', array_filter(explode(',', $csv), 'strlen'));
		return in_array((int) $userId, $ids, true);
	}

	/**
	 * Gate for token-management endpoints: the calling token must carry the
	 * given scope AND be bound to a sysadmin user (mirrors the ACP, where only
	 * sysadmins manage tokens). Returns the bound sysadmin user row.
	 */
	private function _requireSysadminToken($token, $scope)
	{
		$this->_requireTokenScope($token, $scope);
		$user = $this->_requireBoundUser($token);
		if ( ! isset($user->is_sysadmin) || $user->is_sysadmin !== 'y') {
			$this->_emit(403, array('error' => 'Token management requires a token bound to a sysadmin user.'));
		}
		return $user;
	}

	/** Whitelist projector for an API token row. Never exposes token_hash or the raw token. */
	private function _projectToken($row)
	{
		$scopes = isset($row->scopes) ? json_decode((string) $row->scopes, true) : null;
		if ( ! is_array($scopes)) { $scopes = array(); }
		return array(
			'id'           => (int) $row->id,
			'label'        => $row->label,
			'token_prefix' => $row->token_prefix,
			'scopes'       => array_values($scopes),
			'user_id'      => (isset($row->user_id) && $row->user_id !== null) ? (int) $row->user_id : null,
			'created_by'   => (isset($row->created_by) && $row->created_by !== null) ? (int) $row->created_by : null,
			'created_at'   => isset($row->created_at) ? $row->created_at : null,
			'last_used_at' => isset($row->last_used_at) ? $row->last_used_at : null,
			'expires_at'   => isset($row->expires_at) ? $row->expires_at : null,
			'revoked_at'   => isset($row->revoked_at) ? $row->revoked_at : null,
			'revoked'      => ! empty($row->revoked_at),
		);
	}

	/** Compose a character's display name (display_name override, else first/last/suffix). */
	private function _characterName($r)
	{
		if ( ! empty($r->display_name)) {
			return $r->display_name;
		}
		return trim(implode(' ', array_filter(array(
			isset($r->first_name) ? $r->first_name : '',
			isset($r->last_name)  ? $r->last_name  : '',
			isset($r->suffix)     ? $r->suffix     : '',
		))));
	}

	/** Current request method, uppercased. */
	private function _method()
	{
		$m = $this->input->server('REQUEST_METHOD');
		return strtoupper((string) ($m ?: 'GET'));
	}

	/** Hard 405 unless the request uses the expected HTTP method. */
	private function _requireMethod($expected)
	{
		if ($this->_method() !== strtoupper($expected)) {
			$this->_emit(405, array('error' => 'Method not allowed. Use '.strtoupper($expected).'.'));
		}
	}

	/**
	 * Merge request inputs for write endpoints into one associative array.
	 * Accepts a JSON body (Content-Type: application/json), form-encoded POST,
	 * and query-string params - later sources do NOT override earlier ones, so
	 * precedence is: JSON body > POST > GET.
	 */
	private function _readInput()
	{
		$out = array();

		$get = $this->input->get();
		if (is_array($get)) { $out = array_merge($out, $get); }

		$post = $this->input->post();
		if (is_array($post)) { $out = array_merge($out, $post); }

		$raw = $this->input->raw_input_stream;
		if (is_string($raw) && $raw !== '') {
			$json = json_decode($raw, true);
			if (is_array($json)) { $out = array_merge($out, $json); }
		}

		return $out;
	}

	private function _param(array $input, $key, $default = null)
	{
		return array_key_exists($key, $input) ? $input[$key] : $default;
	}

	/**
	 * Interpret a request param as a boolean. Treats "false", "0", "no", "off",
	 * 0, and false as false; everything else (including absence) falls back to
	 * $default.
	 */
	private function _boolParam(array $input, $key, $default)
	{
		if ( ! array_key_exists($key, $input)) {
			return $default;
		}
		$v = $input[$key];
		if (is_bool($v)) { return $v; }
		if (is_int($v))  { return $v !== 0; }
		$s = strtolower(trim((string) $v));
		return ! in_array($s, array('false', '0', 'no', 'off', ''), true);
	}

	/**
	 * Resolve the target user for a status change from the request input.
	 * Prefers `user_id` (always available); falls back to `discord_id`, which
	 * only works when the Discord Auth feature is enabled (it owns the column
	 * the link lives in). Emits and halts on any failure.
	 */
	private function _resolveUser(array $input)
	{
		$userId    = $this->_param($input, 'user_id');
		$discordId = $this->_param($input, 'discord_id');

		if ($userId !== null && $userId !== '' && ctype_digit((string) $userId)) {
			$row = $this->db->get_where('users', array('userid' => (int) $userId))->row();
			if ( ! $row) { $this->_emit(404, array('error' => 'User not found.')); }
			return $row;
		}

		if ($discordId !== null && $discordId !== '') {
			$features = $this->_suiteFeatures();
			if (empty($features['discord_auth'])) {
				$this->_emit(409, array(
					'error'   => 'Cannot look up a user by Discord ID: the Discord Auth feature is not enabled on this sim. Use user_id instead.',
					'feature' => 'discord_auth',
					'enabled' => false,
				));
			}
			if ( ! ctype_digit((string) $discordId)) {
				$this->_emit(400, array('error' => 'discord_id must be a numeric Discord account ID.'));
			}
			$row = $this->db->get_where('users', array('nova_ext_discord_auth_id' => (string) $discordId))->row();
			if ( ! $row) { $this->_emit(404, array('error' => 'No user is linked to that Discord ID.')); }
			return $row;
		}

		$this->_emit(400, array('error' => 'Provide either user_id or discord_id.'));
	}

	/** charids linked to a user that currently hold the given crew_type. */
	private function _linkedCharIds($userId, $crewType)
	{
		$rows = $this->db->select('charid')
			->where('user', (int) $userId)
			->where('crew_type', $crewType)
			->get('characters')->result();
		$ids = array();
		foreach ($rows as $r) { $ids[] = (int) $r->charid; }
		return $ids;
	}

	/**
	 * Whitelist projector for a webhook row. The destination URL is included
	 * because managing a webhook requires seeing it, and these endpoints are
	 * already gated behind the privileged webhooks:read / webhooks:write scope.
	 */
	private function _projectWebhook($row)
	{
		$events = json_decode((string) $row->events, true);
		if ( ! is_array($events)) { $events = array(); }
		$roleEvents = isset($row->mention_role_events) ? json_decode((string) $row->mention_role_events, true) : null;
		if ( ! is_array($roleEvents)) { $roleEvents = array(); }

		return array(
			'id'                        => (int) $row->id,
			'label'                     => $row->label,
			'url'                       => $row->url,
			'format'                    => $row->format,
			'events'                    => array_values($events),
			'enabled'                   => (bool) (int) $row->enabled,
			'news_types'                => isset($row->news_types) ? $row->news_types : 'public',
			'mention_role_id'           => isset($row->mention_role_id) ? ($row->mention_role_id ?: null) : null,
			'mention_role_events'       => array_values($roleEvents),
			'template_title'            => isset($row->template_title) ? $row->template_title : null,
			'template_description'      => isset($row->template_description) ? $row->template_description : null,
			'template_log_title'        => isset($row->template_log_title) ? $row->template_log_title : null,
			'template_log_description'  => isset($row->template_log_description) ? $row->template_log_description : null,
			'template_news_title'       => isset($row->template_news_title) ? $row->template_news_title : null,
			'template_news_description' => isset($row->template_news_description) ? $row->template_news_description : null,
			'created_at'                => isset($row->created_at) ? $row->created_at : null,
			'last_fired_at'             => isset($row->last_fired_at) ? $row->last_fired_at : null,
			'last_status'               => isset($row->last_status) ? ($row->last_status !== null ? (int) $row->last_status : null) : null,
			'last_error'                => isset($row->last_error) ? $row->last_error : null,
		);
	}

	/**
	 * Normalise a create body into the flat shape validateWebhookInput expects,
	 * applying field defaults so a minimal create (label/url/format/events) works.
	 */
	private function _webhookInputDefaults(array $input)
	{
		return array(
			'label'                     => $this->_param($input, 'label', ''),
			'url'                       => $this->_param($input, 'url', ''),
			'format'                    => $this->_param($input, 'format', ''),
			'events'                    => $this->_param($input, 'events', array()),
			'enabled'                   => $this->_boolParam($input, 'enabled', true) ? 1 : 0,
			'news_types'                => $this->_param($input, 'news_types', 'public'),
			'mention_role_id'           => $this->_param($input, 'mention_role_id', ''),
			'mention_role_events'       => $this->_param($input, 'mention_role_events', array()),
			'template_title'            => $this->_param($input, 'template_title', ''),
			'template_description'      => $this->_param($input, 'template_description', ''),
			'template_log_title'        => $this->_param($input, 'template_log_title', ''),
			'template_log_description'  => $this->_param($input, 'template_log_description', ''),
			'template_news_title'       => $this->_param($input, 'template_news_title', ''),
			'template_news_description' => $this->_param($input, 'template_news_description', ''),
		);
	}

	/**
	 * Build a full validateWebhookInput payload for a PATCH/PUT: start from the
	 * existing row, overlay only the fields the request actually supplied. This
	 * makes updates partial (omit a field -> keep its stored value).
	 */
	private function _mergeWebhookInput($existing, array $input)
	{
		$events = json_decode((string) $existing->events, true);
		if ( ! is_array($events)) { $events = array(); }
		$roleEvents = isset($existing->mention_role_events) ? json_decode((string) $existing->mention_role_events, true) : null;
		if ( ! is_array($roleEvents)) { $roleEvents = array(); }

		$base = array(
			'label'                     => $existing->label,
			'url'                       => $existing->url,
			'format'                    => $existing->format,
			'events'                    => $events,
			'enabled'                   => (int) $existing->enabled,
			'news_types'                => isset($existing->news_types) ? $existing->news_types : 'public',
			'mention_role_id'           => isset($existing->mention_role_id) ? (string) $existing->mention_role_id : '',
			'mention_role_events'       => $roleEvents,
			'template_title'            => isset($existing->template_title) ? (string) $existing->template_title : '',
			'template_description'      => isset($existing->template_description) ? (string) $existing->template_description : '',
			'template_log_title'        => isset($existing->template_log_title) ? (string) $existing->template_log_title : '',
			'template_log_description'  => isset($existing->template_log_description) ? (string) $existing->template_log_description : '',
			'template_news_title'       => isset($existing->template_news_title) ? (string) $existing->template_news_title : '',
			'template_news_description' => isset($existing->template_news_description) ? (string) $existing->template_news_description : '',
		);

		foreach ($base as $key => $val) {
			if (array_key_exists($key, $input)) {
				if ($key === 'enabled') {
					$base[$key] = $this->_boolParam($input, 'enabled', (bool) $val) ? 1 : 0;
				} else {
					$base[$key] = $input[$key];
				}
			}
		}
		return $base;
	}

	/**
	 * Whitelist projector for a post row. Keep this list explicit - if Nova
	 * adds columns, they shouldn't leak through the API until we opt them
	 * in deliberately. Suite-feature fields are appended conditionally on
	 * the relevant feature being enabled, so consumers can detect what's
	 * available by key presence rather than null sentinels.
	 */
	private function _projectPost($row)
	{
		$out = array(
			'id'         => (int) $row->post_id,
			'title'      => $row->post_title,
			'content'    => $row->post_content,
			'mission_id' => isset($row->post_mission) ? (int) $row->post_mission : null,
			'authors'    => isset($row->post_authors) ? $row->post_authors : null,
			'status'     => isset($row->post_status) ? $row->post_status : null,
			'date'       => isset($row->post_date) ? date('c', (int) $row->post_date) : null,
			// Words in this post's body (HTML stripped). Same definition as
			// the mission-page counts. Not attributed to any one author -
			// a post can have several.
			'word_count' => isset($row->post_content)
				? \nova_ext_sim_central\PostWordCount::countText($row->post_content)
				: 0,
		);

		$features = $this->_suiteFeatures();

		// Mission Post Summary - the short TL;DR field surfaced in the feed.
		if ( ! empty($features['summary']) && property_exists($row, 'nova_ext_mission_post_summary')) {
			$out['summary'] = $row->nova_ext_mission_post_summary;
		}

		// Ordered Mission Posts - day/time/date/stardate on the post.
		if ( ! empty($features['ordered_mission_posts'])) {
			$ordered = array();
			if (property_exists($row, 'nova_ext_ordered_post_day')) {
				$ordered['day'] = (int) $row->nova_ext_ordered_post_day;
			}
			if (property_exists($row, 'nova_ext_ordered_post_time')) {
				$ordered['time'] = $row->nova_ext_ordered_post_time;
			}
			if (property_exists($row, 'nova_ext_ordered_post_date')) {
				$ordered['date'] = $row->nova_ext_ordered_post_date;
			}
			if (property_exists($row, 'nova_ext_ordered_post_stardate')) {
				$ordered['stardate'] = $row->nova_ext_ordered_post_stardate;
			}
			if ( ! empty($ordered)) {
				$out['ordered'] = $ordered;
			}
		}

		// Content Filter - per-post age gate flag. The API still returns
		// full content; consumers decide whether to redact in their own UI.
		if ( ! empty($features['content_filter']) && property_exists($row, 'nova_ext_content_filter_age_gated')) {
			$out['age_gated'] = (bool) (int) $row->nova_ext_content_filter_age_gated;
		}

		return $out;
	}

	private function _projectCharacter($row)
	{
		$userId = (isset($row->user) && (int) $row->user > 0) ? (int) $row->user : null;

		$out = array(
			'id'         => (int) $row->charid,
			'first_name' => isset($row->first_name) ? $row->first_name : null,
			'last_name'  => isset($row->last_name) ? $row->last_name : null,
			'suffix'     => isset($row->suffix) ? $row->suffix : null,
			'status'     => isset($row->crew_type) ? $row->crew_type : null,
			'rank'       => isset($row->rank) ? (int) $row->rank : null,
			'user_id'    => $userId,
			// v1.34.0: the linked user's PUBLIC display name (same value and
			// visibility rule as the snapshot manifest's player.name - never
			// email or account internals). Null when unowned/unlinked.
			'user_name'  => ($userId !== null) ? $this->_publicUserName($userId) : null,
		);

		$features = $this->_suiteFeatures();

		// Display Name - admin-set override that replaces first/last/suffix on
		// the manifest. Returned as a raw column value plus a precomputed
		// "preferred name" so consumers don't have to reimplement the rule.
		if ( ! empty($features['display_name']) && property_exists($row, 'display_name')) {
			$out['display_name']   = $row->display_name ?: null;
			$out['preferred_name'] = ! empty($row->display_name)
				? $row->display_name
				: trim(implode(' ', array_filter(array(
					isset($row->first_name) ? $row->first_name : '',
					isset($row->last_name)  ? $row->last_name  : '',
					isset($row->suffix)     ? $row->suffix     : '',
				))));
		}

		return $out;
	}

	private function _projectMission($row)
	{
		$out = array(
			'id'          => (int) $row->mission_id,
			'title'       => isset($row->mission_title) ? $row->mission_title : null,
			'description' => isset($row->mission_desc) ? $row->mission_desc : null,
			'status'      => isset($row->mission_status) ? $row->mission_status : null,
			'start'       => ( ! empty($row->mission_start)) ? date('c', (int) $row->mission_start) : null,
			'end'         => ( ! empty($row->mission_end)) ? date('c', (int) $row->mission_end) : null,
		);

		// Total words across this mission's activated posts. Only exposed to
		// tokens that can read all posts (posts:read / posts:read.all): a
		// mission total aggregates every author's posts, so an own-only or
		// missions-only token must not see it.
		if ($this->_tokenCanReadAllPosts()) {
			$out['word_count'] = \nova_ext_sim_central\PostWordCount::forMission((int) $row->mission_id);
		}

		$features = $this->_suiteFeatures();

		// Mission Post Summary - per-mission toggle (whether writers see the
		// summary field on this mission's posts).
		if ( ! empty($features['summary']) && property_exists($row, 'mission_ext_mission_post_summary_enable')) {
			$out['summary_enabled'] = (bool) (int) $row->mission_ext_mission_post_summary_enable;
		}

		// Ordered Mission Posts - per-mission config (ordering scheme, defaults,
		// numbering, legacy mode). Returned grouped for clarity.
		if ( ! empty($features['ordered_mission_posts'])) {
			$ordered = array();
			if (property_exists($row, 'mission_ext_ordered_config_setting')) {
				$ordered['config'] = $row->mission_ext_ordered_config_setting;
			}
			if (property_exists($row, 'mission_ext_ordered_post_numbering')) {
				$ordered['numbering'] = (bool) (int) $row->mission_ext_ordered_post_numbering;
			}
			if (property_exists($row, 'mission_ext_ordered_default_mission_date')) {
				$ordered['default_date'] = $row->mission_ext_ordered_default_mission_date;
			}
			if (property_exists($row, 'mission_ext_ordered_default_stardate')) {
				$ordered['default_stardate'] = $row->mission_ext_ordered_default_stardate;
			}
			if (property_exists($row, 'mission_ext_ordered_legacy_mode')) {
				$ordered['legacy_mode'] = (bool) (int) $row->mission_ext_ordered_legacy_mode;
			}
			if ( ! empty($ordered)) {
				$out['ordered'] = $ordered;
			}
		}

		return $out;
	}

	/**
	 * Shape a positions row for the API. $deptNames is a caller-owned cache so a
	 * list doesn't re-query the departments table once per row.
	 */
	private function _projectPosition($row, array &$deptNames = array())
	{
		$deptId = isset($row->pos_dept) ? (int) $row->pos_dept : 0;
		if ($deptId > 0 && ! array_key_exists($deptId, $deptNames)) {
			$name = $this->dept->get_dept($deptId, 'dept_name');
			$deptNames[$deptId] = ($name !== false) ? (string) $name : '';
		}

		return array(
			'id'            => (int) $row->pos_id,
			'name'          => isset($row->pos_name) ? $row->pos_name : null,
			'description'   => isset($row->pos_desc) ? $row->pos_desc : null,
			'department_id' => ($deptId > 0) ? $deptId : null,
			'department'    => ($deptId > 0 && ! empty($deptNames[$deptId])) ? $deptNames[$deptId] : null,
			'open'          => isset($row->pos_open) ? (int) $row->pos_open : 0,
			'type'          => isset($row->pos_type) ? $row->pos_type : null,
			'order'         => isset($row->pos_order) ? (int) $row->pos_order : null,
			'top_open'      => (isset($row->pos_top_open) && $row->pos_top_open === 'y'),
		);
	}

	/**
	 * Per-request cache of the suite's feature toggle map. Read once,
	 * referenced by every projector that needs to decide whether to surface
	 * a feature-added column.
	 */
	private $_authToken = null;

	/**
	 * Per-request memoized lookup of a user's public display name
	 * (users.name), entity-decoded to match the snapshot's player.name.
	 * PK lookups, so even a 100-row character page stays cheap.
	 */
	private $_userNameCache = array();
	private function _publicUserName($userId)
	{
		$userId = (int) $userId;
		if ($userId <= 0) {
			return null;
		}
		if ( ! array_key_exists($userId, $this->_userNameCache)) {
			$row = $this->db->select('name')->get_where('users', array('userid' => $userId), 1)->row();
			$this->_userNameCache[$userId] = ($row && $row->name !== '' && $row->name !== null)
				? html_entity_decode((string) $row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8')
				: null;
		}
		return $this->_userNameCache[$userId];
	}

	private $_featuresCache = null;
	private function _suiteFeatures()
	{
		if ($this->_featuresCache === null) {
			$this->_featuresCache = \nova_ext_sim_central\Config::features();
		}
		return $this->_featuresCache;
	}

	/**
	 * Post visibility rule for the single-fetch path: anything not
	 * 'activated' is treated as not-found via the API unless ?status=any is
	 * eventually added to the single endpoint. For now, single = public.
	 */
	private function _postIsVisible($row)
	{
		return isset($row->post_status) && $row->post_status === 'activated';
	}

	/**
	 * Normalises ?page= and ?per_page= into [page, per_page, offset]. Caps
	 * per_page at 100 to keep memory + payload bounded.
	 */
	private function _paging()
	{
		$page    = (int) $this->input->get('page');
		$perPage = (int) $this->input->get('per_page');
		if ($page < 1)     { $page    = 1; }
		if ($perPage < 1)  { $perPage = 25; }
		if ($perPage > 100){ $perPage = 100; }
		return array($page, $perPage, ($page - 1) * $perPage);
	}

	private function _paginate(array $data, $total, $page, $perPage)
	{
		return array(
			'data'     => $data,
			'page'     => (int) $page,
			'per_page' => (int) $perPage,
			'total'    => (int) $total,
		);
	}

	/**
	 * Authenticate the current request, requiring the given scope (or null
	 * for "any valid token"). On failure, emits the appropriate JSON error
	 * and exits - so this method always returns a valid token row OR does
	 * not return at all.
	 */
	private function _authenticate($scope)
	{
		$raw    = $this->_extractToken();
		$result = \nova_ext_sim_central\ApiAuth::validateToken($raw, $scope);

		if ($result['status'] !== 'ok') {
			$this->_emit($result['code'], array('error' => $result['message']));
		}
		// Remember the authenticated token for the request so projections
		// can consult its scopes (e.g. mission word_count is only exposed to
		// all-posts readers). Every endpoint calls _authenticate before it
		// projects anything.
		$this->_authToken = $result['token'];
		return $result['token'];
	}

	/**
	 * True when the current token may read arbitrary posts (not just its
	 * own). Gates the mission word_count field, since a mission total
	 * aggregates every author's posts.
	 */
	private function _tokenCanReadAllPosts()
	{
		if ( ! isset($this->_authToken)) {
			return false;
		}
		return $this->_tokenHasScope($this->_authToken, 'posts:read')
			|| $this->_tokenHasScope($this->_authToken, 'posts:read.all');
	}

	/**
	 * Pull the raw API token out of the request's `X-API-Key` header.
	 *
	 * We deliberately only accept X-API-Key, not Authorization: Bearer.
	 * Apache strips Authorization on most shared hosts (it considers it
	 * server-owned), which would silently break the API for admins who
	 * didn't know to add an .htaccess rewrite. X-* headers pass through
	 * untouched. One supported form = no configuration footgun.
	 *
	 * Returns the trimmed token string or null if the header is absent.
	 */
	private function _extractToken()
	{
		$value = null;

		if (function_exists('apache_request_headers')) {
			$headers = apache_request_headers();
			if (is_array($headers)) {
				foreach ($headers as $hname => $hvalue) {
					if (strcasecmp($hname, 'X-API-Key') === 0) {
						$value = $hvalue;
						break;
					}
				}
			}
		}

		// $_SERVER fallback. PHP exposes "X-API-Key" as HTTP_X_API_KEY
		// (uppercased, dashes -> underscores, HTTP_ prefix).
		if ($value === null && ! empty($_SERVER['HTTP_X_API_KEY'])) {
			$value = $_SERVER['HTTP_X_API_KEY'];
		}

		if ($value === null) {
			return null;
		}
		$value = trim($value);
		return $value === '' ? null : $value;
	}

	/**
	 * Emit a JSON response with the given HTTP status code, then halt. All
	 * API responses go through here so the content-type and exit behaviour
	 * are uniform.
	 */
	private function _emit($status, array $payload)
	{
		$this->_emitRaw($status, json_encode($payload));
	}

	/** Emit a pre-encoded JSON string (e.g. the cached Astrolabe snapshot). */
	private function _emitRaw($status, $json)
	{
		if ( ! headers_sent()) {
			http_response_code($status);
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
		}
		echo (string) $json;
		exit;
	}
}
