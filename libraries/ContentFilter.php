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
	const DIMENSIONS    = array('sex', 'violence');
	const RATING_GATE   = 3;

	/**
	 * Returns the sim-wide ratings as array('language'=>0..3, 'sex'=>0..3, 'violence'=>0..3).
	 */
	public static function ratings()
	{
		$c = Config::load();
		$s = isset($c['setting']) && is_array($c['setting']) ? $c['setting'] : array();
		return array(
			'language' => self::clampRating(isset($s['content_filter_language']) ? $s['content_filter_language'] : 0),
			'sex'      => self::clampRating(isset($s['content_filter_sex'])      ? $s['content_filter_sex']      : 0),
			'violence' => self::clampRating(isset($s['content_filter_violence']) ? $s['content_filter_violence'] : 0),
		);
	}

	/**
	 * True if the filter has any effect on this sim - i.e. the sim
	 * allows at least one of (Sex, Violence) to reach the gate
	 * threshold (3). Language alone never makes the filter active.
	 */
	public static function isActive()
	{
		$r = self::ratings();
		foreach (self::DIMENSIONS as $dim) {
			if ((int) $r[$dim] >= self::RATING_GATE) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Which gateable dimensions the sim actually permits at 3, in a
	 * stable order. Used to drive the per-post toggle's definition
	 * text (a 323 sim should not show the sexual-content definition).
	 */
	public static function gatedDimensions()
	{
		$r   = self::ratings();
		$out = array();
		foreach (self::DIMENSIONS as $dim) {
			if ((int) $r[$dim] >= self::RATING_GATE) {
				$out[] = $dim;
			}
		}
		return $out;
	}

	/**
	 * Definition text per dimension, pulled from the editable label
	 * block in config.json. Returned only for dimensions the sim
	 * actually permits at 3.
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

	private static function clampRating($value)
	{
		$n = (int) $value;
		if ($n < 0) return 0;
		if ($n > 3) return 3;
		return $n;
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
