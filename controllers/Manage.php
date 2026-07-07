<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

require_once MODPATH.'core/libraries/Nova_controller_admin.php';
require_once dirname(__FILE__).'/../libraries/Migrations.php';

/**
 * Sim Central Suite - admin controller.
 *
 * One dashboard for every Sim Central feature. The $features registry below
 * declares each feature's metadata (display name, the standalone extension it
 * replaces, the database columns it needs, and the controller / model shim
 * blocks it injects). A feature may inject more than one block - anti_spam,
 * for example, owns both contact() and join() in Main.php - so 'shims' is an
 * array keyed by tag.
 *
 * The generic writer at the bottom of this file handles installing, updating,
 * and removing those shims using the marker pattern. It recognises three
 * pre-marker conditions: a standalone extension's marked block (full
 * take-over), an unmarked legacy method (lexer-based replace), or nothing at
 * all (fresh insert).
 */
class __extensions__nova_ext_sim_central__Manage extends Nova_controller_admin
{
	private $features;

	public function __construct()
	{
		parent::__construct();

		$this->ci =& get_instance();
		$this->ci->load->model('settings_model', 'settings');
		$this->_regions['nav_sub'] = Menu::build('adminsub', 'manageext');
		$this->features = $this->_featureRegistry();
	}

	// ---------- routes ----------

	public function index()
	{
		Auth::check_access('site/settings');

		$configPath = dirname(__FILE__).'/../config.json';
		$action     = isset($_POST['action'])  ? $_POST['action']  : '';
		$feature    = isset($_POST['feature']) ? $_POST['feature'] : '';

		if ($action === 'toggle_on' && isset($this->features[$feature])) {
			$this->_flash($this->_toggleFeature($feature, true, $configPath));
		} elseif ($action === 'toggle_off' && isset($this->features[$feature])) {
			$this->_flash($this->_toggleFeature($feature, false, $configPath));
		} elseif ($action === 'setup_database' && isset($this->features[$feature])) {
			$this->_flash($this->_setupDatabase($feature));
		} elseif ($action === 'install_shim' && isset($this->features[$feature])) {
			$this->_flash($this->_writeAllShims($feature));
		} elseif ($action === 'disable_standalone' && isset($this->features[$feature])) {
			$this->_flash($this->_disableStandalone($feature));
		} elseif ($action === 'do_update') {
			$targetVersion = isset($_POST['target_version']) ? $_POST['target_version'] : '';
			return $this->_runUpdate($targetVersion);
		} elseif ($action === 'recheck_update') {
			// Force-bypass the 24h cache; result lands in the same row
			// that the regular dashboard render already reads from.
			\nova_ext_sim_central\UpdateCheck::latest(true);
			$this->_flash(array('success', 'Update check refreshed.'));
		}

		$data = array();
		$data['title']    = 'Sim Central Suite';
		$data['jsons']    = $this->_loadConfig($configPath);

		// One-time notice when the post-update auto-runner did work on
		// the first request after an upgrade (see Migrations::runPending).
		// Shown once, then the action list is cleared from state.
		$auto = isset($data['jsons']['auto_migrated_last']) ? $data['jsons']['auto_migrated_last'] : null;
		if (is_array($auto)
			&& ( ! empty($auto['actions']) || ! empty($auto['errors']))
			&& (string) (isset($auto['version']) ? $auto['version'] : '') === \nova_ext_sim_central\Config::version()) {
			$lines = array();
			foreach ((array) (isset($auto['actions']) ? $auto['actions'] : array()) as $line) {
				$lines[] = htmlspecialchars($line, ENT_QUOTES);
			}
			foreach ((array) (isset($auto['errors']) ? $auto['errors'] : array()) as $line) {
				$lines[] = '<strong>Needs attention:</strong> '.htmlspecialchars($line, ENT_QUOTES);
			}
			$this->_flash(array(
				empty($auto['errors']) ? 'success' : 'error',
				'Post-update housekeeping ran automatically:<br />'.implode('<br />', $lines),
			));
			$state = $data['jsons'];
			$state['auto_migrated_last']['actions'] = array();
			$state['auto_migrated_last']['errors']  = array();
			$this->_saveConfig($configPath, $state);
		}

		$data['features'] = $this->_featuresWithStatus($data['jsons']);
		$data['version']  = \nova_ext_sim_central\Config::version();
		$data['update']   = \nova_ext_sim_central\UpdateCheck::latest();

		$this->_regions['title']  .= 'Sim Central Suite';
		$this->_regions['content'] = $this->extension['nova_ext_sim_central']
			->view('config', $this->skin, 'admin', $data);

		Template::assign($this->_regions);
		Template::render();
	}

	public function display_name()
	{
		$this->_featureConfigPage('display_name');
	}

	public function rest_api()
	{
		Auth::check_access('site/settings');

		if ( ! isset($this->features['rest_api'])) {
			show_404();
			return;
		}

		// ApiAuth normally loads in init.php only when the feature is on; the
		// admin reaches this page through the regular nav after enabling it,
		// so the require has already happened. Belt-and-braces require here
		// for the edge case where an admin lands directly while the feature
		// toggle is mid-flight.
		require_once dirname(__FILE__).'/../libraries/ApiAuth.php';

		$action      = isset($_POST['action']) ? $_POST['action'] : '';
		$newTokenRaw = null;

		// Broker URL + secret live in the suite settings row, not the tokens
		// table, so this save works even before "Setup database" has run.
		if ($action === 'save_broker_config') {
			$this->_flash($this->_saveBrokerConfig());
		}

		// Defensive: the tokens table is created by the feature's *Setup
		// database* step. If the admin enabled the feature toggle but hasn't
		// run setup_database yet, every query below would 500. Detect that
		// state explicitly and render a friendly prompt instead.
		$dbReady = empty($this->_missingTables('rest_api'));

		if ($dbReady) {
			if ($action === 'create_token') {
				list($result, $newTokenRaw) = $this->_createApiToken();
				$this->_flash($result);
			} elseif ($action === 'revoke_token') {
				$this->_flash($this->_revokeApiToken());
			} elseif ($action === 'delete_token') {
				$this->_flash($this->_deleteApiToken());
			} elseif ($action === 'grant_sim_central') {
				list($result, $newTokenRaw) = \nova_ext_sim_central\SimCentralAccess::grant();
				$this->_flash($result);
			} elseif ($action === 'revoke_sim_central') {
				$this->_flash(\nova_ext_sim_central\SimCentralAccess::revoke());
			}
		}

		// Token-authenticated POSTs (create post/webhook, user disable/etc.)
		// would otherwise be rejected by Nova's CSRF protection. Make sure the
		// API path is on the CSRF allowlist; this self-heals on every visit.
		$csrfExclusion = $this->_ensureApiCsrfExclusion();

		$f               = $this->features['rest_api'];
		$data            = array();
		$data['title']   = 'Sim Central Suite - '.$f['name'];
		$data['feature'] = $f;
		$data['csrf_exclusion'] = $csrfExclusion;
		$data['jsons']   = $this->_loadConfig($configPath = dirname(__FILE__).'/../config.json');
		$data['db_ready']= $dbReady;
		$data['tokens']  = $dbReady
			? $this->db->order_by('revoked_at IS NULL', 'DESC', false)
				->order_by('created_at', 'DESC')
				->get('sim_central_api_tokens')
				->result()
			: array();
		$data['available_scopes'] = $this->_apiAvailableScopes();
		$data['new_token_raw']    = $newTokenRaw;
		$data['images']           = $this->_iconImages();
		$data['api_base_url']     = site_url('extensions/nova_ext_sim_central/Api');

		// Sim Central access state for the "Grant access" card + broker config.
		$data['sim_central']        = \nova_ext_sim_central\SimCentralAccess::status();
		$data['sim_central_scopes'] = \nova_ext_sim_central\SimCentralAccess::scopes();
		$data['broker_configured']  = \nova_ext_sim_central\Broker::isConfigured();

		// Active users for the "bind token to user" dropdown, labelled with their
		// main character so two users with the same name are distinguishable.
		// characters.display_name only exists once the Display Name feature has
		// run its Setup database, so include it only when present - otherwise the
		// whole query errors and the Configure page 500s on fresh sites.
		$charFields  = $this->db->list_fields($this->db->dbprefix.'characters');
		$userSelect  = 'users.userid, users.name, users.is_sysadmin, c.first_name, c.last_name';
		if (in_array('display_name', $charFields, true)) {
			$userSelect .= ', c.display_name';
		}
		$data['users'] = $this->db
			->select($userSelect)
			->from('users')
			->join('characters c', 'c.charid = users.main_char', 'left')
			->where('users.status', 'active')
			->order_by('users.name', 'asc')
			->get()->result();

		// id => display label for showing the bound user on existing token rows
		// (includes inactive users so old tokens still resolve a name).
		$data['user_names'] = array();
		foreach ($this->db->select('userid, name')->get('users')->result() as $u) {
			$data['user_names'][(int) $u->userid] = $u->name;
		}

		$this->_regions['title']  .= $f['name'];
		$this->_regions['content'] = $this->_readable($this->extension['nova_ext_sim_central']
			->view('rest_api', $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	public function mobile()
	{
		Auth::check_access('site/settings');

		if ( ! isset($this->features['mobile'])) {
			show_404();
			return;
		}

		// Register the pre_system hook that serves the clean /mobile URL.
		// Self-heals on every visit (idempotent); falls back to a manual
		// snippet if application/config/hooks.php isn't writable.
		$routeStatus = $this->_ensureMobileRoute();

		$f               = $this->features['mobile'];
		$data            = array();
		$data['title']   = 'Sim Central Suite - '.$f['name'];
		$data['feature'] = $f;
		$data['route']   = $routeStatus;
		$data['mobile_url'] = site_url('mobile');
		$data['ext_url']    = site_url('extensions/nova_ext_sim_central/Mobile/index');
		$data['images']     = $this->_iconImages();

		$this->_regions['title']  .= $f['name'];
		$this->_regions['content'] = $this->_readable($this->extension['nova_ext_sim_central']
			->view('mobile', $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	public function webhooks()
	{
		Auth::check_access('site/settings');

		if ( ! isset($this->features['webhooks'])) {
			show_404();
			return;
		}

		require_once dirname(__FILE__).'/../libraries/Webhooks.php';

		$action     = isset($_POST['action']) ? $_POST['action'] : '';
		$dbReady    = empty($this->_missingTables('webhooks'));

		// Edit link is /Manage/webhooks/edit/{id}, so the id is in segment 6
		// (segment 5 is the literal "edit"). The extension router consumes
		// segments 1-2 (extensions/<name>); 3=Manage, 4=webhooks, 5=edit, 6=id.
		$editingId = ($this->uri->segment(5) === 'edit')
			? (int) $this->uri->segment(6)
			: 0;

		if ($dbReady) {
			if ($action === 'create_webhook') {
				$this->_flash($this->_saveWebhook(null));
			} elseif ($action === 'update_webhook') {
				$id = (int) ($_POST['id'] ?? 0);
				if ($id > 0) { $this->_flash($this->_saveWebhook($id)); }
			} elseif ($action === 'delete_webhook') {
				$this->_flash($this->_deleteWebhook());
			} elseif ($action === 'toggle_webhook') {
				$this->_flash($this->_toggleWebhook());
			} elseif ($action === 'test_webhook') {
				$id = (int) ($_POST['id'] ?? 0);
				$ev = isset($_POST['event']) ? (string) $_POST['event'] : 'post.posted';
				if ($id > 0) { $this->_flash(\nova_ext_sim_central\Webhooks::testWebhook($id, $ev)); }
			}
		}

		$f               = $this->features['webhooks'];
		$data            = array();
		$data['title']   = 'Sim Central Suite - '.$f['name'];
		$data['feature'] = $f;
		$data['jsons']   = $this->_loadConfig(dirname(__FILE__).'/../config.json');
		$data['db_ready']= $dbReady;
		$data['available_events'] = $this->_webhookAvailableEvents();
		$data['available_formats'] = $this->_webhookAvailableFormats();
		$data['available_news_types'] = $this->_webhookNewsTypes();
		$data['default_title_template']       = \nova_ext_sim_central\Webhooks::DEFAULT_TITLE_TEMPLATE;
		$data['default_description_template'] = \nova_ext_sim_central\Webhooks::DEFAULT_DESCRIPTION_TEMPLATE;
		$data['default_log_title_template']        = \nova_ext_sim_central\Webhooks::DEFAULT_LOG_TITLE_TEMPLATE;
		$data['default_log_description_template']  = \nova_ext_sim_central\Webhooks::DEFAULT_LOG_DESCRIPTION_TEMPLATE;
		$data['default_news_title_template']       = \nova_ext_sim_central\Webhooks::DEFAULT_NEWS_TITLE_TEMPLATE;
		$data['default_news_description_template'] = \nova_ext_sim_central\Webhooks::DEFAULT_NEWS_DESCRIPTION_TEMPLATE;
		$data['webhooks'] = $dbReady
			? $this->db->order_by('enabled', 'DESC')->order_by('created_at', 'DESC')->get('sim_central_webhooks')->result()
			: array();
		$data['edit_row'] = null;
		if ($dbReady && $editingId > 0) {
			$data['edit_row'] = $this->db->get_where('sim_central_webhooks', array('id' => $editingId))->row();
		}

		$this->_regions['title']  .= $f['name'];
		$this->_regions['content'] = $this->_readable($this->extension['nova_ext_sim_central']
			->view('webhooks', $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	public function api_explorer()
	{
		Auth::check_access('site/settings');

		if ( ! isset($this->features['rest_api'])) {
			show_404();
			return;
		}

		require_once dirname(__FILE__).'/../libraries/ApiEndpoints.php';

		$f               = $this->features['rest_api'];
		$data            = array();
		$data['title']   = 'Sim Central Suite - API Explorer';
		$data['feature'] = $f;
		$data['jsons']   = $this->_loadConfig(dirname(__FILE__).'/../config.json');
		$data['endpoints'] = \nova_ext_sim_central\ApiEndpoints::endpoints();
		$data['schemas']   = \nova_ext_sim_central\ApiEndpoints::schemas();
		$data['api_base_url'] = site_url('extensions/nova_ext_sim_central/Api');
		$data['openapi_url']  = site_url('extensions/nova_ext_sim_central/Api/openapi');

		// Active tokens for the Try It selector. We never expose token_hash
		// or anything that could reveal the raw value - admins paste their
		// own token into the input. Listing labels + prefixes is for the
		// UX hint ("you have these tokens; paste the corresponding scapi_..").
		$data['token_hints'] = $this->db
			->select('label, token_prefix, scopes')
			->where('revoked_at IS NULL', null, false)
			->where('(expires_at IS NULL OR expires_at > NOW())', null, false)
			->order_by('label', 'asc')
			->get('sim_central_api_tokens')
			->result();

		$this->_regions['title']  .= 'API Explorer';
		$this->_regions['content'] = $this->_readable($this->extension['nova_ext_sim_central']
			->view('api_explorer', $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	public function discord_auth()
	{
		Auth::check_access('site/settings');

		if ( ! isset($this->features['discord_auth'])) {
			show_404();
			return;
		}

		$configPath = dirname(__FILE__).'/../config.json';
		$action     = isset($_POST['action']) ? $_POST['action'] : '';

		if ($action === 'save_discord_auth_config') {
			$this->_flash($this->_saveDiscordAuthConfig($configPath));
		} elseif ($action === 'fetch_public_key') {
			$this->_flash($this->_fetchDiscordAuthPublicKey($configPath));
		}

		$f               = $this->features['discord_auth'];
		$data            = array();
		$data['title']   = 'Sim Central Suite - '.$f['name'];
		$data['feature'] = $f;
		$data['jsons']   = $this->_loadConfig($configPath);
		$data['callback_url'] = \nova_ext_sim_central\DiscordAuth::callbackUrl();

		$this->_regions['title']  .= $f['name'];
		$this->_regions['content'] = $this->_readable($this->extension['nova_ext_sim_central']
			->view('discord_auth', $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	public function content_filter()
	{
		Auth::check_access('site/settings');

		if ( ! isset($this->features['content_filter'])) {
			show_404();
			return;
		}

		$configPath = dirname(__FILE__).'/../config.json';
		$action     = isset($_POST['action']) ? $_POST['action'] : '';

		if ($action === 'save_content_filter_config') {
			$this->_flash($this->_saveContentFilterConfig($configPath));
		}

		$f               = $this->features['content_filter'];
		$data            = array();
		$data['title']   = 'Sim Central Suite - '.$f['name'];
		$data['feature'] = $f;
		$data['jsons']   = $this->_loadConfig($configPath);
		$data['active']  = \nova_ext_sim_central\ContentFilter::isActive();

		$this->_regions['title']  .= $f['name'];
		$this->_regions['content'] = $this->_readable($this->extension['nova_ext_sim_central']
			->view('content_filter', $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	public function ordered_mission_posts()
	{
		Auth::check_access('site/settings');

		if ( ! isset($this->features['ordered_mission_posts'])) {
			show_404();
			return;
		}

		$configPath = dirname(__FILE__).'/../config.json';
		$action     = isset($_POST['action']) ? $_POST['action'] : '';

		if ($action === 'save_ordered_config') {
			$this->_flash($this->_saveOrderedConfig($configPath));
		}

		$f               = $this->features['ordered_mission_posts'];
		$data            = array();
		$data['title']   = 'Sim Central Suite - '.$f['name'];
		$data['feature'] = $f;
		$data['jsons']   = $this->_loadConfig($configPath);

		// Legacy mode is only meaningful when the chronological_mission_posts
		// columns are still present on the posts table.
		$postFields = $this->db->list_fields($this->db->dbprefix.'posts');
		$data['legacy_available'] = in_array('post_chronological_mission_post_day', $postFields, true);

		$this->_regions['title']  .= $f['name'];
		$this->_regions['content'] = $this->_readable($this->extension['nova_ext_sim_central']
			->view('ordered_mission_posts', $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	public function summary()
	{
		Auth::check_access('site/settings');

		if ( ! isset($this->features['summary'])) {
			show_404();
			return;
		}

		$configPath = dirname(__FILE__).'/../config.json';
		$action     = isset($_POST['action']) ? $_POST['action'] : '';

		if ($action === 'save_summary_config') {
			$this->_flash($this->_saveSummaryConfig($configPath));
		}

		$f               = $this->features['summary'];
		$data            = array();
		$data['title']   = 'Sim Central Suite - '.$f['name'];
		$data['feature'] = $f;
		$data['jsons']   = $this->_loadConfig($configPath);

		$this->_regions['title']  .= $f['name'];
		$this->_regions['content'] = $this->_readable($this->extension['nova_ext_sim_central']
			->view('summary', $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	public function url_parser()
	{
		Auth::check_access('site/settings');

		$submit = isset($_POST['submit']) ? strtolower($_POST['submit']) : '';
		if ($this->uri->segment(4) === 'delete' && $submit === 'submit') {
			$this->_flash($this->_urlParserDelete());
		}

		$data           = array();
		$data['title']  = 'Sim Central Suite - URL Parser';
		$data['images'] = $this->_iconImages();

		$this->db->from('tag');
		$data['models'] = $this->db->get()->result();

		$this->_regions['title']     .= 'URL Parser';
		$this->_regions['javascript'] .= $this->extension['nova_ext_sim_central']->inline_js('url_parser', 'admin', $data);
		$this->_regions['content']    = $this->_readable($this->extension['nova_ext_sim_central']
			->view('url_parser', $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	public function url_parser_create()
	{
		Auth::check_access('site/settings');

		if (isset($_POST['submit']) && $_POST['submit'] === 'Submit') {
			$dataArray = array(
				'title'      => isset($_POST['title']) ? $_POST['title'] : '',
				'url'        => isset($_POST['url']) ? $_POST['url'] : '',
				'post_url'   => isset($_POST['post_url']) ? $_POST['post_url'] : '',
				'is_new_tab' => isset($_POST['is_new_tab']) ? $_POST['is_new_tab'] : 0,
			);
			$this->db->insert('tag', $dataArray);
			$this->dbutil->optimize_table('tag');
			$this->_flash(array('success', 'Tag added.'));
		}

		$data          = array();
		$data['title'] = 'Sim Central Suite - Add Tag';

		$this->_regions['title']  .= 'Add Tag';
		$this->_regions['content'] = $this->_readable($this->extension['nova_ext_sim_central']
			->view('url_parser_create', $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	public function url_parser_edit()
	{
		Auth::check_access('site/settings');

		$id = $this->uri->segment(5);

		if (isset($_POST['submit']) && $_POST['submit'] === 'Submit') {
			$dataArray = array(
				'title'      => isset($_POST['title']) ? $_POST['title'] : '',
				'url'        => isset($_POST['url']) ? $_POST['url'] : '',
				'post_url'   => isset($_POST['post_url']) ? $_POST['post_url'] : '',
				'is_new_tab' => isset($_POST['is_new_tab']) ? $_POST['is_new_tab'] : 0,
			);
			$this->db->where('id', $id);
			$this->db->update('tag', $dataArray);
			$this->dbutil->optimize_table('tag');
			$this->_flash(array('success', 'Tag updated.'));
		}

		$data          = array();
		$data['title'] = 'Sim Central Suite - Edit Tag';
		$query         = $this->db->get_where('tag', array('id' => $id));
		$data['model'] = ($query->num_rows() > 0) ? $query->row() : false;

		$this->_regions['title']  .= 'Edit Tag';
		$this->_regions['content'] = $this->_readable($this->extension['nova_ext_sim_central']
			->view('url_parser_edit', $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	public function anti_spam()
	{
		Auth::check_access('site/settings');

		// The delete confirm form posts to Manage/anti_spam/delete/{id}, so the
		// "delete" action sits at URI segment 5 (segment 4 is the method name,
		// "anti_spam"). Checking segment 4 here meant the branch never ran.
		$submit = isset($_POST['submit']) ? strtolower($_POST['submit']) : '';
		if ($this->uri->segment(5) === 'delete' && $submit === 'submit') {
			$this->_flash($this->_antiSpamDelete());
		}

		$data           = array();
		$data['title']  = 'Sim Central Suite - Anti Spam Questions';
		$data['images'] = $this->_iconImages();

		$this->db->from('settings');
		$this->db->where('setting_key', 'question');
		$data['models'] = $this->db->get()->result();

		$this->_regions['title']     .= 'Anti Spam Questions';
		$this->_regions['javascript'] .= $this->extension['nova_ext_sim_central']->inline_js('anti_spam', 'admin', $data);
		$this->_regions['content']    = $this->_readable($this->extension['nova_ext_sim_central']
			->view('anti_spam', $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	public function anti_spam_create()
	{
		Auth::check_access('site/settings');

		if (isset($_POST['submit']) && $_POST['submit'] === 'Submit') {
			$json = array(
				'question' => isset($_POST['question']) ? $_POST['question'] : '',
				'answer'   => $this->_cleanAnswers(isset($_POST['answer']) ? $_POST['answer'] : array()),
			);
			$this->ci->settings->add_new_setting(array(
				'setting_key'   => 'question',
				'setting_label' => 'Questions and Answer',
				'setting_value' => json_encode($json),
			));
			$this->_flash(array('success', 'Question added.'));
		}

		$data           = array();
		$data['title']  = 'Sim Central Suite - Add Question';

		$this->_regions['title']     .= 'Add Question';
		$this->_regions['javascript'] .= $this->extension['nova_ext_sim_central']->inline_js('anti_spam', 'admin', $data);
		$this->_regions['content']    = $this->_readable($this->extension['nova_ext_sim_central']
			->view('anti_spam_create', $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	public function anti_spam_edit()
	{
		Auth::check_access('site/settings');

		$id = $this->uri->segment(5);

		if (isset($_POST['submit']) && $_POST['submit'] === 'Submit') {
			$json = array(
				'question' => isset($_POST['question']) ? $_POST['question'] : '',
				'answer'   => $this->_cleanAnswers(isset($_POST['answer']) ? $_POST['answer'] : array()),
			);
			$this->ci->settings->update_setting($id, array(
				'setting_value' => json_encode($json),
			), 'setting_id');
			$this->_flash(array('success', 'Question updated.'));
		}

		$data           = array();
		$data['title']  = 'Sim Central Suite - Edit Question';
		$query          = $this->db->get_where('settings', array('setting_id' => $id));
		$data['model']  = ($query->num_rows() > 0) ? $query->row() : false;

		$this->_regions['title']     .= 'Edit Question';
		$this->_regions['javascript'] .= $this->extension['nova_ext_sim_central']->inline_js('anti_spam', 'admin', $data);
		$this->_regions['content']    = $this->_readable($this->extension['nova_ext_sim_central']
			->view('anti_spam_edit', $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	private function _featuresWithStatus($json)
	{
		$enabledFeatures   = isset($json['features']) ? $json['features'] : array();
		$extensions        = $this->config->item('extensions');
		$enabledExtensions = is_array($extensions) && isset($extensions['enabled']) ? $extensions['enabled'] : array();

		$out = array();
		foreach ($this->features as $key => $f) {
			$f['key']                 = $key;
			$f['enabled']             = ! empty($enabledFeatures[$key]);
			$f['standalone_conflict'] = in_array($f['standalone'], $enabledExtensions, true);
			$f['missing_columns']     = ! empty($f['requires_db']) ? $this->_missingColumns($key) : array();
			$f['missing_tables']      = ! empty($f['requires_tables']) ? $this->_missingTables($key) : array();
			$f['missing_indexes']     = ! empty($f['requires_indexes']) ? $this->_missingIndexes($key) : array();
			$f['stale_columns']       = ! empty($f['removes_db']) ? $this->_staleColumns($key) : array();
			$f['db_ready']            = empty($f['missing_columns']) && empty($f['missing_tables']) && empty($f['missing_indexes']) && empty($f['stale_columns']);
			$f['shim_state']          = ! empty($f['shims']) ? $this->_featureCombinedState($key) : 'none';
			$out[$key] = $f;
		}
		return $out;
	}

	// ---------- per-feature config page helper ----------

	private function _featureConfigPage($key)
	{
		Auth::check_access('site/settings');

		if ( ! isset($this->features[$key])) {
			show_404();
			return;
		}

		$configPath = dirname(__FILE__).'/../config.json';

		if (isset($_POST['action']) && $_POST['action'] === 'save_labels') {
			$this->_flash($this->_saveLabels($configPath));
		}

		$f                = $this->features[$key];
		$data             = array();
		$data['title']    = 'Sim Central Suite - '.$f['name'];
		$data['feature']  = $f;
		$data['jsons']    = $this->_loadConfig($configPath);

		$this->_regions['title']  .= $f['name'];
		$this->_regions['content'] = $this->_readable($this->extension['nova_ext_sim_central']
			->view($key, $this->skin, 'admin', $data));

		Template::assign($this->_regions);
		Template::render();
	}

	// ---------- generic helpers ----------

	private function _loadConfig($configPath)
	{
		// $configPath is ignored - kept for the existing call-sites'
		// signature. All persisted state lives in a row of the settings
		// table, with config.json providing bundled defaults; the
		// Config helper handles the layering. See libraries/Config.php.
		\nova_ext_sim_central\Config::clearCache();
		return \nova_ext_sim_central\Config::load();
	}

	private function _saveConfig($configPath, $json)
	{
		\nova_ext_sim_central\Config::save($json);
	}

	private function _flash($result)
	{
		$flash = array(
			'status'  => ($result[0] === 'error') ? 'error' : 'success',
			'message' => text_output($result[1]),
		);
		$this->_regions['flash_message'] = Location::view('flash', $this->skin, 'admin', $flash);
	}

	/**
	 * Wrap a suite admin page's rendered HTML in the readability baseline:
	 * prepend the shared sc_readable.css and scope it with a .sc-readable
	 * container so the page's light-background boxes and native inputs stay
	 * legible under skins that use light body text / dark controls (e.g. Titan).
	 * Nova's own pages are untouched because the rules are scoped to the class.
	 */
	private function _readable($html)
	{
		return $this->extension['nova_ext_sim_central']->inline_css('sc_readable', 'admin')
			.'<div class="sc-readable">'.$html.'</div>';
	}

	private function _iconImages()
	{
		return array(
			'add' => array(
				'src'   => Location::img('icon-add.png', $this->skin, 'admin'),
				'alt'   => ucfirst(lang('actions_add')),
				'title' => ucfirst(lang('actions_add')),
				'class' => 'image inline_img_left',
			),
			'delete' => array(
				'src'   => Location::img('icon-delete.png', $this->skin, 'admin'),
				'alt'   => lang('actions_delete'),
				'title' => ucfirst(lang('actions_delete')),
				'class' => 'image',
			),
			'edit' => array(
				'src'   => Location::img('icon-edit.png', $this->skin, 'admin'),
				'alt'   => lang('actions_edit'),
				'title' => ucfirst(lang('actions_edit')),
				'class' => 'image',
			),
		);
	}

	// ---------- toggle ----------

	private function _toggleFeature($key, $on, $configPath)
	{
		$f    = $this->features[$key];
		$json = $this->_loadConfig($configPath);

		if ($on) {
			$extensions        = $this->config->item('extensions');
			$enabledExtensions = is_array($extensions) && isset($extensions['enabled']) ? $extensions['enabled'] : array();
			if (in_array($f['standalone'], $enabledExtensions, true)) {
				return array(
					'error',
					$f['name'].' standalone extension ('.$f['standalone'].') is still enabled in '
					.'application/config/extensions.php. Disable it there before enabling the suite version.',
				);
			}
			$json['features'][$key] = true;
			$this->_saveConfig($configPath, $json);
			return array('success', $f['name'].' enabled.');
		}

		if ( ! empty($f['shims'])) {
			// Compute the future enabled map (current state minus the feature
			// being turned off) so the refcount check in _removeShim knows
			// what's still using the shim.
			$futureEnabled = isset($json['features']) ? $json['features'] : array();
			$futureEnabled[$key] = false;
			$this->_removeAllShims($key, $futureEnabled);
		}
		$json['features'][$key] = false;
		$this->_saveConfig($configPath, $json);
		return array('success', $f['name'].' disabled. Shim removed where no other enabled feature still needs it; database columns left in place.');
	}

	// ---------- standalone removal ----------

	/**
	 * Remove the standalone extension this suite feature replaces. Two
	 * effects:
	 *   1. Strip the `$config['extensions']['enabled'][] = 'nova_ext_X';`
	 *      line from application/config/extensions.php (commented lines
	 *      are left alone).
	 *   2. Delete any menu_items rows whose menu_link points into the
	 *      standalone's URL space, so the orphan Manage Extensions entry
	 *      goes away.
	 *
	 * The standalone's Installer recreates its menu item on every page
	 * load while it's enabled, so the extensions.php edit MUST happen
	 * before/with the menu delete - otherwise the next request would just
	 * re-add the row. We do extensions.php first.
	 *
	 * Database tables/columns the standalone created are intentionally
	 * left in place; the suite reuses them.
	 */
	private function _disableStandalone($key)
	{
		$f = $this->features[$key];
		if (empty($f['standalone'])) {
			return array('error', 'No standalone extension is registered for '.$f['name'].'.');
		}
		$standalone = $f['standalone'];

		$extensionsPhp = APPPATH.'config/extensions.php';
		if ( ! is_writable($extensionsPhp)) {
			return array('error', 'Cannot write to '.$extensionsPhp.'. Fix permissions and try again.');
		}

		$contents = file_get_contents($extensionsPhp);
		if ($contents === false) {
			return array('error', 'Could not read '.$extensionsPhp.'.');
		}

		// Match: optional leading whitespace, $config['extensions']['enabled'][] = 'X';
		// with either quote style and any spacing. The `^` anchor + `\$config`
		// requirement skips `//` and `#` commented lines automatically because
		// they don't start with `$config`.
		$pattern = '/^[ \t]*\$config\[\'extensions\'\]\[\'enabled\'\]\[\]\s*=\s*([\'"])'
			.preg_quote($standalone, '/').'\1\s*;[ \t]*\r?\n?/m';
		$updated = preg_replace($pattern, '', $contents, -1, $removed);

		if ($removed === 0) {
			// Already absent (or only present in commented form). Still try
			// to clean up any orphan menu rows below.
		} else {
			if (file_put_contents($extensionsPhp, $updated) === false) {
				return array('error', 'Failed to write '.$extensionsPhp.'.');
			}
			// Force the PHP opcache to drop its cached bytecode for this
			// file so the very next request reads the freshly-edited copy
			// from disk. Without this, opcache may serve the stale
			// (still-enabling) version for up to a few seconds. The
			// standalone's Installer would then run again on the next
			// page load, miss the menu row we're about to delete, and
			// insert a new one - exactly the orphan we just removed.
			if (function_exists('opcache_invalidate')) {
				@opcache_invalidate($extensionsPhp, true);
			}
		}

		// Scope the menu delete to links that actually point INTO the
		// extension's URL space. Catches both `extensions/X` and
		// `extensions/X/anything/...` without accidentally hitting a row
		// that just happens to contain the substring.
		$prefix = $this->db->dbprefix.'menu_items';
		$this->db->query(
			'DELETE FROM `'.$prefix.'` WHERE menu_link = ? OR menu_link LIKE ?',
			array('extensions/'.$standalone, 'extensions/'.$standalone.'/%')
		);
		$menuDeleted = $this->db->affected_rows();

		// Build a user-facing summary that's honest about what changed.
		$bits = array();
		if ($removed > 0) {
			$bits[] = 'removed enable line from extensions.php';
		} else {
			$bits[] = 'extensions.php already clean';
		}
		if ($menuDeleted > 0) {
			$bits[] = 'deleted '.$menuDeleted.' menu item(s)';
		} else {
			$bits[] = 'no orphan menu items found';
		}
		return array('success', $standalone.' disabled - '.implode(', ', $bits).'. Reload to refresh the dashboard.');
	}

	// ---------- one-click updater ----------

	/**
	 * Runs the Updater pipeline and renders a dedicated "update complete"
	 * page (not the dashboard) on success - the user's request is still
	 * running the OLD code, so we don't try to render dashboard content
	 * generated by it; we just tell them to reload.
	 *
	 * On failure we fall back to the regular dashboard with a flash
	 * message so the user can see what's wrong and try again.
	 */
	private function _runUpdate($targetVersion)
	{
		$result = \nova_ext_sim_central\Updater::update($targetVersion);
		// $result = array($status, $message, $context)
		$status  = $result[0];
		$message = $result[1];
		$context = isset($result[2]) ? $result[2] : array();

		if ($status === 'success') {
			$data = array(
				'title'   => 'Sim Central Suite - update complete',
				'version' => isset($context['version']) ? $context['version'] : '',
				'backup'  => isset($context['backup'])  ? $context['backup']  : '',
				'message' => $message,
			);

			$this->_regions['title']  .= 'Update complete';
			$this->_regions['content'] = $this->extension['nova_ext_sim_central']
				->view('update_complete', $this->skin, 'admin', $data);

			Template::assign($this->_regions);
			Template::render();
			return;
		}

		// Error: render the dashboard with a flash so they can retry.
		$this->_flash(array('error', $message));

		$configPath       = dirname(__FILE__).'/../config.json';
		$data             = array();
		$data['title']    = 'Sim Central Suite';
		$data['jsons']    = $this->_loadConfig($configPath);
		$data['features'] = $this->_featuresWithStatus($data['jsons']);
		$data['version']  = \nova_ext_sim_central\Config::version();
		$data['update']   = \nova_ext_sim_central\UpdateCheck::latest();

		$this->_regions['title']  .= 'Sim Central Suite';
		$this->_regions['content'] = $this->extension['nova_ext_sim_central']
			->view('config', $this->skin, 'admin', $data);

		Template::assign($this->_regions);
		Template::render();
	}

	// ---------- labels ----------

	private function _saveLabels($configPath)
	{
		$json = $this->_loadConfig($configPath);
		foreach ($json as $section => $fields) {
			if ($section === 'features' || ! is_array($fields)) {
				continue;
			}
			foreach ($fields as $key => $field) {
				if ( ! is_array($field) || ! isset($field['value'])) {
					continue;
				}
				if (isset($_POST[$key])) {
					$json[$section][$key]['value'] = $_POST[$key];
				}
			}
		}
		$this->_saveConfig($configPath, $json);
		return array('success', 'Labels saved.');
	}

	// ---------- summary settings save ----------

	private function _saveSummaryConfig($configPath)
	{
		$json = $this->_loadConfig($configPath);

		if (isset($json['nova_ext_mission_post_summary']) && is_array($json['nova_ext_mission_post_summary'])) {
			foreach ($json['nova_ext_mission_post_summary'] as $key => $field) {
				if ( ! is_array($field) || ! isset($field['value'])) {
					continue;
				}
				if (isset($_POST[$key])) {
					$json['nova_ext_mission_post_summary'][$key]['value'] = $_POST[$key];
				}
			}
		}

		if ( ! isset($json['setting']) || ! is_array($json['setting'])) {
			$json['setting'] = array();
		}
		if (isset($_POST['rows'])) {
			$json['setting']['rows'] = (int) $_POST['rows'];
		}
		$json['setting']['summary_mode'] = isset($_POST['summary_mode']) ? $_POST['summary_mode'] : 0;

		$this->_saveConfig($configPath, $json);
		return array('success', 'Configuration saved.');
	}

	// ---------- ordered_mission_posts settings save ----------

	private function _saveOrderedConfig($configPath)
	{
		$json = $this->_loadConfig($configPath);

		if (isset($json['nova_ext_ordered_mission_posts']) && is_array($json['nova_ext_ordered_mission_posts'])) {
			foreach ($json['nova_ext_ordered_mission_posts'] as $key => $field) {
				if ( ! is_array($field) || ! isset($field['value'])) {
					continue;
				}
				if (isset($_POST[$key])) {
					$json['nova_ext_ordered_mission_posts'][$key]['value'] = $_POST[$key];
				}
			}
		}

		if ( ! isset($json['setting']) || ! is_array($json['setting'])) {
			$json['setting'] = array();
		}
		$json['setting']['legacy_mode'] = isset($_POST['legacy_mode']) ? $_POST['legacy_mode'] : 0;
		if (isset($_POST['post_order_column_fallback'])) {
			$json['setting']['post_order_column_fallback'] = $_POST['post_order_column_fallback'];
		}

		// Validate date/time format choices against the known keys so a
		// rogue POST can't park a junk value in the settings row.
		if (isset($_POST['date_format'])) {
			$dateChoices = \nova_ext_sim_central\TimelineFormat::dateFormatChoices();
			if (isset($dateChoices[$_POST['date_format']])) {
				$json['setting']['date_format'] = $_POST['date_format'];
			}
		}
		if (isset($_POST['time_format'])) {
			$timeChoices = \nova_ext_sim_central\TimelineFormat::timeFormatChoices();
			if (isset($timeChoices[$_POST['time_format']])) {
				$json['setting']['time_format'] = $_POST['time_format'];
			}
		}

		$this->_saveConfig($configPath, $json);
		return array('success', 'Configuration saved.');
	}

	// ---------- discord_auth settings save ----------

	private function _saveDiscordAuthConfig($configPath)
	{
		$json = $this->_loadConfig($configPath);

		if ( ! isset($json['setting']) || ! is_array($json['setting'])) {
			$json['setting'] = array();
		}

		if (isset($_POST['discord_auth_broker_url'])) {
			$json['setting']['discord_auth_broker_url'] = trim((string) $_POST['discord_auth_broker_url']);
		}
		if (isset($_POST['discord_auth_public_key'])) {
			$json['setting']['discord_auth_public_key'] = (string) $_POST['discord_auth_public_key'];
		}
		// Opt-in Discord email scope (v1.25.0+). Off by default; the only
		// use is pre-filling the join form, and the address is never
		// stored. Requires broker v1.2.0+.
		if (isset($_POST['discord_auth_request_email'])) {
			$json['setting']['discord_auth_request_email'] =
				((int) $_POST['discord_auth_request_email'] === 1) ? 1 : 0;
		}

		// Required-on-join is a plain checkbox (hidden+checkbox pair so
		// PHP always gets a 0/1 value). Auto-create mode was removed
		// in v1.3.1: Nova's join flow includes character approval the
		// suite has no business bypassing.
		if (isset($_POST['discord_auth_required_on_join'])) {
			$json['setting']['discord_auth_required_on_join'] =
				((int) $_POST['discord_auth_required_on_join'] === 1) ? 1 : 0;
		}

		// Global require-link (v1.4.0+). When on, every logged-in user
		// without a Discord ID gets bounced to the forced-link page.
		if (isset($_POST['discord_auth_required'])) {
			$json['setting']['discord_auth_required'] =
				((int) $_POST['discord_auth_required'] === 1) ? 1 : 0;
		}

		// Discord-only login mode (v1.8.0+). When on, the email +
		// password form is hidden on the login page (revealable for
		// sysadmins), and non-sysadmins who somehow sign in via
		// email + password get bounced to a Discord sign-in page.
		if (isset($_POST['discord_auth_login_discord_only'])) {
			$json['setting']['discord_auth_login_discord_only'] =
				((int) $_POST['discord_auth_login_discord_only'] === 1) ? 1 : 0;
		}

		// Required Discord guild membership (v1.7.0+). Textarea input
		// gets split on newlines, trimmed, filtered to digit-only IDs
		// (Discord snowflakes), de-duplicated.
		if (isset($_POST['discord_auth_required_guild_ids'])) {
			$raw   = (string) $_POST['discord_auth_required_guild_ids'];
			$lines = preg_split('/\r\n|\r|\n|,/', $raw);
			$ids   = array();
			foreach ($lines as $line) {
				$clean = preg_replace('/[^0-9]/', '', (string) $line);
				if ($clean !== '' && ! in_array($clean, $ids, true)) {
					$ids[] = $clean;
				}
			}
			$json['setting']['discord_auth_required_guild_ids'] = $ids;
		}
		if (isset($_POST['discord_auth_required_guild_mode'])) {
			$mode = (string) $_POST['discord_auth_required_guild_mode'];
			$json['setting']['discord_auth_required_guild_mode'] = ($mode === 'all') ? 'all' : 'any';
		}
		if (isset($_POST['discord_auth_required_guild_help'])) {
			// Admin-trusted; HTML allowed (invite-link anchors etc).
			$json['setting']['discord_auth_required_guild_help'] = (string) $_POST['discord_auth_required_guild_help'];
		}

		// Nova user IDs exempt from the link-required / Discord-only
		// enforcement. Same newline/comma split as the guild-ids field,
		// filtered to positive integers and de-duplicated.
		if (isset($_POST['discord_auth_required_exclude_user_ids'])) {
			$raw   = (string) $_POST['discord_auth_required_exclude_user_ids'];
			$lines = preg_split('/\r\n|\r|\n|,/', $raw);
			$ids   = array();
			foreach ($lines as $line) {
				$clean = (int) preg_replace('/[^0-9]/', '', (string) $line);
				if ($clean > 0 && ! in_array($clean, $ids, true)) {
					$ids[] = $clean;
				}
			}
			$json['setting']['discord_auth_required_exclude_user_ids'] = $ids;
		}

		// Migrate any leftover discord_auth_mode key from v1.3.0 - no
		// behavioural meaning in v1.3.1+.
		if (isset($json['setting']['discord_auth_mode'])) {
			unset($json['setting']['discord_auth_mode']);
		}

		$this->_saveConfig($configPath, $json);
		return array('success', 'Discord Sign-In configuration saved.');
	}

	/**
	 * Hit the broker's /.well-known/jwks.json endpoint, extract the
	 * first key, convert from JWK to PEM, and store it in the
	 * discord_auth_public_key setting. Saves the admin from copy-pasting
	 * the key when their broker is the canonical one or any standard
	 * JWKS-publishing OAuth broker.
	 */
	private function _fetchDiscordAuthPublicKey($configPath)
	{
		$json = $this->_loadConfig($configPath);
		$brokerUrl = isset($json['setting']['discord_auth_broker_url'])
			? rtrim((string) $json['setting']['discord_auth_broker_url'], '/')
			: \nova_ext_sim_central\DiscordAuth::DEFAULT_BROKER_URL;

		if ($brokerUrl === '' || strpos($brokerUrl, 'http') !== 0) {
			return array('error', 'Set a valid Broker URL first, then fetch.');
		}

		if ( ! function_exists('curl_init')) {
			return array('error', 'PHP cURL is not available - paste the key manually instead.');
		}

		$ch = curl_init($brokerUrl.'/.well-known/jwks.json');
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT        => 5,
		));
		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($code !== 200 || ! is_string($body)) {
			return array('error', 'Broker JWKS endpoint returned HTTP '.$code.'. Check the Broker URL.');
		}
		$jwks = json_decode($body, true);
		if ( ! is_array($jwks) || empty($jwks['keys'][0]['n']) || empty($jwks['keys'][0]['e'])) {
			return array('error', 'Broker JWKS response was not in the expected shape.');
		}

		$pem = $this->_jwkRsaToPem($jwks['keys'][0]['n'], $jwks['keys'][0]['e']);
		if ($pem === null) {
			return array('error', 'Could not convert the JWKS key to PEM. Paste the key manually instead.');
		}

		$json['setting']['discord_auth_public_key'] = $pem;
		$this->_saveConfig($configPath, $json);
		return array('success', 'Fetched and saved public key from the broker.');
	}

	/**
	 * Hand-rolled JWK->PEM for RSA public keys. The "right" way is
	 * phpseclib, but we have a zero-dependency policy. The JWK is just
	 * the modulus + exponent base64url-encoded; the PEM is a standard
	 * SPKI envelope around the same bytes ASN.1-encoded.
	 *
	 * Returns NULL if the inputs can't be decoded.
	 */
	private function _jwkRsaToPem($nB64u, $eB64u)
	{
		$n = $this->_b64uDecode($nB64u);
		$e = $this->_b64uDecode($eB64u);
		if ($n === false || $e === false) return null;

		$encInt = function($bytes) {
			// Prepend 0x00 if the high bit is set (so ASN.1 sees it as
			// a positive INTEGER, not a negative two's-complement).
			if (ord($bytes[0]) > 0x7F) {
				$bytes = "\x00".$bytes;
			}
			return "\x02".$this->_asn1Len(strlen($bytes)).$bytes;
		};

		// SEQUENCE { INTEGER n, INTEGER e }
		$pkSeq = "\x30".$this->_asn1Len(strlen($encInt($n).$encInt($e))).$encInt($n).$encInt($e);
		// BIT STRING wrapping the above (with the unused-bits byte = 0)
		$bitStr = "\x03".$this->_asn1Len(strlen("\x00".$pkSeq))."\x00".$pkSeq;
		// AlgorithmIdentifier for rsaEncryption (1.2.840.113549.1.1.1)
		$algoOid = "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00";
		$algoSeq = "\x30".$this->_asn1Len(strlen($algoOid)).$algoOid;
		// Outer SEQUENCE { algo, bitstring }
		$spki    = "\x30".$this->_asn1Len(strlen($algoSeq.$bitStr)).$algoSeq.$bitStr;

		$pem = "-----BEGIN PUBLIC KEY-----\n"
			.chunk_split(base64_encode($spki), 64, "\n")
			."-----END PUBLIC KEY-----\n";
		return $pem;
	}

	private function _asn1Len($len)
	{
		if ($len < 0x80) return chr($len);
		$hex = dechex($len);
		if (strlen($hex) % 2) $hex = '0'.$hex;
		$bytes = hex2bin($hex);
		return chr(0x80 | strlen($bytes)).$bytes;
	}

	private function _b64uDecode($s)
	{
		$s = strtr((string) $s, '-_', '+/');
		$pad = strlen($s) % 4;
		if ($pad) $s .= str_repeat('=', 4 - $pad);
		return base64_decode($s, true);
	}

	// ---------- content_filter settings save ----------

	private function _saveContentFilterConfig($configPath)
	{
		$json = $this->_loadConfig($configPath);

		// Editable label block (definitions + notice text).
		if (isset($json['nova_ext_content_filter']) && is_array($json['nova_ext_content_filter'])) {
			foreach ($json['nova_ext_content_filter'] as $key => $field) {
				if ( ! is_array($field) || ! isset($field['value'])) {
					continue;
				}
				if (isset($_POST[$key])) {
					$json['nova_ext_content_filter'][$key]['value'] = $_POST[$key];
				}
			}
		}

		if ( ! isset($json['setting']) || ! is_array($json['setting'])) {
			$json['setting'] = array();
		}

		// v1.5.0+: three booleans (language / violence / sex). Hidden+checkbox
		// pair guarantees a 0/1 lands in POST regardless of UI state.
		foreach (\nova_ext_sim_central\ContentFilter::DIMENSIONS as $dim) {
			$key = 'content_filter_allows_'.$dim;
			if (isset($_POST[$key])) {
				$json['setting'][$key] = ((int) $_POST[$key] === 1) ? 1 : 0;
			}
		}

		// Per-post age-gate default (boolean).
		if (isset($_POST['content_filter_age_gate_default'])) {
			$json['setting']['content_filter_age_gate_default'] =
				((int) $_POST['content_filter_age_gate_default'] === 1) ? 1 : 0;
		}

		// Also fire the submit-confirm popup on Save (not just Post). Off by
		// default - drafts aren't public, so the attestation only matters at
		// publish time unless the admin opts in here.
		if (isset($_POST['content_filter_confirm_on_save'])) {
			$json['setting']['content_filter_confirm_on_save'] =
				((int) $_POST['content_filter_confirm_on_save'] === 1) ? 1 : 0;
		}

		// Migrate away from the v1.2-v1.4 numeric rating keys. Their
		// values have already been read by ContentFilter::allows() as
		// the initial state of the checkboxes, so dropping them now
		// keeps the settings row tidy without changing behaviour.
		foreach (array('content_filter_language', 'content_filter_sex', 'content_filter_violence') as $oldKey) {
			if (array_key_exists($oldKey, $json['setting'])) {
				unset($json['setting'][$oldKey]);
			}
		}

		$this->_saveConfig($configPath, $json);
		return array('success', 'Content filter configuration saved.');
	}

	// ---------- anti_spam helpers ----------

	private function _cleanAnswers($raw)
	{
		if ( ! is_array($raw)) {
			return array();
		}
		$out = array();
		foreach ($raw as $a) {
			$a = is_string($a) ? trim($a) : '';
			if ($a !== '') {
				$out[] = $a;
			}
		}
		return $out;
	}

	private function _urlParserDelete()
	{
		$id = $this->input->post('id', true);
		$id = is_numeric($id) ? (int) $id : false;
		if ($id === false) {
			return array('error', 'Invalid tag id.');
		}
		$this->db->delete('tag', array('id' => $id));
		$this->dbutil->optimize_table('tag');
		return array('success', 'Tag deleted.');
	}

	private function _antiSpamDelete()
	{
		$id = $this->input->post('id', true);
		$id = is_numeric($id) ? (int) $id : false;
		if ($id === false) {
			return array('error', 'Invalid question id.');
		}
		$this->ci->settings->delete_setting($id);
		return array('success', 'Question deleted.');
	}

	// ---------- database setup ----------

	/**
	 * Canonical list of scopes the API understands. Kept as an explicit
	 * registry rather than a free-form string so the ACP create form can
	 * render real checkboxes and the validator can reject unknowns.
	 *
	 * Read scopes gate the GET endpoints. Write scopes gate the mutating
	 * endpoints (user status changes, webhook management) - grant these only
	 * to trusted automation.
	 */
	private function _apiAvailableScopes()
	{
		return \nova_ext_sim_central\ApiAuth::availableScopes();
	}

	/**
	 * Create a new API token from POSTed form data.
	 *
	 * Returns array($result_tuple, $raw_token_or_null). The raw token is
	 * intentionally returned to the caller (never persisted) so the page
	 * can display it exactly once. A refresh of the page after creation
	 * will lose it forever - that's the desired behaviour.
	 */
	/**
	 * Ensure the REST API path is on Nova's CSRF allowlist.
	 *
	 * Nova's CSRF protection rejects every POST that lacks a session CSRF
	 * token - which is every token-authenticated API write (create post,
	 * create webhook, disable user, ...). The fix is CodeIgniter's
	 * csrf_exclude_uris, which must be set in application/config/config.php:
	 * that file is loaded at bootstrap BEFORE the CSRF check runs (unlike
	 * application/config/nova.php, which is autoloaded later during controller
	 * init), and it lives in application/ so a Nova upgrade won't overwrite it.
	 *
	 * Idempotent: detects an existing entry (ours or hand-added) and no-ops.
	 * Returns a status the Configure page surfaces:
	 *   present | added | manual (not writable) | unreadable | missing
	 */
	private function _ensureApiCsrfExclusion()
	{
		$path    = APPPATH.'config/config.php';
		$marker  = 'extensions/nova_ext_sim_central/Api';
		$line    = 'extensions/nova_ext_sim_central/Api/.*';

		$result = array('status' => 'manual', 'path' => $path, 'line' => $line);

		if ( ! is_file($path)) {
			$result['status'] = 'missing';
			return $result;
		}
		$contents = @file_get_contents($path);
		if ($contents === false) {
			$result['status'] = 'unreadable';
			return $result;
		}
		if (strpos($contents, $marker) !== false) {
			$result['status'] = 'present';
			return $result;
		}
		if ( ! is_writable($path)) {
			$result['status'] = 'manual';
			return $result;
		}

		$block = "\n/* nova_ext_sim_central: let token-authenticated REST API POST requests"
			."\n   bypass Nova's CSRF check (the API authenticates via the X-API-Key header,"
			."\n   not session cookies). Safe to remove if you disable the REST API. */"
			."\n\$config['csrf_exclude_uris'][] = '".$line."';\n";

		$ok = @file_put_contents($path, $contents.$block, LOCK_EX);
		$result['status'] = ($ok !== false) ? 'added' : 'manual';
		return $result;
	}

	/**
	 * Register the pre_system hook that serves the mobile site at /mobile.
	 *
	 * Nova's extension dispatch can't be reached via a normal route alias
	 * (it reads the raw URI segments), so we add a pre_system hook that
	 * rewrites /mobile -> the extension path before CI parses the URI. The
	 * registration lives in application/config/hooks.php (site file, loaded at
	 * bootstrap, upgrade-safe). Idempotent; status mirrors the CSRF helper:
	 *   present | added | manual (not writable) | missing
	 */
	private function _ensureMobileRoute()
	{
		$path   = APPPATH.'config/hooks.php';
		$marker = 'sim_central_mobile_route';
		$result = array('status' => 'manual', 'path' => $path);

		if ( ! is_file($path)) {
			$result['status'] = 'missing';
			return $result;
		}
		$contents = @file_get_contents($path);
		if ($contents === false) {
			$result['status'] = 'unreadable';
			return $result;
		}
		if (strpos($contents, $marker) !== false) {
			$result['status'] = 'present';
			return $result;
		}
		if ( ! is_writable($path)) {
			$result['status'] = 'manual';
			return $result;
		}

		$block = "\n/* nova_ext_sim_central: serve the mobile site at a clean /mobile URL"
			."\n   (pre_system URI rewrite - safe to remove if you disable Mobile Site). */"
			."\n\$hook['pre_system'][] = array("
			."\n\t'class'    => '',"
			."\n\t'function' => 'sim_central_mobile_route',"
			."\n\t'filename' => 'mobile_route_hook.php',"
			."\n\t'filepath' => 'extensions/nova_ext_sim_central/hooks',"
			."\n);\n";

		$ok = @file_put_contents($path, $contents.$block, LOCK_EX);
		$result['status'] = ($ok !== false) ? 'added' : 'manual';
		return $result;
	}

	private function _createApiToken()
	{
		// Validation lives in ApiAuth so the ACP form and the REST API token
		// endpoints enforce identical rules.
		$result = \nova_ext_sim_central\ApiAuth::validateTokenInput(array(
			'label'      => isset($_POST['label']) ? $_POST['label'] : '',
			'scopes'     => isset($_POST['scopes']) ? $_POST['scopes'] : array(),
			'user_id'    => isset($_POST['user_id']) ? $_POST['user_id'] : '',
			'expires_at' => isset($_POST['expires_at']) ? $_POST['expires_at'] : '',
		));
		if ( ! empty($result['errors'])) {
			return array(array('error', $result['errors'][0]), null);
		}
		$data = $result['data'];

		$token  = \nova_ext_sim_central\ApiAuth::generateToken();
		$userId = $this->session ? (int) $this->session->userdata('userid') : null;

		$this->db->insert('sim_central_api_tokens', array(
			'label'        => $data['label'],
			'token_hash'   => $token['hash'],
			'token_prefix' => $token['prefix'],
			'scopes'       => json_encode($data['scopes']),
			'user_id'      => $data['user_id'],
			'created_by'   => $userId ?: null,
			'created_at'   => date('Y-m-d H:i:s'),
			'expires_at'   => $data['expires_at'],
		));

		return array(array('success', 'Token created. Copy it now - it will not be shown again.'), $token['raw']);
	}

	private function _revokeApiToken()
	{
		$id = isset($_POST['token_id']) ? (int) $_POST['token_id'] : 0;
		if ($id <= 0) {
			return array('error', 'Invalid token id.');
		}
		$row = $this->db->get_where('sim_central_api_tokens', array('id' => $id))->row();
		if ( ! $row) {
			return array('error', 'Token not found.');
		}
		if ( ! empty($row->revoked_at)) {
			return array('success', 'Token was already revoked.');
		}
		$this->db->where('id', $id)->update('sim_central_api_tokens', array(
			'revoked_at' => date('Y-m-d H:i:s'),
		));
		// If this is the Sim Central access token, tell the broker.
		\nova_ext_sim_central\SimCentralAccess::onTokenRevoked($id);
		return array('success', 'Token revoked.');
	}

	private function _deleteApiToken()
	{
		$id = isset($_POST['token_id']) ? (int) $_POST['token_id'] : 0;
		if ($id <= 0) {
			return array('error', 'Invalid token id.');
		}
		// Notify the broker before the row vanishes if this is the Sim
		// Central access token.
		\nova_ext_sim_central\SimCentralAccess::onTokenDeleted($id);
		$this->db->where('id', $id)->delete('sim_central_api_tokens');
		return array('success', 'Token deleted.');
	}

	/**
	 * Persist the Sim Central broker URL + shared secret (and the periodic
	 * status-report toggle) into the suite settings row. The secret is left
	 * unchanged when the field is submitted blank, so an admin re-saving the
	 * URL doesn't have to re-enter it.
	 */
	private function _saveBrokerConfig()
	{
		$json = $this->_loadConfig(dirname(__FILE__).'/../config.json');
		if ( ! isset($json['setting']) || ! is_array($json['setting'])) {
			$json['setting'] = array();
		}

		if (isset($_POST['sim_central_broker_url'])) {
			$json['setting']['sim_central_broker_url'] = trim((string) $_POST['sim_central_broker_url']);
		}
		// Blank secret field = keep the stored secret (avoids clobbering it on
		// an unrelated save). Send a single space to deliberately clear it.
		if (isset($_POST['sim_central_broker_secret'])) {
			$secret = (string) $_POST['sim_central_broker_secret'];
			if (trim($secret) !== '') {
				$json['setting']['sim_central_broker_secret'] = ($secret === ' ') ? '' : $secret;
			}
		}

		$this->_saveConfig(dirname(__FILE__).'/../config.json', $json);
		return array('success', 'Broker configuration saved.');
	}

	// ---------- webhooks helpers ----------

	private function _webhookAvailableEvents()
	{
		return \nova_ext_sim_central\Webhooks::availableEvents();
	}

	private function _webhookNewsTypes()
	{
		return \nova_ext_sim_central\Webhooks::availableNewsTypes();
	}

	private function _webhookAvailableFormats()
	{
		return \nova_ext_sim_central\Webhooks::availableFormats();
	}

	private function _saveWebhook($id)
	{
		// Validation + normalisation lives in the Webhooks library so the ACP
		// form and the REST API share exactly one rule set.
		$result = \nova_ext_sim_central\Webhooks::validateWebhookInput(array(
			'label'                     => isset($_POST['label']) ? $_POST['label'] : '',
			'url'                       => isset($_POST['url']) ? $_POST['url'] : '',
			'format'                    => isset($_POST['format']) ? $_POST['format'] : '',
			'events'                    => isset($_POST['events']) ? $_POST['events'] : array(),
			'enabled'                   => ! empty($_POST['enabled']) ? 1 : 0,
			'news_types'                => isset($_POST['news_types']) ? $_POST['news_types'] : 'public',
			'mention_role_id'           => isset($_POST['mention_role_id']) ? $_POST['mention_role_id'] : '',
			'mention_role_events'       => isset($_POST['mention_role_events']) ? $_POST['mention_role_events'] : array(),
			'template_title'            => isset($_POST['template_title']) ? $_POST['template_title'] : '',
			'template_description'      => isset($_POST['template_description']) ? $_POST['template_description'] : '',
			'template_log_title'        => isset($_POST['template_log_title']) ? $_POST['template_log_title'] : '',
			'template_log_description'  => isset($_POST['template_log_description']) ? $_POST['template_log_description'] : '',
			'template_news_title'       => isset($_POST['template_news_title']) ? $_POST['template_news_title'] : '',
			'template_news_description' => isset($_POST['template_news_description']) ? $_POST['template_news_description'] : '',
		));

		if ( ! empty($result['errors'])) {
			return array('error', $result['errors'][0]);
		}
		$data = $result['data'];

		if ($id === null) {
			$data['created_at'] = date('Y-m-d H:i:s');
			$data['created_by'] = $this->session ? (int) $this->session->userdata('userid') : null;
			$this->db->insert('sim_central_webhooks', $data);
			return array('success', 'Webhook created.');
		}

		$this->db->where('id', $id)->update('sim_central_webhooks', $data);
		return array('success', 'Webhook updated.');
	}

	private function _deleteWebhook()
	{
		$id = (int) ($_POST['id'] ?? 0);
		if ($id <= 0) { return array('error', 'Invalid webhook id.'); }
		$this->db->where('id', $id)->delete('sim_central_webhooks');
		return array('success', 'Webhook deleted.');
	}

	private function _toggleWebhook()
	{
		$id = (int) ($_POST['id'] ?? 0);
		if ($id <= 0) { return array('error', 'Invalid webhook id.'); }
		$row = $this->db->get_where('sim_central_webhooks', array('id' => $id))->row();
		if ( ! $row) { return array('error', 'Webhook not found.'); }
		$this->db->where('id', $id)->update('sim_central_webhooks', array('enabled' => $row->enabled ? 0 : 1));
		return array('success', $row->enabled ? 'Webhook disabled.' : 'Webhook enabled.');
	}

	// ---------- migrations delegates ----------
	// Registry, database setup, and the shim writer live in
	// libraries/Migrations.php so the post-update auto-runner can use
	// them outside this controller. These delegates keep every existing
	// call site (and the views) unchanged.

	private function _featureRegistry() { return \nova_ext_sim_central\Migrations::registry(); }
	private function _knownStandaloneMarkers() { return \nova_ext_sim_central\Migrations::knownStandaloneMarkers(); }
	private function _missingColumns($key) { return \nova_ext_sim_central\Migrations::missingColumns($key); }
	private function _staleColumns($key) { return \nova_ext_sim_central\Migrations::staleColumns($key); }
	private function _missingTables($key) { return \nova_ext_sim_central\Migrations::missingTables($key); }
	private function _missingIndexes($key) { return \nova_ext_sim_central\Migrations::missingIndexes($key); }
	private function _setupDatabase($key) { return \nova_ext_sim_central\Migrations::setupDatabase($key); }
	private function _featureCombinedState($key) { return \nova_ext_sim_central\Migrations::featureCombinedState($key); }
	private function _shimState($key, $tag) { return \nova_ext_sim_central\Migrations::shimState($key, $tag); }
	private function _writeAllShims($key) { return \nova_ext_sim_central\Migrations::writeAllShims($key); }
	private function _writeShim($key, $tag) { return \nova_ext_sim_central\Migrations::writeShim($key, $tag); }
	private function _removeAllShims($key, $futureEnabled) { return \nova_ext_sim_central\Migrations::removeAllShims($key, $futureEnabled); }
}
