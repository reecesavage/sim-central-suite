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
