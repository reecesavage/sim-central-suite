<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Background check against the GitHub Releases API to see whether the
 * suite has a newer published release than the installed version.
 *
 * Wire:
 *   $info = \nova_ext_sim_central\UpdateCheck::latest();
 *   // $info['latest_version'] (string), $info['release_url'] (string),
 *   // $info['checked_at']    (int unix ts).
 *
 * Cache: a dedicated `settings` row (key `sim_central_update_check`)
 * holds the last successful result plus the last-checked timestamp.
 * The fetch only runs when the cache is older than CACHE_TTL_SECONDS
 * (24h). On any network/parse failure the check returns the cached
 * value and just bumps `checked_at` so we don't hammer GitHub - they
 * rate-limit unauthenticated traffic to 60 req/hr per IP.
 *
 * Network: short timeouts (2s connect, 3s total) so a slow GitHub
 * never blocks the admin dashboard. cURL only; if curl isn't loaded
 * we no-op and the UI shows the version with no "update available"
 * banner.
 */
class UpdateCheck
{
	const SETTING_KEY        = 'sim_central_update_check';
	const SETTING_LABEL      = 'Sim Central Suite - update check cache (do not edit by hand)';
	const CACHE_TTL_SECONDS  = 86400; // 24 hours
	// We query the releases LIST and pick the newest published release
	// ourselves, rather than GitHub's computed /releases/latest endpoint -
	// the latter intermittently 504s for this repo, which would make the
	// updater silently fall back to its cache and never see new releases.
	const RELEASES_API_URL   = 'https://api.github.com/repos/reecesavage/sim-central-suite/releases?per_page=30';
	const RELEASES_HTML_URL  = 'https://github.com/reecesavage/sim-central-suite/releases';

	/**
	 * Returns the current cache, refreshing it from GitHub if stale.
	 * Shape: array('checked_at' => int, 'latest_version' => string|null,
	 *              'release_url' => string|null)
	 */
	public static function latest($force = false)
	{
		$cache = self::loadCache();
		$now   = time();

		$stale = empty($cache['checked_at'])
			|| ($now - (int) $cache['checked_at']) >= self::CACHE_TTL_SECONDS;

		if ( ! $force && ! $stale) {
			return self::shape($cache);
		}

		$fresh = self::fetch();
		if ($fresh === null) {
			// Failed - keep whatever we knew last time but bump checked_at
			// so we don't retry on every page load until the TTL elapses.
			$cache['checked_at'] = $now;
			self::saveCache($cache);
			return self::shape($cache);
		}

		$cache = array(
			'checked_at'     => $now,
			'latest_version' => $fresh['version'],
			'release_url'    => $fresh['url'],
		);
		self::saveCache($cache);
		return self::shape($cache);
	}

	/**
	 * True if $latest is strictly newer than $current per version_compare.
	 * Both strings can be plain "1.2.3" or tag style "v1.2.3".
	 */
	public static function isNewer($latest, $current)
	{
		if ( ! is_string($latest) || $latest === '') {
			return false;
		}
		return version_compare(ltrim($latest, 'vV'), ltrim((string) $current, 'vV'), '>');
	}

	public static function releasesUrl()
	{
		return self::RELEASES_HTML_URL;
	}

	/**
	 * Coarse human-readable age for a unix timestamp - "just now",
	 * "12 min ago", "3 hr ago", "2 days ago", or "never" for empty
	 * input. Used by the dashboard's "Last checked" indicator next to
	 * the manual recheck button.
	 */
	public static function relativeCheckedAt($timestamp)
	{
		$ts = (int) $timestamp;
		if ($ts <= 0) {
			return 'never';
		}
		$diff = time() - $ts;
		if ($diff < 60)    return 'just now';
		if ($diff < 3600)  return floor($diff / 60).' min ago';
		if ($diff < 86400) return floor($diff / 3600).' hr ago';
		$days = floor($diff / 86400);
		return $days.' day'.($days === 1.0 ? '' : 's').' ago';
	}

	// ---------- internals ----------

	private static function shape(array $cache)
	{
		return array(
			'checked_at'     => isset($cache['checked_at'])     ? (int) $cache['checked_at']     : 0,
			'latest_version' => isset($cache['latest_version']) ? $cache['latest_version']       : null,
			'release_url'    => isset($cache['release_url'])    ? $cache['release_url']          : null,
		);
	}

	private static function fetch()
	{
		if ( ! function_exists('curl_init')) {
			return null;
		}

		$ch = curl_init(self::RELEASES_API_URL);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_TIMEOUT        => 3,
			// GitHub rejects requests with no User-Agent.
			CURLOPT_USERAGENT => 'sim-central-suite/'.Config::version(),
			CURLOPT_HTTPHEADER => array(
				'Accept: application/vnd.github+json',
			),
		));
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false || (int) $code !== 200) {
			return null;
		}

		$data = json_decode($body, true);
		if ( ! is_array($data)) {
			return null;
		}

		// The list endpoint returns an array of releases (newest-created
		// first). Pick the highest published version by semver, skipping
		// drafts and pre-releases.
		$bestVersion = null;
		$bestUrl     = self::RELEASES_HTML_URL;
		foreach ($data as $release) {
			if ( ! is_array($release) || empty($release['tag_name'])) {
				continue;
			}
			if ( ! empty($release['draft']) || ! empty($release['prerelease'])) {
				continue;
			}
			$version = ltrim((string) $release['tag_name'], 'vV');
			if ($bestVersion === null || version_compare($version, $bestVersion, '>')) {
				$bestVersion = $version;
				$bestUrl     = ! empty($release['html_url']) ? (string) $release['html_url'] : self::RELEASES_HTML_URL;
			}
		}

		if ($bestVersion === null) {
			return null;
		}

		return array('version' => $bestVersion, 'url' => $bestUrl);
	}

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
