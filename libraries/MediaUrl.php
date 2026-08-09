<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Absolute media URLs for the REST API and the Astrolabe snapshot (v1.41.0).
 *
 * One place that turns what Nova actually stores - a bare filename, a CSV of
 * them, or a rank basename with no extension - into an absolute https:// URL
 * or null. Consumers of this API are off-site (Astrolabe, a Discord bot, a
 * dashboard): a root-relative path or a bare filename is useless to them, and
 * resolving one themselves would mean reproducing Nova's asset layout, its
 * APPFOLDER, its genre folder, and two catalogue columns they cannot see.
 *
 * Rules, fixed here so every endpoint answers the same way:
 *   - Absolute or null. Never a bare filename, never "".
 *   - http:// is rewritten to https://. A mixed-content URL is a dropped image
 *     in any browser client, so this is not the caller's problem to remember.
 *   - An entry that is already a full URL passes through: Nova lets an images
 *     CSV hold them, and Nova's own personnel view honours that.
 *   - all() returns an array, empty but never null, so a consumer needn't
 *     null-guard a list. first() returns null, never "".
 *
 * Extracted from AstrolabeSnapshot in v1.41.0, which still delegates here, so
 * the snapshot and the API cannot drift on what a URL looks like.
 */
class MediaUrl
{
	// Nova's asset folders, relative to application/assets/. Passed to
	// Location::asset(), which prepends APPFOLDER.'/assets/'.
	const CHARACTERS = 'images/characters';
	const MISSIONS   = 'images/missions';
	const SPECS      = 'images/specs';
	const TOUR       = 'images/tour';
	const AWARDS     = 'images/awards';

	/** Resolved rank set + extension, memoized per request. See rankMeta(). */
	private static $rankMeta = null;

	/**
	 * Force http -> https so a consumer never drops a URL to mixed content.
	 */
	public static function https($url)
	{
		return preg_replace('#^http://#i', 'https://', (string) $url);
	}

	/**
	 * Absolute https URL for a site-relative path (a page, not an asset).
	 */
	public static function site($path)
	{
		return self::https(site_url($path));
	}

	/**
	 * Absolute https URL for ONE stored image filename in $folder, or null
	 * when there's no filename. Already-absolute entries pass through.
	 */
	public static function asset($folder, $file)
	{
		$file = trim((string) $file);
		if ($file === '') {
			return null;
		}
		if (stripos($file, 'http://') === 0 || stripos($file, 'https://') === 0) {
			return self::https($file);
		}
		return self::https(base_url(\Location::asset($folder, $file)));
	}

	/**
	 * Every image in a stored CSV, in stored order, as absolute https URLs.
	 * Blank entries are skipped rather than emitted as null holes.
	 *
	 * Always an array. A sim with no images gets [], not null.
	 */
	public static function all($csv, $folder)
	{
		$out = array();
		foreach (explode(',', (string) $csv) as $file) {
			$url = self::asset($folder, $file);
			if ($url !== null) {
				$out[] = $url;
			}
		}
		return $out;
	}

	/**
	 * The FIRST entry of a stored CSV - the avatar / hero image - or null.
	 *
	 * Deliberately positional rather than "first non-empty": Nova treats
	 * element 0 as the primary image everywhere, so a leading empty entry
	 * means there is no primary image, not "use the next one".
	 */
	public static function first($csv, $folder)
	{
		$parts = explode(',', (string) $csv);
		return self::asset($folder, isset($parts[0]) ? $parts[0] : '');
	}

	/**
	 * Absolute https URL for a rank's artwork, or null.
	 *
	 * Rank art is the one image in Nova that doesn't live under assets/images:
	 * it resolves through assets/common/{genre}/ranks/{set}/{image}{ext},
	 * where the set is a sim setting and the extension is a catalogue column.
	 * That's why this is generated sim-side and never handed out as a bare
	 * rank_image filename.
	 *
	 * Null - not a broken URL - when the rank has no image, or when the sim's
	 * display_rank setting names a set that get_rankcat() can't resolve. The
	 * usual cause of the latter is a set belonging to another genre, since the
	 * lookup filters on rankcat_genre.
	 */
	public static function rank($image)
	{
		$image = trim((string) $image);
		if ($image === '') {
			return null;
		}
		$meta = self::rankMeta();
		if ($meta['set'] === '' || $meta['ext'] === '') {
			return null;
		}
		return self::https(base_url(\Location::rank($meta['set'], $image, $meta['ext'])));
	}

	/**
	 * The sim's active rank set + file extension, resolved once per request.
	 *
	 * Memoized because a rank ladder or a manifest asks for this per row and
	 * the answer is a sim-wide setting. Loads its own models: this is called
	 * from both the snapshot builder and the API's /ranks endpoint, which have
	 * no loader in common. CI's model loader is idempotent, so the repeat
	 * calls cost nothing.
	 */
	private static function rankMeta()
	{
		if (self::$rankMeta === null) {
			$ci =& get_instance();
			$ci->load->model('settings_model', 'sc_settings');
			$ci->load->model('ranks_model',    'sc_ranks');

			$set = $ci->sc_settings->get_setting('display_rank');
			$ext = '';
			if ($set !== false && $set !== '') {
				$cat = $ci->sc_ranks->get_rankcat($set);
				if ($cat && isset($cat->rankcat_extension)) {
					$ext = (string) $cat->rankcat_extension;
				}
			}
			self::$rankMeta = array(
				'set' => ($set !== false ? (string) $set : ''),
				'ext' => $ext,
			);
		}
		return self::$rankMeta;
	}
}
