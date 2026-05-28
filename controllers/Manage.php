<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

require_once MODPATH.'core/libraries/Nova_controller_admin.php';

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
			}
		}

		$f               = $this->features['rest_api'];
		$data            = array();
		$data['title']   = 'Sim Central Suite - '.$f['name'];
		$data['feature'] = $f;
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

		$this->_regions['title']  .= $f['name'];
		$this->_regions['content'] = $this->extension['nova_ext_sim_central']
			->view('rest_api', $this->skin, 'admin', $data);

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
		$editingId  = (int) $this->uri->segment(5);

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
		$data['default_title_template']       = \nova_ext_sim_central\Webhooks::DEFAULT_TITLE_TEMPLATE;
		$data['default_description_template'] = \nova_ext_sim_central\Webhooks::DEFAULT_DESCRIPTION_TEMPLATE;
		$data['webhooks'] = $dbReady
			? $this->db->order_by('enabled', 'DESC')->order_by('created_at', 'DESC')->get('sim_central_webhooks')->result()
			: array();
		$data['edit_row'] = null;
		if ($dbReady && $editingId > 0) {
			$data['edit_row'] = $this->db->get_where('sim_central_webhooks', array('id' => $editingId))->row();
		}

		$this->_regions['title']  .= $f['name'];
		$this->_regions['content'] = $this->extension['nova_ext_sim_central']
			->view('webhooks', $this->skin, 'admin', $data);

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
		$this->_regions['content'] = $this->extension['nova_ext_sim_central']
			->view('api_explorer', $this->skin, 'admin', $data);

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
		$this->_regions['content'] = $this->extension['nova_ext_sim_central']
			->view('discord_auth', $this->skin, 'admin', $data);

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
		$this->_regions['content'] = $this->extension['nova_ext_sim_central']
			->view('content_filter', $this->skin, 'admin', $data);

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
		$this->_regions['content'] = $this->extension['nova_ext_sim_central']
			->view('ordered_mission_posts', $this->skin, 'admin', $data);

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
		$this->_regions['content'] = $this->extension['nova_ext_sim_central']
			->view('summary', $this->skin, 'admin', $data);

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
		$this->_regions['content']    = $this->extension['nova_ext_sim_central']
			->view('url_parser', $this->skin, 'admin', $data);

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
		$this->_regions['content'] = $this->extension['nova_ext_sim_central']
			->view('url_parser_create', $this->skin, 'admin', $data);

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
		$this->_regions['content'] = $this->extension['nova_ext_sim_central']
			->view('url_parser_edit', $this->skin, 'admin', $data);

		Template::assign($this->_regions);
		Template::render();
	}

	public function anti_spam()
	{
		Auth::check_access('site/settings');

		$submit = isset($_POST['submit']) ? strtolower($_POST['submit']) : '';
		if ($this->uri->segment(4) === 'delete' && $submit === 'submit') {
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
		$this->_regions['content']    = $this->extension['nova_ext_sim_central']
			->view('anti_spam', $this->skin, 'admin', $data);

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
		$this->_regions['content']    = $this->extension['nova_ext_sim_central']
			->view('anti_spam_create', $this->skin, 'admin', $data);

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
		$this->_regions['content']    = $this->extension['nova_ext_sim_central']
			->view('anti_spam_edit', $this->skin, 'admin', $data);

		Template::assign($this->_regions);
		Template::render();
	}

	// ---------- feature registry ----------

	private function _featureRegistry()
	{
		return array(
			'display_name' => array(
				'name'        => 'Display Name',
				'summary'     => 'Use a custom display name on the manifest in place of First / Last / Suffix when one is set.',
				'standalone'  => 'nova_ext_display_name',
				'requires_db' => array(
					'characters' => array(
						'display_name' => 'VARCHAR(255) DEFAULT NULL',
					),
				),
				'shims' => array(
					'display_name' => array(
						'file'                  => APPPATH.'models/Characters_model.php',
						'txt'                   => dirname(__FILE__).'/../character.txt',
						'tag'                   => 'display_name',
						'method'                => 'get_character_name',
						'label'                 => 'Display name model code',
						'standalone_marker_ns'  => 'nova_ext_display_name',
						'standalone_marker_tag' => 'character',
					),
				),
				'config_route' => 'extensions/nova_ext_sim_central/Manage/display_name',
			),
			'anti_spam' => array(
				'name'        => 'Anti Spam Questions',
				'summary'     => 'Adds a random security question to the join and contact forms to keep bots out.',
				'standalone'  => 'nova_ext_anti_spam_questions',
				'requires_db' => array(),
				'shims'       => array(
					'contact' => array(
						'file'                  => APPPATH.'controllers/Main.php',
						'txt'                   => dirname(__FILE__).'/../main.txt',
						'tag'                   => 'contact',
						'method'                => 'contact',
						'label'                 => 'Contact form code',
						'standalone_marker_ns'  => 'nova_ext_anti_spam_questions',
						'standalone_marker_tag' => 'contact',
					),
					'join' => array(
						'file'                  => APPPATH.'controllers/Main.php',
						'txt'                   => dirname(__FILE__).'/../main.txt',
						'tag'                   => 'join',
						'method'                => 'join',
						'label'                 => 'Join form code',
						'standalone_marker_ns'  => 'nova_ext_anti_spam_questions',
						'standalone_marker_tag' => 'join',
					),
				),
				'config_route' => 'extensions/nova_ext_sim_central/Manage/anti_spam',
			),
			'summary' => array(
				'name'        => 'Mission Post Summary',
				'summary'     => 'Adds a short TL;DR summary field to long mission posts. Shown on the post view and (optionally) in post emails and the RSS feed.',
				'standalone'  => 'nova_ext_mission_post_summary',
				'requires_db' => array(
					'posts' => array(
						'nova_ext_mission_post_summary' => 'TEXT NULL DEFAULT NULL',
					),
					'missions' => array(
						'mission_ext_mission_post_summary_enable' => 'INTEGER DEFAULT 0',
					),
				),
				'shims' => array(
					'feed' => array(
						'file'                  => APPPATH.'controllers/Feed.php',
						'txt'                   => dirname(__FILE__).'/../feed.txt',
						'tag'                   => 'feed',
						'method'                => 'posts',
						'label'                 => 'RSS feed code',
						'standalone_marker_ns'  => 'nova_ext_mission_post_summary',
						'standalone_marker_tag' => 'feed',
					),
				),
				'config_route' => 'extensions/nova_ext_sim_central/Manage/summary',
			),
			'url_parser' => array(
				'name'        => 'URL Parser',
				'summary'     => 'Define short tags that expand into anchors across post content, news, and other rendered pages. Write [docs|getting-started] and it becomes a link.',
				'standalone'  => 'nova_ext_url_parser',
				'requires_tables' => array(
					'tag' => "CREATE TABLE IF NOT EXISTS `{prefix}tag` (`id` int(11) NOT NULL AUTO_INCREMENT, `title` varchar(255) DEFAULT NULL, `url` text DEFAULT NULL, `post_url` varchar(255) DEFAULT NULL, `is_new_tab` int(11) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci",
				),
				'requires_db' => array(),
				'shims'       => array(),
				'config_route' => 'extensions/nova_ext_sim_central/Manage/url_parser',
			),
			'discord_auth' => array(
				'name'        => 'Discord Sign-In',
				'summary'     => 'Lets users sign in to the sim with their Discord account via the Sim Central Broker. Existing users can link their Discord; new users can sign up with Discord (when auto-create is on).',
				'standalone'  => 'nova_ext_discord_account_confirmation',
				'requires_db' => array(
					'users' => array(
						'nova_ext_discord_auth_id'             => 'VARCHAR(32) NULL',
						'nova_ext_discord_auth_username'       => 'VARCHAR(100) NULL',
						'nova_ext_discord_auth_avatar'         => 'VARCHAR(64) NULL',
						'nova_ext_discord_auth_email_verified' => 'TINYINT NULL',
						'nova_ext_discord_auth_linked_at'      => 'INT NULL',
					),
				),
				'requires_indexes' => array(
					'users' => array(
						'nova_ext_discord_auth_id_unique' => '(`nova_ext_discord_auth_id`)',
					),
				),
				'shims'        => array(),
				'config_route' => 'extensions/nova_ext_sim_central/Manage/discord_auth',
			),
			'content_filter' => array(
				'name'        => 'Content Filter',
				'summary'     => 'Age-gates mission post bodies on the public site and in the RSS feed for sims that allow explicit sexual content or violence (rating 3). Per-post toggle lets writers mark individual posts as safe.',
				'standalone'  => 'nova_ext_content_filter',
				'requires_db' => array(
					'posts' => array(
						'nova_ext_content_filter_age_gated' => 'TINYINT NOT NULL DEFAULT 1',
					),
				),
				'shims'       => array(),
				'config_route' => 'extensions/nova_ext_sim_central/Manage/content_filter',
			),
			'rest_api' => array(
				'name'        => 'REST API',
				'summary'     => 'Exposes a read-only HTTP API for external integrations (n8n, scripts, dashboards). Tokens are managed under Configure and authenticate via the X-API-Key header.',
				'standalone'  => null,
				'requires_tables' => array(
					'sim_central_api_tokens' => "CREATE TABLE IF NOT EXISTS `{prefix}sim_central_api_tokens` (`id` int(11) NOT NULL AUTO_INCREMENT, `label` varchar(120) NOT NULL, `token_hash` char(64) NOT NULL, `token_prefix` varchar(16) NOT NULL, `scopes` text NOT NULL, `created_by` int(11) DEFAULT NULL, `created_at` datetime NOT NULL, `last_used_at` datetime DEFAULT NULL, `expires_at` datetime DEFAULT NULL, `revoked_at` datetime DEFAULT NULL, `rate_count` int(11) NOT NULL DEFAULT 0, `rate_window_at` datetime DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `token_hash` (`token_hash`)) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci",
				),
				'requires_db' => array(),
				'shims'       => array(),
				'config_route' => 'extensions/nova_ext_sim_central/Manage/rest_api',
			),
			'webhooks' => array(
				'name'        => 'Event Webhooks',
				'summary'     => 'Fire HTTP webhooks when posts are saved or activated. Discord-formatted (embed with @mentions of linked authors) or generic JSON (for n8n etc.). Multiple webhooks per event; per-token rate isolation; admin-managed under Configure.',
				'standalone'  => null,
				'requires_tables' => array(
					'sim_central_webhooks' => "CREATE TABLE IF NOT EXISTS `{prefix}sim_central_webhooks` (`id` int(11) NOT NULL AUTO_INCREMENT, `label` varchar(120) NOT NULL, `url` text NOT NULL, `format` varchar(20) NOT NULL, `events` text NOT NULL, `enabled` tinyint(1) NOT NULL DEFAULT 1, `template_title` text DEFAULT NULL, `template_description` text DEFAULT NULL, `created_by` int(11) DEFAULT NULL, `created_at` datetime NOT NULL, `last_fired_at` datetime DEFAULT NULL, `last_status` int(11) DEFAULT NULL, `last_error` text DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci",
				),
				'requires_db' => array(),
				'shims' => array(
					'webhooks_create' => array(
						'file'                  => APPPATH.'models/Posts_model.php',
						'txt'                   => dirname(__FILE__).'/../posts_model_create.txt',
						'tag'                   => 'webhooks_create',
						'method'                => 'create_mission_entry',
						'label'                 => 'Post create webhook hook',
						'standalone_marker_ns'  => 'nova_ext_sim_central',
						'standalone_marker_tag' => 'webhooks_create',
					),
					'webhooks_update' => array(
						'file'                  => APPPATH.'models/Posts_model.php',
						'txt'                   => dirname(__FILE__).'/../posts_model_update.txt',
						'tag'                   => 'webhooks_update',
						'method'                => 'update_post',
						'label'                 => 'Post update webhook hook',
						'standalone_marker_ns'  => 'nova_ext_sim_central',
						'standalone_marker_tag' => 'webhooks_update',
					),
				),
				'config_route' => 'extensions/nova_ext_sim_central/Manage/webhooks',
			),
			'ordered_mission_posts' => array(
				'name'        => 'Ordered Mission Posts',
				'summary'     => 'Order mission posts by Day/Time, Date/Time, or Stardate(decimal)/Time, and optionally number them. Replaces chronological_mission_posts (legacy mode keeps old Day/Time data usable).',
				'standalone'  => 'nova_ext_ordered_mission_posts',
				'requires_db' => array(
					'posts' => array(
						'nova_ext_ordered_post_day'      => 'INTEGER NOT NULL DEFAULT 1',
						'nova_ext_ordered_post_time'     => "VARCHAR(4) NOT NULL DEFAULT '0000'",
						'nova_ext_ordered_post_date'     => 'VARCHAR(255) DEFAULT NULL',
						'nova_ext_ordered_post_stardate' => 'VARCHAR(255) DEFAULT NULL',
					),
					'missions' => array(
						'mission_ext_ordered_config_setting'       => 'VARCHAR(255) DEFAULT NULL',
						'mission_ext_ordered_post_numbering'       => 'INTEGER NOT NULL DEFAULT 0',
						'mission_ext_ordered_default_mission_date' => 'VARCHAR(255) DEFAULT NULL',
						'mission_ext_ordered_default_stardate'     => 'VARCHAR(255) DEFAULT NULL',
						'mission_ext_ordered_legacy_mode'          => 'INTEGER NOT NULL DEFAULT 0',
						'mission_ext_ordered_is_new_record'        => 'INTEGER DEFAULT 0',
					),
				),
				'requires_indexes' => array(
					'posts' => array(
						'post_ordered_mission_post' => '(`nova_ext_ordered_post_day`, `nova_ext_ordered_post_date`, `nova_ext_ordered_post_stardate`, `nova_ext_ordered_post_time`)',
					),
					'missions' => array(
						'post_ordered_mission' => '(`mission_ext_ordered_config_setting`, `mission_ext_ordered_post_numbering`, `mission_ext_ordered_default_mission_date`, `mission_ext_ordered_default_stardate`, `mission_ext_ordered_legacy_mode`, `mission_ext_ordered_is_new_record`)',
					),
				),
				'shims' => array(
					'email' => array(
						'file'                  => APPPATH.'controllers/Write.php',
						'txt'                   => dirname(__FILE__).'/../write.txt',
						'tag'                   => 'email',
						'method'                => '_email',
						'label'                 => 'Post email code',
						'standalone_marker_ns'  => 'nova_ext_ordered_mission_posts',
						'standalone_marker_tag' => 'email',
					),
					'feed' => array(
						'file'                  => APPPATH.'controllers/Feed.php',
						'txt'                   => dirname(__FILE__).'/../feed.txt',
						'tag'                   => 'feed',
						'method'                => 'posts',
						'label'                 => 'RSS feed code',
						'standalone_marker_ns'  => 'nova_ext_ordered_mission_posts',
						'standalone_marker_tag' => 'feed',
					),
				),
				'config_route' => 'extensions/nova_ext_sim_central/Manage/ordered_mission_posts',
			),
		);
	}

	/**
	 * Every (namespace, tag) pair that any feature lists as a standalone
	 * marker. Used so the writer can recognise an in-place standalone shim
	 * even when it isn't the standalone for the feature being installed
	 * (e.g. summary trying to take Feed.php while ordered's shim is there).
	 */
	private function _knownStandaloneMarkers()
	{
		$markers = array();
		foreach ($this->features as $f) {
			if (empty($f['shims'])) {
				continue;
			}
			foreach ($f['shims'] as $shim) {
				if (empty($shim['standalone_marker_ns']) || empty($shim['standalone_marker_tag'])) {
					continue;
				}
				$key = $shim['standalone_marker_ns'].':'.$shim['standalone_marker_tag'];
				$markers[$key] = array(
					'ns'  => $shim['standalone_marker_ns'],
					'tag' => $shim['standalone_marker_tag'],
				);
			}
		}
		return array_values($markers);
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
			$f['db_ready']            = empty($f['missing_columns']) && empty($f['missing_tables']) && empty($f['missing_indexes']);
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
		$this->_regions['content'] = $this->extension['nova_ext_sim_central']
			->view($key, $this->skin, 'admin', $data);

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
	 * v1 is read-only. Write scopes (posts:write etc.) will join this list
	 * once the corresponding endpoints exist and the "act as" author setting
	 * is added.
	 */
	private function _apiAvailableScopes()
	{
		return array(
			'posts:read'      => 'Read mission posts (list + view).',
			'characters:read' => 'Read characters (list + view).',
			'missions:read'   => 'Read missions (list + view).',
		);
	}

	/**
	 * Create a new API token from POSTed form data.
	 *
	 * Returns array($result_tuple, $raw_token_or_null). The raw token is
	 * intentionally returned to the caller (never persisted) so the page
	 * can display it exactly once. A refresh of the page after creation
	 * will lose it forever - that's the desired behaviour.
	 */
	private function _createApiToken()
	{
		$label = isset($_POST['label']) ? trim((string) $_POST['label']) : '';
		if ($label === '') {
			return array(array('error', 'Label is required.'), null);
		}
		if (strlen($label) > 120) {
			return array(array('error', 'Label is too long (max 120 chars).'), null);
		}

		$postedScopes = isset($_POST['scopes']) && is_array($_POST['scopes']) ? $_POST['scopes'] : array();
		$available    = $this->_apiAvailableScopes();
		$scopes       = array();
		foreach ($postedScopes as $s) {
			if (isset($available[$s])) {
				$scopes[] = $s;
			}
		}
		if (empty($scopes)) {
			return array(array('error', 'Select at least one scope.'), null);
		}

		$expiresAt = null;
		if ( ! empty($_POST['expires_at'])) {
			$ts = strtotime((string) $_POST['expires_at']);
			if ($ts === false || $ts < time()) {
				return array(array('error', 'Expiry must be a valid future date/time.'), null);
			}
			$expiresAt = date('Y-m-d H:i:s', $ts);
		}

		$token = \nova_ext_sim_central\ApiAuth::generateToken();
		$userId = $this->session ? (int) $this->session->userdata('userid') : null;

		$this->db->insert('sim_central_api_tokens', array(
			'label'        => $label,
			'token_hash'   => $token['hash'],
			'token_prefix' => $token['prefix'],
			'scopes'       => json_encode(array_values($scopes)),
			'created_by'   => $userId ?: null,
			'created_at'   => date('Y-m-d H:i:s'),
			'expires_at'   => $expiresAt,
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
		return array('success', 'Token revoked.');
	}

	private function _deleteApiToken()
	{
		$id = isset($_POST['token_id']) ? (int) $_POST['token_id'] : 0;
		if ($id <= 0) {
			return array('error', 'Invalid token id.');
		}
		$this->db->where('id', $id)->delete('sim_central_api_tokens');
		return array('success', 'Token deleted.');
	}

	// ---------- webhooks helpers ----------

	private function _webhookAvailableEvents()
	{
		return array(
			'post.saved'  => 'Fires when a draft post is created or saved (status = saved). For nudging co-authors that a draft needs another look.',
			'post.posted' => 'Fires when a post transitions to activated (i.e. publicly posted). The main announcement event.',
		);
	}

	private function _webhookAvailableFormats()
	{
		return array(
			'discord' => 'Discord webhook URL. Fires a formatted embed (with @mentions of authors who have linked Discord).',
			'generic' => 'Generic JSON. Sends a structured payload suitable for n8n, custom scripts, or any tool that consumes raw JSON.',
		);
	}

	private function _saveWebhook($id)
	{
		$label = isset($_POST['label']) ? trim((string) $_POST['label']) : '';
		$url   = isset($_POST['url'])   ? trim((string) $_POST['url'])   : '';
		$format= isset($_POST['format'])? (string) $_POST['format']      : '';
		$events= isset($_POST['events']) && is_array($_POST['events']) ? $_POST['events'] : array();
		$enabled = ! empty($_POST['enabled']) ? 1 : 0;
		$tplTitle = isset($_POST['template_title']) ? trim((string) $_POST['template_title']) : '';
		$tplDesc  = isset($_POST['template_description']) ? trim((string) $_POST['template_description']) : '';

		if ($label === '') { return array('error', 'Label is required.'); }
		if (strlen($label) > 120) { return array('error', 'Label is too long (max 120 chars).'); }
		if ( ! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
			return array('error', 'URL must be a valid http(s) URL.');
		}
		$availableFormats = $this->_webhookAvailableFormats();
		if ( ! isset($availableFormats[$format])) {
			return array('error', 'Unknown format.');
		}
		$availableEvents = $this->_webhookAvailableEvents();
		$cleanEvents = array();
		foreach ($events as $ev) {
			if (isset($availableEvents[$ev])) { $cleanEvents[] = $ev; }
		}
		if (empty($cleanEvents)) { return array('error', 'Select at least one event.'); }

		$data = array(
			'label'                => $label,
			'url'                  => $url,
			'format'               => $format,
			'events'               => json_encode(array_values($cleanEvents)),
			'enabled'              => $enabled,
			'template_title'       => $tplTitle !== '' ? $tplTitle : null,
			'template_description' => $tplDesc  !== '' ? $tplDesc  : null,
		);

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

	private function _missingColumns($key)
	{
		$f = $this->features[$key];
		if ( ! isset($f['requires_db'])) {
			return array();
		}
		$prefix  = $this->db->dbprefix;
		$missing = array();
		foreach ($f['requires_db'] as $table => $columns) {
			$existing = $this->db->list_fields($prefix.$table);
			foreach ($columns as $column => $def) {
				if ( ! in_array($column, $existing, true)) {
					$missing[] = $table.'.'.$column;
				}
			}
		}
		return $missing;
	}

	private function _missingTables($key)
	{
		$f = $this->features[$key];
		if (empty($f['requires_tables'])) {
			return array();
		}
		$prefix  = $this->db->dbprefix;
		$missing = array();
		foreach ($f['requires_tables'] as $table => $sql) {
			if ( ! $this->db->table_exists($prefix.$table) && ! $this->db->table_exists($table)) {
				$missing[] = $table;
			}
		}
		return $missing;
	}

	private function _missingIndexes($key)
	{
		$f = $this->features[$key];
		if (empty($f['requires_indexes'])) {
			return array();
		}
		$prefix  = $this->db->dbprefix;
		$missing = array();
		foreach ($f['requires_indexes'] as $table => $indexes) {
			foreach ($indexes as $indexName => $columnsSql) {
				if ( ! $this->_indexExists($prefix.$table, $indexName)) {
					$missing[] = $table.'.'.$indexName;
				}
			}
		}
		return $missing;
	}

	private function _indexExists($table, $indexName)
	{
		$result = $this->db->query('SHOW INDEX FROM `'.$table.'`');
		foreach ($result->result() as $row) {
			if ($row->Key_name === $indexName) {
				return true;
			}
		}
		return false;
	}

	private function _setupDatabase($key)
	{
		$f = $this->features[$key];
		if (empty($f['requires_db']) && empty($f['requires_tables']) && empty($f['requires_indexes'])) {
			return array('success', 'Nothing to do - this feature has no database setup.');
		}
		$prefix       = $this->db->dbprefix;
		$tablesAdded  = 0;
		$columnsAdded = 0;
		$indexesAdded = 0;

		// Create missing tables first so any column adds below can land on them.
		if ( ! empty($f['requires_tables'])) {
			foreach ($f['requires_tables'] as $table => $sql) {
				if ($this->db->table_exists($prefix.$table) || $this->db->table_exists($table)) {
					continue;
				}
				$this->db->query(str_replace('{prefix}', $prefix, $sql));
				$tablesAdded++;
			}
		}

		if ( ! empty($f['requires_db'])) {
			foreach ($f['requires_db'] as $table => $columns) {
				$existing = $this->db->list_fields($prefix.$table);
				foreach ($columns as $column => $def) {
					if (in_array($column, $existing, true)) {
						continue;
					}
					$sql = 'ALTER TABLE `'.$prefix.$table.'` ADD COLUMN `'.$column.'` '.$def;
					$this->db->query($sql);
					$columnsAdded++;
				}
			}
		}

		// Indexes go last so they always land on already-existing columns.
		if ( ! empty($f['requires_indexes'])) {
			foreach ($f['requires_indexes'] as $table => $indexes) {
				foreach ($indexes as $indexName => $columnsSql) {
					if ($this->_indexExists($prefix.$table, $indexName)) {
						continue;
					}
					$this->db->query('CREATE INDEX `'.$indexName.'` ON `'.$prefix.$table.'` '.$columnsSql);
					$indexesAdded++;
				}
			}
		}

		if ($tablesAdded === 0 && $columnsAdded === 0 && $indexesAdded === 0) {
			return array('success', 'Database is already fully set up - nothing to add.');
		}
		$parts = array();
		if ($tablesAdded > 0)  $parts[] = $tablesAdded.' table(s)';
		if ($columnsAdded > 0) $parts[] = $columnsAdded.' column(s)';
		if ($indexesAdded > 0) $parts[] = $indexesAdded.' index(es)';
		return array('success', 'Database setup complete. Added '.implode(', ', $parts).'.');
	}

	// ---------- shim writer (per-shim primitives + per-feature aggregates) ----------

	/**
	 * Worst-of state across every shim in a feature, ranked by:
	 * missing_file > standalone_shim > legacy > outdated > missing > current.
	 */
	private function _featureCombinedState($key)
	{
		$priority = array(
			'missing_file'    => 5,
			'standalone_shim' => 4,
			'legacy'          => 3,
			'outdated'        => 2,
			'missing'         => 1,
			'current'         => 0,
		);
		$worst = 'current';
		foreach (array_keys($this->features[$key]['shims']) as $tag) {
			$state = $this->_shimState($key, $tag);
			if ( ! isset($priority[$state])) {
				continue;
			}
			if ($priority[$state] > $priority[$worst]) {
				$worst = $state;
			}
		}
		return $worst;
	}

	private function _shimState($key, $tag)
	{
		$shim = $this->features[$key]['shims'][$tag];

		if ( ! file_exists($shim['file'])) {
			return 'missing_file';
		}

		$file = file_get_contents($shim['file']);
		$txt  = file_exists($shim['txt']) ? file_get_contents($shim['txt']) : '';

		$installedVersion = $this->_blockVersion($file, $shim['tag']);
		$currentVersion   = $this->_blockVersion($txt,  $shim['tag']);

		if ($installedVersion !== null) {
			return ($installedVersion === $currentVersion) ? 'current' : 'outdated';
		}

		// Cross-feature: any registered standalone marker counts as
		// a take-over candidate, not just the one this feature lists.
		foreach ($this->_knownStandaloneMarkers() as $m) {
			$standalonePattern = '/'
				.preg_quote($m['ns'], '/').':'.preg_quote($m['tag'], '/')
				.' v\d+ START/';
			if (preg_match($standalonePattern, $file)) {
				return 'standalone_shim';
			}
		}

		if (preg_match('/function\s+'.preg_quote($shim['method'], '/').'\s*\(/', $file)) {
			return 'legacy';
		}

		return 'missing';
	}

	private function _blockVersion($content, $tag)
	{
		if (preg_match('/nova_ext_sim_central:'.preg_quote($tag, '/').' v(\d+) START/', $content, $match)) {
			return (int) $match[1];
		}
		return null;
	}

	/**
	 * Extract a single tagged managed block from a .txt file that may contain
	 * more than one (e.g. main.txt holds both the contact and join shims).
	 */
	private function _readBlockFromTxt($txtPath, $tag)
	{
		if ( ! file_exists($txtPath)) {
			return null;
		}
		$content = file_get_contents($txtPath);
		$pattern = '/[ \t]*\/\*\s*nova_ext_sim_central:'.preg_quote($tag, '/')
			.' v\d+ START.*?nova_ext_sim_central:'.preg_quote($tag, '/').' END\s*\*\//s';
		if (preg_match($pattern, $content, $match)) {
			return rtrim($match[0], "\r\n");
		}
		return null;
	}

	private function _writeAllShims($key)
	{
		$shims  = $this->features[$key]['shims'];
		$wrote  = 0;
		$errors = array();
		foreach (array_keys($shims) as $tag) {
			$result = $this->_writeShim($key, $tag);
			if ($result[0] === 'error') {
				$errors[] = $result[1];
			} elseif (strpos($result[1], 'already up to date') === false) {
				$wrote++;
			}
		}
		if ( ! empty($errors)) {
			return array('error', implode(' ', $errors));
		}
		if ($wrote === 0) {
			return array('success', $this->features[$key]['name'].' shims are already up to date.');
		}
		return array('success', $this->features[$key]['name'].' shims updated successfully.');
	}

	private function _writeShim($key, $tag)
	{
		$shim  = $this->features[$key]['shims'][$tag];
		$state = $this->_shimState($key, $tag);

		if ($state === 'current') {
			return array('success', $shim['label'].' is already up to date.');
		}
		if ($state === 'missing_file') {
			return array('error', 'Could not find '.$shim['file'].'.');
		}

		$block = $this->_readBlockFromTxt($shim['txt'], $shim['tag']);
		if ($block === null) {
			return array('error', 'Cannot find the '.$shim['tag'].' block in '.basename($shim['txt']).'.');
		}

		$file = file_get_contents($shim['file']);

		if ($state === 'outdated') {
			$pattern = '/[ \t]*\/\*\s*nova_ext_sim_central:'.preg_quote($shim['tag'], '/')
				.' v\d+ START.*?nova_ext_sim_central:'.preg_quote($shim['tag'], '/').' END\s*\*\//s';
			$new = preg_replace($pattern, $block, $file, 1, $count);
			if ($count !== 1) {
				return array('error', 'Could not locate the managed '.$shim['tag'].' block in '.basename($shim['file']).'.');
			}
			$file = $new;
		} elseif ($state === 'legacy') {
			$span = $this->_findUnmarkedMethodSpan($file, $shim['method']);
			if ($span === null) {
				return array('error', 'Could not parse the existing '.$shim['method'].'() method in '.basename($shim['file']).'.');
			}
			$file = substr($file, 0, $span[0]).$block."\n".substr($file, $span[1]);
		} elseif ($state === 'standalone_shim') {
			// Strip every known standalone marker block from the target file
			// (not just this feature's standalone) so a take-over from ANY
			// previous owner cleans up cleanly.
			foreach ($this->_knownStandaloneMarkers() as $m) {
				$standalonePattern = '/[ \t]*\/\*\s*'
					.preg_quote($m['ns'], '/').':'.preg_quote($m['tag'], '/')
					.' v\d+ START.*?'
					.preg_quote($m['ns'], '/').':'.preg_quote($m['tag'], '/')
					.' END\s*\*\/\n?/s';
				$file = preg_replace($standalonePattern, '', $file);
			}
			$pos = strrpos($file, '}');
			if ($pos === false) {
				return array('error', basename($shim['file']).' is not in the expected format.');
			}
			$file = rtrim(substr($file, 0, $pos))."\n\n".$block."\n}\n";
		} else {
			$pos = strrpos($file, '}');
			if ($pos === false) {
				return array('error', basename($shim['file']).' is not in the expected format.');
			}
			$file = rtrim(substr($file, 0, $pos))."\n\n".$block."\n}\n";
		}

		file_put_contents($shim['file'], $file);
		return array('success', $shim['label'].' updated successfully.');
	}

	private function _removeAllShims($key, $futureEnabled)
	{
		foreach (array_keys($this->features[$key]['shims']) as $tag) {
			$this->_removeShim($key, $tag, $futureEnabled);
		}
	}

	private function _removeShim($key, $tag, $futureEnabled)
	{
		$shim = $this->features[$key]['shims'][$tag];

		// Refcount: if any other still-enabled feature has a shim with the
		// same file + tag, leave it alone - that feature still needs it.
		foreach ($this->features as $otherKey => $f) {
			if ($otherKey === $key || empty($futureEnabled[$otherKey]) || empty($f['shims'])) {
				continue;
			}
			foreach ($f['shims'] as $otherShim) {
				if ($otherShim['file'] === $shim['file'] && $otherShim['tag'] === $shim['tag']) {
					return;
				}
			}
		}

		if ( ! file_exists($shim['file'])) {
			return;
		}
		$file = file_get_contents($shim['file']);
		$pattern = '/[ \t]*\/\*\s*nova_ext_sim_central:'.preg_quote($shim['tag'], '/')
			.' v\d+ START.*?nova_ext_sim_central:'.preg_quote($shim['tag'], '/').' END\s*\*\/\n?/s';
		$new = preg_replace($pattern, '', $file);
		if ($new !== null && $new !== $file) {
			$new = preg_replace('/\n{3,}/', "\n\n", $new);
			file_put_contents($shim['file'], $new);
		}
	}

	/**
	 * Locate the byte span of an unmarked $methodName declaration in $content.
	 * Returns array($start, $end) (end exclusive, includes the trailing newline
	 * if present), or null if the method can't be cleanly located. A minimal
	 * lexer is used so braces, comments, and string literals don't fool the
	 * counter.
	 */
	private function _findUnmarkedMethodSpan($content, $methodName)
	{
		$len = strlen($content);
		$state = 'normal';
		$functionPositions = array();
		$i = 0;

		while ($i < $len) {
			$c = $content[$i];
			$next = ($i + 1 < $len) ? $content[$i + 1] : '';

			if ($state === 'normal') {
				if ($c === "'") { $state = 'single'; $i++; continue; }
				if ($c === '"') { $state = 'double'; $i++; continue; }
				if ($c === '/' && $next === '/') { $state = 'line_comment'; $i += 2; continue; }
				if ($c === '/' && $next === '*') { $state = 'block_comment'; $i += 2; continue; }
				if ($c === 'f'
					&& substr($content, $i, 8) === 'function'
					&& ($i === 0 || ! self::_isIdentChar($content[$i - 1]))
					&& ($i + 8 >= $len || ! self::_isIdentChar($content[$i + 8]))) {
					$functionPositions[] = $i;
					$i += 8;
					continue;
				}
			} elseif ($state === 'single') {
				if ($c === '\\') { $i += 2; continue; }
				if ($c === "'") $state = 'normal';
			} elseif ($state === 'double') {
				if ($c === '\\') { $i += 2; continue; }
				if ($c === '"') $state = 'normal';
			} elseif ($state === 'line_comment') {
				if ($c === "\n") $state = 'normal';
			} elseif ($state === 'block_comment') {
				if ($c === '*' && $next === '/') { $state = 'normal'; $i += 2; continue; }
			}
			$i++;
		}

		foreach ($functionPositions as $fnPos) {
			$p = $fnPos + 8;
			while ($p < $len && ctype_space($content[$p])) {
				$p++;
			}
			$nameLen = strlen($methodName);
			if ($p + $nameLen > $len) continue;
			if (substr($content, $p, $nameLen) !== $methodName) continue;
			if ($p + $nameLen < $len && self::_isIdentChar($content[$p + $nameLen])) continue;

			$k = $fnPos - 1;
			while ($k >= 0 && ($content[$k] === ' ' || $content[$k] === "\t")) {
				$k--;
			}
			foreach (array('static', 'final', 'abstract', 'protected', 'public', 'private') as $kw) {
				$klen = strlen($kw);
				if ($k - $klen + 1 >= 0
					&& substr($content, $k - $klen + 1, $klen) === $kw
					&& ($k - $klen < 0 || ! self::_isIdentChar($content[$k - $klen]))) {
					$k -= $klen;
					while ($k >= 0 && ($content[$k] === ' ' || $content[$k] === "\t")) {
						$k--;
					}
				}
			}
			$start = $k + 1;

			$q = $p + $nameLen;
			$bs = 'normal';
			$depth = 0;
			$started = false;
			while ($q < $len) {
				$c = $content[$q];
				$next = ($q + 1 < $len) ? $content[$q + 1] : '';
				if ($bs === 'normal') {
					if ($c === '{') {
						$depth++;
						$started = true;
					} elseif ($c === '}') {
						$depth--;
						if ($started && $depth === 0) {
							$end = $q + 1;
							if ($end < $len && $content[$end] === "\n") $end++;
							return array($start, $end);
						}
					} elseif ($c === "'") { $bs = 'single'; $q++; continue; }
					elseif ($c === '"') { $bs = 'double'; $q++; continue; }
					elseif ($c === '/' && $next === '/') { $bs = 'line_comment'; $q += 2; continue; }
					elseif ($c === '/' && $next === '*') { $bs = 'block_comment'; $q += 2; continue; }
				} elseif ($bs === 'single') {
					if ($c === '\\') { $q += 2; continue; }
					if ($c === "'") $bs = 'normal';
				} elseif ($bs === 'double') {
					if ($c === '\\') { $q += 2; continue; }
					if ($c === '"') $bs = 'normal';
				} elseif ($bs === 'line_comment') {
					if ($c === "\n") $bs = 'normal';
				} elseif ($bs === 'block_comment') {
					if ($c === '*' && $next === '/') { $bs = 'normal'; $q += 2; continue; }
				}
				$q++;
			}
			return null;
		}

		return null;
	}

	private static function _isIdentChar($ch)
	{
		return ctype_alnum($ch) || $ch === '_';
	}
}
