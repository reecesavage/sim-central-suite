<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Periodic status report to the broker.
 *
 * Piggybacks on the suite's existing 24h update check (UpdateCheck::latest)
 * so it shares that cadence and trigger points - no cron, no extra page-load
 * cost beyond the once-a-day window. Reports the sim's identity, which suite
 * features and predecessor extensions are active, and whether the API is
 * reachable, so the operator can keep a live picture of the fleet.
 *
 * Entirely best-effort and silent: a missing broker URL, a disabled toggle,
 * or a network failure all return quietly. It must never affect a render.
 *
 * Cache row (independent of the update-check row):
 *   setting_key = 'sim_central_report'  -> JSON { reported_at }
 */
class PhoneHome
{
	const SETTING_KEY       = 'sim_central_report';
	const SETTING_LABEL     = 'Sim Central Suite - status report cache (do not edit by hand)';
	const CACHE_TTL_SECONDS = 86400; // 24h, matching the update check

	/**
	 * Send a report if one is due. Safe to call on every update-check pass;
	 * the TTL gate keeps it to roughly once a day. Swallows everything.
	 */
	public static function maybeReport($force = false)
	{
		try {
			if ( ! self::isEnabled() || ! Broker::isConfigured()) {
				return;
			}

			$cache = self::loadCache();
			$now   = time();
			$due   = empty($cache['reported_at'])
				|| ($now - (int) $cache['reported_at']) >= self::CACHE_TTL_SECONDS;
			if ( ! $force && ! $due) {
				return;
			}

			Broker::post('/phone-home', self::report());

			// Bump the timestamp whether or not the broker answered, so a dead
			// broker doesn't make us retry on every page load until the TTL.
			self::saveCache(array('reported_at' => $now));
		} catch (\Throwable $e) {
			// Never let a status report surface to the user.
		} catch (\Exception $e) {
			// PHP 5.x safety net.
		}
	}

	/** Default-on; admins can switch it off via the sim_central_report_enabled setting. */
	public static function isEnabled()
	{
		$c = Config::load();
		if (isset($c['setting']['sim_central_report_enabled'])) {
			return (int) $c['setting']['sim_central_report_enabled'] === 1;
		}
		return true;
	}

	// ---------- payload ----------

	private static function report()
	{
		$features = Config::features();
		$enabled  = array();
		foreach ($features as $key => $on) {
			if ( ! empty($on)) { $enabled[] = $key; }
		}

		$access = SimCentralAccess::status();

		return array(
			'sim'            => SimCentralAccess::simIdentity(),
			'features'       => $enabled,
			'extensions'     => self::activeExtensions(),
			'api_accessible' => ( ! empty($features['rest_api']) && ! empty($access['active'])),
			'access_granted' => ! empty($access['granted']),
			'php_version'    => PHP_VERSION,
			'at'             => date('c'),
		);
	}

	/** Standalone extensions Nova currently has enabled, for fleet visibility. */
	private static function activeExtensions()
	{
		$ci  =& get_instance();
		$cfg = $ci->config->item('extensions');
		if (is_array($cfg) && isset($cfg['enabled']) && is_array($cfg['enabled'])) {
			return array_values($cfg['enabled']);
		}
		return array();
	}

	// ---------- cache ----------

	private static function loadCache()
	{
		$ci    =& get_instance();
		$query = $ci->db->get_where('settings', array('setting_key' => self::SETTING_KEY), 1);
		if ($query->num_rows() === 0) {
			return array();
		}
		$decoded = json_decode($query->row()->setting_value, true);
		return is_array($decoded) ? $decoded : array();
	}

	private static function saveCache(array $cache)
	{
		$ci   =& get_instance();
		$json = json_encode($cache);
		if ($json === false) {
			return;
		}
		$existing = $ci->db->get_where('settings', array('setting_key' => self::SETTING_KEY), 1);
		if ($existing->num_rows() > 0) {
			$ci->db->where('setting_id', $existing->row()->setting_id)
				->update('settings', array(
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
	}
}
