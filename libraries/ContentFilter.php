<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Content Filter feature - age-gates mission post bodies on the public
 * site and in the RSS feed when the sim allows ratings high enough for
 * explicit sexual content or explicit violence.
 *
 * Rating model mirrors rpgrating.com: Language / Sex / Violence on a
 * 0-3 scale. The filter only acts on Sex and Violence at 3 ("Sexual
 * content expressed in detail" / "Explicit violence not depicted").
 * Language is tracked for display completeness but never triggers
 * gating - a sim can be 3-rated for Language and still have nothing
 * hidden.
 *
 * Per-post: every new post defaults to age-gated when the sim allows
 * Sex=3 or Violence=3. The writer can untick the per-post toggle to
 * mark their specific post as safe; the toggle UI shows only the
 * definitions for whichever dimensions the sim actually permits at 3
 * (so a 323 sim never asks the writer to attest to sexual content).
 *
 * When gated AND the viewer isn't logged in:
 *   - viewpost replaces the body with a notice
 *   - the RSS feed entry keeps the header (title/authors/timeline/etc)
 *     but its body is the same notice
 *   - listposts is unaffected (it never showed the body)
 *
 * Logged-in users always see everything regardless of gating.
 */
class ContentFilter
{
	const DIMENSIONS = array('language', 'sex', 'violence');

	/**
	 * Boolean flags: does this sim PERMIT each kind of explicit content?
	 * Returns array('language' => bool, 'sex' => bool, 'violence' => bool).
	 *
	 * Reads new-style keys (content_filter_allows_*) first; falls back
	 * to the old 0-3 rating keys (content_filter_* >= 3) so sims upgrading
	 * from v1.4.x and earlier keep gating the same content until the admin
	 * saves the config (at which point the old keys are pruned).
	 */
	public static function allows()
	{
		$c = Config::load();
		$s = isset($c['setting']) && is_array($c['setting']) ? $c['setting'] : array();
		$out = array();
		foreach (self::DIMENSIONS as $dim) {
			$out[$dim] = self::resolveDimension($s, $dim);
		}
		return $out;
	}

	private static function resolveDimension($settings, $dim)
	{
		$newKey = 'content_filter_allows_'.$dim;
		if (array_key_exists($newKey, $settings)) {
			return ! empty($settings[$newKey]);
		}
		// Legacy fallback: the v1.2 / v1.4 0-3 rating scale. A rating
		// of 3 (explicit) is the only level that ever gated content,
		// so derive accordingly.
		$oldKey = 'content_filter_'.$dim;
		if (isset($settings[$oldKey])) {
			return ((int) $settings[$oldKey]) >= 3;
		}
		return false;
	}

	/**
	 * True if the filter has any effect on this sim - any of the three
	 * dimensions are permitted. Used to decide whether to render the
	 * per-post toggle on the post form.
	 */
	public static function isActive()
	{
		$a = self::allows();
		foreach (self::DIMENSIONS as $dim) {
			if (! empty($a[$dim])) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Which dimensions are permitted on this sim, in stable order.
	 * Drives the per-post toggle's definition list (writer attests
	 * their post doesn't contain any of these).
	 */
	public static function gatedDimensions()
	{
		$a   = self::allows();
		$out = array();
		foreach (self::DIMENSIONS as $dim) {
			if (! empty($a[$dim])) {
				$out[] = $dim;
			}
		}
		return $out;
	}

	/**
	 * Definition text per dimension, pulled from the editable label
	 * block in config.json. Returned only for dimensions the sim
	 * actually permits.
	 */
	public static function gatedDefinitions()
	{
		$c        = Config::load();
		$labels   = isset($c['nova_ext_content_filter']) && is_array($c['nova_ext_content_filter'])
			? $c['nova_ext_content_filter']
			: array();
		$out      = array();
		foreach (self::gatedDimensions() as $dim) {
			$key = 'definition_'.$dim;
			if (isset($labels[$key]['value'])) {
				$out[$dim] = $labels[$key]['value'];
			}
		}
		return $out;
	}

	/**
	 * Default state of the per-post age-gate toggle on the write-post
	 * form for NEW posts. Existing posts use their stored value.
	 *
	 * Default is TRUE (gated by default) - safer policy. A sim with
	 * rare explicit content can flip this to false so writers opt IN
	 * to gating only when they actually need it.
	 */
	public static function defaultAgeGate()
	{
		$c = Config::load();
		$s = isset($c['setting']) ? $c['setting'] : array();
		if ( ! array_key_exists('content_filter_age_gate_default', $s)) {
			return true;
		}
		return ! empty($s['content_filter_age_gate_default']);
	}

	/**
	 * Whether the age-gate confirmation popup should also fire when the
	 * writer SAVES a draft (clicks Save), rather than only when they POST
	 * (publish) the post.
	 *
	 * Default is FALSE - drafts aren't public, so there's no leak risk on
	 * save; the confirmation only matters at the moment content goes live.
	 * Admins who want the attestation on every save can flip this on.
	 */
	public static function confirmOnSave()
	{
		$c = Config::load();
		$s = isset($c['setting']) ? $c['setting'] : array();
		if ( ! array_key_exists('content_filter_confirm_on_save', $s)) {
			return false;
		}
		return ! empty($s['content_filter_confirm_on_save']);
	}

	/**
	 * True when the given post row should be hidden from guests. Pass
	 * either a DB row object or an array; the column we look at is
	 * `nova_ext_content_filter_age_gated`.
	 */
	public static function isPostGated($post)
	{
		if ( ! self::isActive()) {
			return false;
		}
		$gated = self::pluck($post, 'nova_ext_content_filter_age_gated');
		// Default to gated (1) when the column is missing/null - safer
		// than leaking a body if the row hasn't been migrated yet.
		if ($gated === null) {
			return true;
		}
		return ((int) $gated) === 1;
	}

	/**
	 * The notice shown in place of the body when a post is gated and
	 * the viewer isn't logged in. Editable as a label in config.json
	 * (key `notice`) so admins can rephrase per-sim.
	 */
	public static function noticeText()
	{
		$c      = Config::load();
		$labels = isset($c['nova_ext_content_filter']) && is_array($c['nova_ext_content_filter'])
			? $c['nova_ext_content_filter']
			: array();
		if (isset($labels['notice']['value']) && $labels['notice']['value'] !== '') {
			return (string) $labels['notice']['value'];
		}
		return 'This post is rated for mature audiences. Log in to view the full content.';
	}

	private static function pluck($row, $key)
	{
		if (is_object($row) && isset($row->$key)) {
			return $row->$key;
		}
		if (is_array($row) && array_key_exists($key, $row)) {
			return $row[$key];
		}
		return null;
	}
}
