<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Feature registry + database setup + shim writer, extracted from the
 * Manage controller (v1.27.0) so they can run OUTSIDE the dashboard:
 * runPending() is called from init.php on every request and, after a
 * suite update, automatically performs the same actions the dashboard's
 * "Set Up Database" and "Install Shim" buttons perform - for ENABLED
 * features only. Disabled features are never touched, and shim states
 * that need human judgment ('legacy', 'standalone_shim') are left for
 * the dashboard buttons.
 *
 * The Manage controller now delegates here, so behaviour of the buttons
 * is byte-for-byte the same code path.
 */
class Migrations
{
	const LOCK_NAME = 'sim_central_migrate';

	private static $registry = null;

	private static function db()
	{
		$ci =& get_instance();
		return $ci->db;
	}

	public static function registry()
	{
		if (self::$registry === null) {
			self::$registry = self::registryData();
		}
		return self::$registry;
	}

	/**
	 * Post-update auto-runner. Cheap on the hot path: one array lookup
	 * against the already-loaded state. When the last-migrated version
	 * differs from the running version (i.e. the first request after an
	 * update - including remote POST /suite updates where no human ever
	 * visits the dashboard), it runs pending database setup and shim
	 * installs for enabled features, then records a marker + summary in
	 * the state row. A MySQL advisory lock keeps concurrent first
	 * requests from double-applying shims. Any internal failure is
	 * logged and swallowed - this must never take down the site.
	 */
	public static function runPending()
	{
		try {
			$state = Config::load();
			$version = Config::version();
			$marker = isset($state['auto_migrated_version']) ? (string) $state['auto_migrated_version'] : '';
			if ($marker === $version) {
				return;
			}

			// Claim the run. Timeout 0: if another request holds the
			// lock, it is doing this work right now - just move on.
			$lock = self::db()->query("SELECT GET_LOCK('".self::LOCK_NAME."', 0) AS l")->row();
			if ( ! $lock || (int) $lock->l !== 1) {
				return;
			}

			try {
				// Re-check under the lock (another request may have
				// finished between our check and the claim).
				Config::clearCache();
				$state = Config::load();
				$marker = isset($state['auto_migrated_version']) ? (string) $state['auto_migrated_version'] : '';
				if ($marker === $version) {
					return;
				}

				$enabled = Config::features();
				$actions = array();
				$errors  = array();

				foreach (self::registry() as $key => $f) {
					if (empty($enabled[$key])) {
						continue;
					}

					$dbPending = ( ! empty($f['requires_tables']) && self::missingTables($key))
						|| ( ! empty($f['requires_db']) && self::missingColumns($key))
						|| ( ! empty($f['requires_indexes']) && self::missingIndexes($key))
						|| ( ! empty($f['removes_db']) && self::staleColumns($key));
					if ($dbPending) {
						list($status, $message) = self::setupDatabase($key);
						$line = $f['name'].': '.$message;
						($status === 'error') ? $errors[] = $line : $actions[] = $line;
					}

					if ( ! empty($f['shims'])) {
						foreach ($f['shims'] as $tag => $shim) {
							$shimState = self::shimState($key, $tag);
							// Only clear-cut states. 'legacy' and
							// 'standalone_shim' are take-over decisions
							// a human should make from the dashboard.
							if ($shimState !== 'missing' && $shimState !== 'outdated') {
								continue;
							}
							list($status, $message) = self::writeShim($key, $tag);
							$line = $f['name'].': '.$message;
							($status === 'error') ? $errors[] = $line : $actions[] = $line;
						}
					}
				}

				// Record the marker even when some actions errored: the
				// dashboard cards still flag the leftovers, and we must
				// not retry-loop the same failure on every request.
				$state['auto_migrated_version'] = $version;
				$state['auto_migrated_last'] = array(
					'version' => $version,
					'at'      => time(),
					'actions' => $actions,
					'errors'  => $errors,
				);
				Config::save($state);

				foreach ($actions as $line) {
					log_message('info', 'nova_ext_sim_central auto-migration: '.$line);
				}
				foreach ($errors as $line) {
					log_message('error', 'nova_ext_sim_central auto-migration: '.$line);
				}
			} finally {
				self::db()->query("SELECT RELEASE_LOCK('".self::LOCK_NAME."')");
			}
		} catch (\Throwable $e) {
			log_message('error', 'nova_ext_sim_central auto-migration failed: '.$e->getMessage());
		}
	}

	private static function registryData()
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
					// Shared with Ordered Mission Posts + URL Parser: the Write
					// controller's _email shim delegates to Email::filter, which
					// injects the summary line into post emails. No standalone_marker
					// here - Ordered owns the take-over of the parser_events
					// predecessor; the refcount keys on file+tag so it stays
					// installed while any of the three features is on.
					'email' => array(
						'file'   => APPPATH.'controllers/Write.php',
						'txt'    => dirname(__FILE__).'/../write.txt',
						'tag'    => 'email',
						'method' => '_email',
						'label'  => 'Post email code',
					),
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
				'shims'       => array(
					// Shared with Ordered Mission Posts + Summary: Email::filter
					// (via the Write controller's _email shim) expands [tag|...]
					// shortcodes into links in post/log/news emails. See the
					// summary feature's 'email' shim note above.
					'email' => array(
						'file'   => APPPATH.'controllers/Write.php',
						'txt'    => dirname(__FILE__).'/../write.txt',
						'tag'    => 'email',
						'method' => '_email',
						'label'  => 'Post email code',
					),
				),
				'config_route' => 'extensions/nova_ext_sim_central/Manage/url_parser',
			),
			'discord_auth' => array(
				'name'        => 'Discord Sign-In',
				'summary'     => 'Lets users sign in to the sim with their Discord account via the Sim Central Broker. Existing users can link their Discord; new users can sign up with Discord (when auto-create is on).',
				'standalone'  => 'nova_ext_discord_account_confirmation',
				'requires_db' => array(
					'users' => array(
						'nova_ext_discord_auth_id'        => 'VARCHAR(32) NULL',
						'nova_ext_discord_auth_linked_at' => 'INT NULL',
					),
				),
				// Columns older suite versions created that the feature no
				// longer uses. Set Up Database drops them when present, so
				// the sim stores nothing about the Discord account beyond
				// its public ID (and when it was linked).
				'removes_db' => array(
					'users' => array(
						'nova_ext_discord_auth_username',
						'nova_ext_discord_auth_avatar',
						'nova_ext_discord_auth_email_verified',
					),
				),
				'requires_indexes' => array(
					'users' => array(
						'nova_ext_discord_auth_id_unique' => '(`nova_ext_discord_auth_id`)',
					),
				),
				'shims' => array(
					// Adds the applicant's linked Discord to the GM
					// join-application email. Optional: without the shim,
					// the stock join email sends as always.
					'join_email' => array(
						'file'   => APPPATH.'controllers/Main.php',
						'txt'    => dirname(__FILE__).'/../main.txt',
						'tag'    => 'join_email',
						'method' => '_email',
						'label'  => 'Join application email code',
					),
				),
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
					'sim_central_api_tokens' => "CREATE TABLE IF NOT EXISTS `{prefix}sim_central_api_tokens` (`id` int(11) NOT NULL AUTO_INCREMENT, `label` varchar(120) NOT NULL, `token_hash` char(64) NOT NULL, `token_prefix` varchar(16) NOT NULL, `scopes` text NOT NULL, `user_id` int(11) DEFAULT NULL, `created_by` int(11) DEFAULT NULL, `created_at` datetime NOT NULL, `last_used_at` datetime DEFAULT NULL, `expires_at` datetime DEFAULT NULL, `revoked_at` datetime DEFAULT NULL, `rate_count` int(11) NOT NULL DEFAULT 0, `rate_window_at` datetime DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `token_hash` (`token_hash`)) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci",
				),
				// user_id binds a token to a Nova user so write/own endpoints know
				// "who" the token acts as. Added to existing installs via Setup
				// database (the CREATE above already includes it for fresh installs).
				'requires_db' => array(
					'sim_central_api_tokens' => array(
						'user_id' => "INT DEFAULT NULL",
					),
				),
				'shims'       => array(),
				'config_route' => 'extensions/nova_ext_sim_central/Manage/rest_api',
			),
			'webhooks' => array(
				'name'        => 'Event Webhooks',
				'summary'     => 'Fire HTTP webhooks when mission posts, personal logs, or news items are posted (and when posts are saved). Discord-formatted (embed with @mentions of linked authors on saved-post pings) or generic JSON (for n8n etc.). Multiple webhooks per event; news public/private filter; admin-managed under Configure.',
				'standalone'  => null,
				'requires_tables' => array(
					'sim_central_webhooks' => "CREATE TABLE IF NOT EXISTS `{prefix}sim_central_webhooks` (`id` int(11) NOT NULL AUTO_INCREMENT, `label` varchar(120) NOT NULL, `url` text NOT NULL, `format` varchar(20) NOT NULL, `events` text NOT NULL, `enabled` tinyint(1) NOT NULL DEFAULT 1, `news_types` varchar(20) NOT NULL DEFAULT 'public', `mention_role_id` varchar(32) DEFAULT NULL, `mention_role_events` text DEFAULT NULL, `template_title` text DEFAULT NULL, `template_description` text DEFAULT NULL, `template_log_title` text DEFAULT NULL, `template_log_description` text DEFAULT NULL, `template_news_title` text DEFAULT NULL, `template_news_description` text DEFAULT NULL, `created_by` int(11) DEFAULT NULL, `created_at` datetime NOT NULL, `last_fired_at` datetime DEFAULT NULL, `last_status` int(11) DEFAULT NULL, `last_error` text DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci",
				),
				// Columns added to existing installs via this ALTER path (the
				// CREATE above already has them for fresh installs).
				'requires_db' => array(
					'sim_central_webhooks' => array(
						'news_types'                => "VARCHAR(20) NOT NULL DEFAULT 'public'",
						'mention_role_id'           => "VARCHAR(32) DEFAULT NULL",
						'mention_role_events'       => "TEXT DEFAULT NULL",
						'template_log_title'        => "TEXT DEFAULT NULL",
						'template_log_description'  => "TEXT DEFAULT NULL",
						'template_news_title'       => "TEXT DEFAULT NULL",
						'template_news_description' => "TEXT DEFAULT NULL",
					),
				),
				'shims' => array(
					// NOTE: no standalone_marker_* fields on any of these. The
					// standalone-marker mechanism is for taking over a
					// PREDECESSOR extension's block (the way display_name takes
					// over nova_ext_display_name's character marker). For
					// brand-new shims with no predecessor, omit the fields -
					// otherwise multiple shims on the same file register as
					// standalone markers and strip each other on install,
					// ping-ponging the dashboard forever (see v1.11.1 fix).
					'webhooks_create' => array(
						'file'   => APPPATH.'models/Posts_model.php',
						'txt'    => dirname(__FILE__).'/../posts_model_create.txt',
						'tag'    => 'webhooks_create',
						'method' => 'create_mission_entry',
						'label'  => 'Post create webhook hook',
					),
					'webhooks_update' => array(
						'file'   => APPPATH.'models/Posts_model.php',
						'txt'    => dirname(__FILE__).'/../posts_model_update.txt',
						'tag'    => 'webhooks_update',
						'method' => 'update_post',
						'label'  => 'Post update webhook hook',
					),
					'news_create' => array(
						'file'   => APPPATH.'models/News_model.php',
						'txt'    => dirname(__FILE__).'/../news_model_create.txt',
						'tag'    => 'news_create',
						'method' => 'create_news_item',
						'label'  => 'News create webhook hook',
					),
					'news_update' => array(
						'file'   => APPPATH.'models/News_model.php',
						'txt'    => dirname(__FILE__).'/../news_model_update.txt',
						'tag'    => 'news_update',
						'method' => 'update_news_item',
						'label'  => 'News update webhook hook',
					),
					'log_create' => array(
						'file'   => APPPATH.'models/Personallogs_model.php',
						'txt'    => dirname(__FILE__).'/../log_model_create.txt',
						'tag'    => 'log_create',
						'method' => 'create_personal_log',
						'label'  => 'Log create webhook hook',
					),
					'log_update' => array(
						'file'   => APPPATH.'models/Personallogs_model.php',
						'txt'    => dirname(__FILE__).'/../log_model_update.txt',
						'tag'    => 'log_update',
						'method' => 'update_log',
						'label'  => 'Log update webhook hook',
					),
				),
				'config_route' => 'extensions/nova_ext_sim_central/Manage/webhooks',
			),
			'mobile' => array(
				'name'        => 'Mobile Site',
				'summary'     => 'A lightweight, mobile-friendly site at /mobile where members log in and write, save, post, edit, or delete their mission posts - without fighting Nova\'s desktop views on a phone.',
				'standalone'  => null,
				'requires_db' => array(),
				'shims'       => array(),
				'config_route' => 'extensions/nova_ext_sim_central/Manage/mobile',
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
	public static function knownStandaloneMarkers()
	{
		$markers = array();
		foreach (self::registry() as $f) {
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

	public static function missingColumns($key)
	{
		$f = self::registry()[$key];
		if ( ! isset($f['requires_db'])) {
			return array();
		}
		$prefix  = self::db()->dbprefix;
		$missing = array();
		foreach ($f['requires_db'] as $table => $columns) {
			// The target table might be one this feature creates itself via
			// requires_tables and which hasn't been created yet (e.g. a sim
			// that never set up Event Webhooks). list_fields() issues
			// SHOW COLUMNS, which throws on a missing table - so skip it.
			// _missingTables() already reports the table as missing, which is
			// what drives the "Setup database" prompt; that single action
			// creates the table AND adds the columns together.
			if ( ! self::db()->table_exists($prefix.$table) && ! self::db()->table_exists($table)) {
				continue;
			}
			$existing = self::db()->list_fields($prefix.$table);
			foreach ($columns as $column => $def) {
				if ( ! in_array($column, $existing, true)) {
					$missing[] = $table.'.'.$column;
				}
			}
		}
		return $missing;
	}

	/**
	 * Columns listed in a feature's removes_db that still exist -
	 * leftovers from an older suite version. Drives the same "Set Up
	 * Database" prompt as missing columns; the setup action drops them.
	 */
	public static function staleColumns($key)
	{
		$f = self::registry()[$key];
		if (empty($f['removes_db'])) {
			return array();
		}
		$prefix = self::db()->dbprefix;
		$stale  = array();
		foreach ($f['removes_db'] as $table => $columns) {
			if ( ! self::db()->table_exists($prefix.$table) && ! self::db()->table_exists($table)) {
				continue;
			}
			$existing = self::db()->list_fields($prefix.$table);
			foreach ($columns as $column) {
				if (in_array($column, $existing, true)) {
					$stale[] = $table.'.'.$column;
				}
			}
		}
		return $stale;
	}

	public static function missingTables($key)
	{
		$f = self::registry()[$key];
		if (empty($f['requires_tables'])) {
			return array();
		}
		$prefix  = self::db()->dbprefix;
		$missing = array();
		foreach ($f['requires_tables'] as $table => $sql) {
			if ( ! self::db()->table_exists($prefix.$table) && ! self::db()->table_exists($table)) {
				$missing[] = $table;
			}
		}
		return $missing;
	}

	public static function missingIndexes($key)
	{
		$f = self::registry()[$key];
		if (empty($f['requires_indexes'])) {
			return array();
		}
		$prefix  = self::db()->dbprefix;
		$missing = array();
		foreach ($f['requires_indexes'] as $table => $indexes) {
			foreach ($indexes as $indexName => $columnsSql) {
				if ( ! self::indexExists($prefix.$table, $indexName)) {
					$missing[] = $table.'.'.$indexName;
				}
			}
		}
		return $missing;
	}

	public static function indexExists($table, $indexName)
	{
		$result = self::db()->query('SHOW INDEX FROM `'.$table.'`');
		foreach ($result->result() as $row) {
			if ($row->Key_name === $indexName) {
				return true;
			}
		}
		return false;
	}

	public static function setupDatabase($key)
	{
		$f = self::registry()[$key];
		if (empty($f['requires_db']) && empty($f['requires_tables']) && empty($f['requires_indexes']) && empty($f['removes_db'])) {
			return array('success', 'Nothing to do - this feature has no database setup.');
		}
		$prefix         = self::db()->dbprefix;
		$tablesAdded    = 0;
		$columnsAdded   = 0;
		$indexesAdded   = 0;
		$columnsDropped = 0;

		// Create missing tables first so any column adds below can land on them.
		if ( ! empty($f['requires_tables'])) {
			foreach ($f['requires_tables'] as $table => $sql) {
				if (self::db()->table_exists($prefix.$table) || self::db()->table_exists($table)) {
					continue;
				}
				self::db()->query(str_replace('{prefix}', $prefix, $sql));
				$tablesAdded++;
			}
		}

		if ( ! empty($f['requires_db'])) {
			foreach ($f['requires_db'] as $table => $columns) {
				// Skip if the table doesn't exist (e.g. its requires_tables
				// CREATE failed above). Avoids a list_fields() fatal; the
				// table problem surfaces via _missingTables instead.
				if ( ! self::db()->table_exists($prefix.$table) && ! self::db()->table_exists($table)) {
					continue;
				}
				$existing = self::db()->list_fields($prefix.$table);
				foreach ($columns as $column => $def) {
					if (in_array($column, $existing, true)) {
						continue;
					}
					$sql = 'ALTER TABLE `'.$prefix.$table.'` ADD COLUMN `'.$column.'` '.$def;
					self::db()->query($sql);
					$columnsAdded++;
				}
			}
		}

		// Indexes go last so they always land on already-existing columns.
		if ( ! empty($f['requires_indexes'])) {
			foreach ($f['requires_indexes'] as $table => $indexes) {
				foreach ($indexes as $indexName => $columnsSql) {
					if (self::indexExists($prefix.$table, $indexName)) {
						continue;
					}
					self::db()->query('CREATE INDEX `'.$indexName.'` ON `'.$prefix.$table.'` '.$columnsSql);
					$indexesAdded++;
				}
			}
		}

		// Drop columns the feature has stopped using (left behind by an
		// older suite version). Only ever touches columns named in
		// removes_db, so re-running is safe.
		if ( ! empty($f['removes_db'])) {
			foreach ($f['removes_db'] as $table => $columns) {
				if ( ! self::db()->table_exists($prefix.$table) && ! self::db()->table_exists($table)) {
					continue;
				}
				$existing = self::db()->list_fields($prefix.$table);
				foreach ($columns as $column) {
					if ( ! in_array($column, $existing, true)) {
						continue;
					}
					self::db()->query('ALTER TABLE `'.$prefix.$table.'` DROP COLUMN `'.$column.'`');
					$columnsDropped++;
				}
			}
		}

		if ($tablesAdded === 0 && $columnsAdded === 0 && $indexesAdded === 0 && $columnsDropped === 0) {
			return array('success', 'Database is already fully set up - nothing to do.');
		}
		$parts = array();
		if ($tablesAdded > 0)    $parts[] = 'added '.$tablesAdded.' table(s)';
		if ($columnsAdded > 0)   $parts[] = 'added '.$columnsAdded.' column(s)';
		if ($indexesAdded > 0)   $parts[] = 'added '.$indexesAdded.' index(es)';
		if ($columnsDropped > 0) $parts[] = 'removed '.$columnsDropped.' unused column(s)';
		return array('success', 'Database setup complete: '.implode(', ', $parts).'.');
	}

	/**
	 * Worst-of state across every shim in a feature, ranked by:
	 * missing_file > standalone_shim > legacy > outdated > missing > current.
	 */
	public static function featureCombinedState($key)
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
		foreach (array_keys(self::registry()[$key]['shims']) as $tag) {
			$state = self::shimState($key, $tag);
			if ( ! isset($priority[$state])) {
				continue;
			}
			if ($priority[$state] > $priority[$worst]) {
				$worst = $state;
			}
		}
		return $worst;
	}

	public static function shimState($key, $tag)
	{
		$shim = self::registry()[$key]['shims'][$tag];

		if ( ! file_exists($shim['file'])) {
			return 'missing_file';
		}

		$file = file_get_contents($shim['file']);
		$txt  = file_exists($shim['txt']) ? file_get_contents($shim['txt']) : '';

		$installedVersion = self::blockVersion($file, $shim['tag']);
		$currentVersion   = self::blockVersion($txt,  $shim['tag']);

		if ($installedVersion !== null) {
			return ($installedVersion === $currentVersion) ? 'current' : 'outdated';
		}

		// Cross-feature: any registered standalone marker counts as
		// a take-over candidate, not just the one this feature lists.
		foreach (self::knownStandaloneMarkers() as $m) {
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

	public static function blockVersion($content, $tag)
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
	public static function readBlockFromTxt($txtPath, $tag)
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

	public static function writeAllShims($key)
	{
		$shims  = self::registry()[$key]['shims'];
		$wrote  = 0;
		$errors = array();
		foreach (array_keys($shims) as $tag) {
			$result = self::writeShim($key, $tag);
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
			return array('success', self::registry()[$key]['name'].' shims are already up to date.');
		}
		return array('success', self::registry()[$key]['name'].' shims updated successfully.');
	}

	public static function writeShim($key, $tag)
	{
		$shim  = self::registry()[$key]['shims'][$tag];
		$state = self::shimState($key, $tag);

		if ($state === 'current') {
			return array('success', $shim['label'].' is already up to date.');
		}
		if ($state === 'missing_file') {
			return array('error', 'Could not find '.$shim['file'].'.');
		}

		$block = self::readBlockFromTxt($shim['txt'], $shim['tag']);
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
			$span = self::findUnmarkedMethodSpan($file, $shim['method']);
			if ($span === null) {
				return array('error', 'Could not parse the existing '.$shim['method'].'() method in '.basename($shim['file']).'.');
			}
			$file = substr($file, 0, $span[0]).$block."\n".substr($file, $span[1]);
		} elseif ($state === 'standalone_shim') {
			// Strip every known standalone marker block from the target file
			// (not just this feature's standalone) so a take-over from ANY
			// previous owner cleans up cleanly.
			foreach (self::knownStandaloneMarkers() as $m) {
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

		if (file_put_contents($shim['file'], $file) === false) {
			return array('error', basename($shim['file']).' is not writable - could not install the '.$shim['label'].' block.');
		}
		return array('success', $shim['label'].' updated successfully.');
	}

	public static function removeAllShims($key, $futureEnabled)
	{
		foreach (array_keys(self::registry()[$key]['shims']) as $tag) {
			self::removeShim($key, $tag, $futureEnabled);
		}
	}

	public static function removeShim($key, $tag, $futureEnabled)
	{
		$shim = self::registry()[$key]['shims'][$tag];

		// Refcount: if any other still-enabled feature has a shim with the
		// same file + tag, leave it alone - that feature still needs it.
		foreach (self::registry() as $otherKey => $f) {
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
	private static function findUnmarkedMethodSpan($content, $methodName)
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
					&& ($i === 0 || ! self::isIdentChar($content[$i - 1]))
					&& ($i + 8 >= $len || ! self::isIdentChar($content[$i + 8]))) {
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
			if ($p + $nameLen < $len && self::isIdentChar($content[$p + $nameLen])) continue;

			$k = $fnPos - 1;
			while ($k >= 0 && ($content[$k] === ' ' || $content[$k] === "\t")) {
				$k--;
			}
			foreach (array('static', 'final', 'abstract', 'protected', 'public', 'private') as $kw) {
				$klen = strlen($kw);
				if ($k - $klen + 1 >= 0
					&& substr($content, $k - $klen + 1, $klen) === $kw
					&& ($k - $klen < 0 || ! self::isIdentChar($content[$k - $klen]))) {
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

	private static function isIdentChar($ch)
	{
		return ctype_alnum($ch) || $ch === '_';
	}
}
