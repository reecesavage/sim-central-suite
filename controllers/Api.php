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
	public function posts()
	{
		$this->_gate();
		$this->_authenticate('posts:read');
		$this->load->model('posts_model', 'posts');

		$id = $this->uri->segment(5);
		if ($id !== null && $id !== '' && ctype_digit((string) $id)) {
			$row = $this->posts->get_post((int) $id);
			if ( ! $row || ! $this->_postIsVisible($row)) {
				$this->_emit(404, array('error' => 'Post not found.'));
			}
			$this->_emit(200, $this->_projectPost($row));
		}

		$status = $this->input->get('status', true);
		if ($status === null || $status === '') {
			$status = 'activated';
		}
		$mission = $this->input->get('mission', true);
		list($page, $perPage, $offset) = $this->_paging();

		$query = $this->posts->get_post_list(
			$mission ?: '',
			'desc',
			$perPage,
			$offset,
			$status === 'any' ? '' : $status
		);

		// Count separately - get_post_list doesn't return a total.
		$this->db->from('posts');
		if ( ! empty($mission)) {
			$this->db->where('post_mission', $mission);
		}
		if ($status !== 'any') {
			$this->db->where('post_status', $status);
		}
		$total = (int) $this->db->count_all_results();

		$data = array();
		foreach ($query->result() as $row) {
			$data[] = $this->_projectPost($row);
		}
		$this->_emit(200, $this->_paginate($data, $total, $page, $perPage));
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
		$out = array(
			'id'         => (int) $row->charid,
			'first_name' => isset($row->first_name) ? $row->first_name : null,
			'last_name'  => isset($row->last_name) ? $row->last_name : null,
			'suffix'     => isset($row->suffix) ? $row->suffix : null,
			'status'     => isset($row->crew_type) ? $row->crew_type : null,
			'rank'       => isset($row->rank) ? (int) $row->rank : null,
			'user_id'    => isset($row->user) ? (int) $row->user : null,
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
	 * Per-request cache of the suite's feature toggle map. Read once,
	 * referenced by every projector that needs to decide whether to surface
	 * a feature-added column.
	 */
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
		$header = $this->_authHeader();
		$result = \nova_ext_sim_central\ApiAuth::validateBearer($header, $scope);

		if ($result['status'] !== 'ok') {
			$this->_emit($result['code'], array('error' => $result['message']));
		}
		return $result['token'];
	}

	/**
	 * Read the Authorization header in a way that works under both Apache
	 * (apache_request_headers) and FastCGI ($_SERVER). Returns null if no
	 * header is present.
	 */
	private function _authHeader()
	{
		if (function_exists('apache_request_headers')) {
			$headers = apache_request_headers();
			foreach ($headers as $name => $value) {
				if (strcasecmp($name, 'Authorization') === 0) {
					return $value;
				}
			}
		}
		if ( ! empty($_SERVER['HTTP_AUTHORIZATION'])) {
			return $_SERVER['HTTP_AUTHORIZATION'];
		}
		if ( ! empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
			return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		return null;
	}

	/**
	 * Emit a JSON response with the given HTTP status code, then halt. All
	 * API responses go through here so the content-type and exit behaviour
	 * are uniform.
	 */
	private function _emit($status, array $payload)
	{
		if ( ! headers_sent()) {
			http_response_code($status);
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
		}
		echo json_encode($payload);
		exit;
	}
}
