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
		$raw    = $this->_extractToken();
		$result = \nova_ext_sim_central\ApiAuth::validateToken($raw, $scope);

		if ($result['status'] !== 'ok') {
			$this->_emit($result['code'], array('error' => $result['message']));
		}
		return $result['token'];
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
		if ( ! headers_sent()) {
			http_response_code($status);
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
		}
		echo json_encode($payload);
		exit;
	}
}
