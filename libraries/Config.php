<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Single source of truth for the suite's settings.
 *
 * Two layers:
 *   config.json (file, in repo)  - bundled defaults shipped with the
 *                                  release. Replaced on every upgrade.
 *   settings table (DB row)      - user state. Survives upgrades,
 *                                  backups, and filesystem churn.
 *
 * load()  deep-merges config + state with state taking precedence, so
 *         any key the admin has touched wins, and any new key that
 *         shows up only in a newer bundled config still flows through
 *         to the UI.
 * save()  writes ONLY to the settings row. config.json is never
 *         modified.
 *
 * The settings row uses setting_key = 'sim_central_state', setting_value
 * = JSON-encoded full state, setting_user_created = 'n' (so it doesn't
 * appear in Nova's admin user-settings list).
 *
 * Migration on first load:
 *   - if a row already exists, use it
 *   - else if a legacy state.json file exists from a previous build of
 *     this extension, seed the row from it and delete the file
 *   - else seed the row from config.json so customisations the user had
 *     before this version (when config.json itself held the state) are
 *     preserved
 *
 * Results are cached per request - the suite reads from many event
 * listeners and we don't want each one re-querying.
 */
class Config
{
	const SETTING_KEY   = 'sim_central_state';
	const SETTING_LABEL = 'Sim Central Suite - runtime state (do not edit by hand)';

	private static $cache = null;

	public static function configPath()
	{
		return APPPATH.'extensions/nova_ext_sim_central/config.json';
	}

	/** Legacy file-based state location; only consulted during migration. */
	public static function legacyStatePath()
	{
		return APPPATH.'extensions/nova_ext_sim_central/state.json';
	}

	/**
	 * Returns the full merged config as an array. Reads on first call,
	 * cached thereafter.
	 */
	public static function load()
	{
		if (self::$cache !== null) {
			return self::$cache;
		}

		$defaults = self::readJson(self::configPath());
		$state    = self::loadState();

		if (empty($state)) {
			// First run, or migrating from an older build.
			$state = self::migrateInitialState($defaults);
		}

		self::$cache = self::deepMerge($defaults, $state);
		return self::$cache;
	}

	/**
	 * Persist the full $config to the settings row. The full document is
	 * written (not just a diff) so a later config.json default change
	 * can't silently override a user-set value just because the state
	 * row happens not to have that key.
	 */
	public static function save(array $config)
	{
		$ci   =& get_instance();
		$json = json_encode($config, JSON_PRETTY_PRINT);
		if ($json === false) {
			return false;
		}

		$existing = $ci->db->get_where('settings', array('setting_key' => self::SETTING_KEY), 1);
		if ($existing->num_rows() > 0) {
			$row = $existing->row();
			$ci->db->where('setting_id', $row->setting_id);
			$ci->db->update('settings', array(
				'setting_value' => $json,
				'setting_label' => self::SETTING_LABEL,
			));
		} else {
			$ci->db->insert('settings', array(
				'setting_key'          => self::SETTING_KEY,
				'setting_value'        => $json,
				'setting_label'        => self::SETTING_LABEL,
				'setting_user_created' => 'n',
			));
		}

		self::$cache = $config;
		return true;
	}

	/**
	 * Convenience accessor for the feature toggles. Returns an empty
	 * array (no features enabled) if the section is missing or malformed.
	 */
	public static function features()
	{
		$c = self::load();
		return (isset($c['features']) && is_array($c['features'])) ? $c['features'] : array();
	}

	/**
	 * Drop the per-request cache. Used by tests / migration tooling;
	 * normal request flow shouldn't need it.
	 */
	public static function clearCache()
	{
		self::$cache = null;
	}

	// ---------- internals ----------

	private static function loadState()
	{
		$ci    =& get_instance();
		$query = $ci->db->get_where('settings', array('setting_key' => self::SETTING_KEY), 1);
		if ($query->num_rows() === 0) {
			return array();
		}
		$row     = $query->row();
		$decoded = json_decode($row->setting_value, true);
		return is_array($decoded) ? $decoded : array();
	}

	/**
	 * Seed the state row on the very first load. Prefers an existing
	 * legacy state.json (if a previous build of this extension wrote one)
	 * over the bundled config.json, so no customisation gets lost.
	 */
	private static function migrateInitialState(array $defaults)
	{
		$legacyPath = self::legacyStatePath();
		$state      = array();

		if (file_exists($legacyPath)) {
			$legacy = self::readJson($legacyPath);
			if ( ! empty($legacy)) {
				$state = $legacy;
			}
		}
		if (empty($state)) {
			$state = $defaults;
		}

		// Persist via save() so the same code path that future writes
		// take handles the insert. save() also updates the per-request
		// cache, but we'll overwrite it with the merged value below.
		self::save($state);

		// Clean up the legacy file once its contents are safe in the DB.
		if (file_exists($legacyPath) && is_writable($legacyPath)) {
			@unlink($legacyPath);
		}

		return $state;
	}

	private static function readJson($path)
	{
		if ( ! file_exists($path)) {
			return array();
		}
		$json = json_decode(file_get_contents($path), true);
		return is_array($json) ? $json : array();
	}

	/**
	 * Deep merge: $override wins for every leaf key. Associative arrays
	 * are merged recursively; numeric/list arrays are replaced wholesale
	 * (so the user can shrink a list, not just extend it).
	 */
	private static function deepMerge($base, $override)
	{
		foreach ($override as $key => $value) {
			if (isset($base[$key])
				&& is_array($base[$key])
				&& is_array($value)
				&& self::isAssoc($base[$key])
				&& self::isAssoc($value)) {
				$base[$key] = self::deepMerge($base[$key], $value);
			} else {
				$base[$key] = $value;
			}
		}
		return $base;
	}

	private static function isAssoc($arr)
	{
		if ( ! is_array($arr) || empty($arr)) {
			return false;
		}
		return array_keys($arr) !== range(0, count($arr) - 1);
	}
}
