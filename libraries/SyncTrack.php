<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Change tracking for incremental API consumers (v1.39.0).
 *
 * Every tracked row carries two suite-owned columns:
 *
 *   sc_content_hash        SHA-256 of a canonical serialization of the fields a
 *                          consumer would render
 *   sc_content_updated_at  unix seconds, moved ONLY when that hash moves
 *
 * The second column is what GET /posts?updated_since= filters on, and the
 * first is what a client compares to decide whether it already has the row.
 *
 * THE NO-OP SAVE GUARANTEE. Opening a post and pressing Save without editing
 * anything rewrites the row but does not change the hash, so the timestamp does
 * not move and a poller sees nothing to do. That property lives in exactly one
 * place: stamp() below, which writes only on a hash mismatch.
 *
 * WHY NOT DATABASE TRIGGERS. The obvious objection to hashing in PHP is that a
 * post can be edited through Nova's own controllers, which an extension does
 * not see. That is not true here: every write path in Nova core -
 * nova_write, nova_manage and nova_ajax alike - funnels through
 * Posts_model::create_mission_entry / update_post (and the personal-log and
 * character equivalents), and the suite already installs managed blocks that
 * override exactly those methods. Interception is total one layer below the
 * controllers.
 *
 * Triggers would also have cost us something we are not willing to spend: a
 * trigger that outlives the extension - because the suite was removed, or its
 * columns dropped - makes every UPDATE on nova_posts fail with "Unknown
 * column", which takes the sim's posting offline. They additionally need the
 * TRIGGER privilege and, on servers with binary logging and no SUPER,
 * log_bin_trust_function_creators - neither of which shared hosting reliably
 * grants. A hook we install and remove ourselves has none of those failure
 * modes.
 *
 * HASH VERSION. The recipe below is version 1. If it ever changes, bump
 * HASH_VERSION: the API reports it (hash_version on every list envelope) so a
 * client knows to treat all stored hashes as stale rather than diffing against
 * values computed under the old recipe.
 */
class SyncTrack
{
	/** Bump when the canonical serialization changes. Reported to clients. */
	const HASH_VERSION = 1;

	/**
	 * Field separator. Unit Separator cannot occur in Nova content (the API
	 * strips C0 controls on the way out and no editor emits it), so no field
	 * value can forge a boundary the way a comma or newline could.
	 */
	const SEP = "\x1f";

	private static $enabled = array();

	/**
	 * Tracking is live only once Setup database has added the columns. Until
	 * then every entry point is a no-op rather than a fatal, so a sim that
	 * upgrades the files but has not pressed the button keeps working.
	 */
	public static function enabled($table)
	{
		if ( ! isset(self::$enabled[$table])) {
			self::$enabled[$table] = Migrations::hasColumn($table, 'sc_content_hash')
				&& Migrations::hasColumn($table, 'sc_content_updated_at');
		}
		return self::$enabled[$table];
	}

	// ---------- canonical serialization ----------

	/** NULL and absent both serialize as ''; everything else as its string form. */
	private static function f($row, $field)
	{
		if ( ! is_object($row) || ! property_exists($row, $field) || $row->$field === null) {
			return '';
		}
		return (string) $row->$field;
	}

	private static function digest(array $parts)
	{
		return hash('sha256', implode(self::SEP, $parts));
	}

	/**
	 * Canonical hash of a posts row. Covers metadata as well as the body, so a
	 * retitle, a relocation or an author change is a change - not just an edit
	 * to post_content.
	 *
	 * The ordered-timeline and age-gate columns are included positionally even
	 * when those features are not installed (they serialize as ''), so the
	 * recipe does not depend on column presence. Turning one of those features
	 * ON does move every hash once, which is correct: the rows genuinely gained
	 * fields a consumer renders.
	 */
	public static function postHash($row)
	{
		return self::digest(array(
			self::f($row, 'post_title'),
			self::f($row, 'post_content'),
			self::f($row, 'post_location'),
			self::f($row, 'post_timeline'),
			self::f($row, 'post_mission'),
			self::f($row, 'post_authors'),
			self::f($row, 'post_status'),
			self::f($row, 'post_tags'),
			self::f($row, 'nova_ext_ordered_post_day'),
			self::f($row, 'nova_ext_ordered_post_time'),
			self::f($row, 'nova_ext_ordered_post_date'),
			self::f($row, 'nova_ext_ordered_post_stardate'),
			self::f($row, 'nova_ext_content_filter_age_gated'),
		));
	}

	/**
	 * Canonical hash of a personallogs row.
	 *
	 * log_tags is included where the delta spec listed only title/content/
	 * author/status: tags are rendered, and posts already hash post_tags, so
	 * leaving them out would have made a retag invisible to a poller on logs
	 * but visible on posts.
	 */
	public static function logHash($row)
	{
		return self::digest(array(
			self::f($row, 'log_title'),
			self::f($row, 'log_content'),
			self::f($row, 'log_author_character'),
			self::f($row, 'log_status'),
			self::f($row, 'log_tags'),
		));
	}

	/**
	 * Canonical hash of a character: the roster fields plus every visible bio
	 * value, ordered by field_id so the sequence is stable regardless of how
	 * the rows come back.
	 *
	 * display_name is included where the delta spec listed only first/last/
	 * suffix. It overrides all three on the manifest, so a rename through it
	 * would otherwise leave the hash - and therefore the sync - untouched.
	 * Hashed only when the Display Name feature has added the column, so sims
	 * without it are unaffected.
	 *
	 * @param object     $row  characters row
	 * @param array|null $bio  fieldId => value, queried when not supplied
	 */
	public static function characterHash($row, $bio = null)
	{
		if ($bio === null) {
			$bio = self::bioValues((int) self::f($row, 'charid'));
		}
		ksort($bio, SORT_NUMERIC);

		$parts = array(
			self::f($row, 'first_name'),
			self::f($row, 'last_name'),
			self::f($row, 'suffix'),
			self::f($row, 'crew_type'),
			self::f($row, 'rank'),
			self::f($row, 'position_1'),
			self::f($row, 'position_2'),
			self::f($row, 'user'),
			self::f($row, 'display_name'),
		);
		foreach ($bio as $fieldId => $value) {
			$parts[] = (string) (int) $fieldId;
			$parts[] = ($value === null) ? '' : (string) $value;
		}
		return self::digest($parts);
	}

	/** fieldId => value for a character's visible bio fields. */
	public static function bioValues($charId)
	{
		$charId = (int) $charId;
		if ($charId <= 0) {
			return array();
		}
		$ci =& get_instance();
		$rows = $ci->db
			->select('characters_data.data_field, characters_data.data_value')
			->from('characters_data')
			->join('characters_fields', 'characters_fields.field_id = characters_data.data_field', 'inner')
			->where('characters_data.data_char', $charId)
			->where('characters_fields.field_display', 'y')
			->get()->result();

		$out = array();
		foreach ($rows as $r) {
			$out[(int) $r->data_field] = $r->data_value;
		}
		return $out;
	}

	// ---------- stamping ----------

	/**
	 * Write the hash and move the timestamp, but only if the hash actually
	 * changed. Returns true when something was written.
	 *
	 * This is the single point the no-op-save guarantee depends on.
	 */
	private static function stamp($table, $pk, $id, $hash, $existingHash)
	{
		if ($existingHash !== null && (string) $existingHash === $hash) {
			return false;
		}
		$ci =& get_instance();
		$ci->db->where($pk, (int) $id)->update($table, array(
			'sc_content_hash'       => $hash,
			'sc_content_updated_at' => time(),
		));
		return true;
	}

	// ---------- write-side entry points (called from the model shims) ----------

	public static function onPostChanged($postId)
	{
		if ( ! self::enabled('posts')) { return; }
		$ci =& get_instance();
		$row = $ci->db->get_where('posts', array('post_id' => (int) $postId), 1)->row();
		if ($row) {
			self::stamp('posts', 'post_id', $postId, self::postHash($row),
				isset($row->sc_content_hash) ? $row->sc_content_hash : null);
		}
	}

	public static function onLogChanged($logId)
	{
		if ( ! self::enabled('personallogs')) { return; }
		$ci =& get_instance();
		$row = $ci->db->get_where('personallogs', array('log_id' => (int) $logId), 1)->row();
		if ($row) {
			self::stamp('personallogs', 'log_id', $logId, self::logHash($row),
				isset($row->sc_content_hash) ? $row->sc_content_hash : null);
		}
	}

	public static function onCharacterChanged($charId)
	{
		if ( ! self::enabled('characters')) { return; }
		$ci =& get_instance();
		$row = $ci->db->get_where('characters', array('charid' => (int) $charId), 1)->row();
		if ($row) {
			self::stamp('characters', 'charid', $charId, self::characterHash($row),
				isset($row->sc_content_hash) ? $row->sc_content_hash : null);
		}
	}

	// ---------- read-side lazy fill ----------

	/**
	 * Hash for a row the API is about to project, computing and storing it if
	 * the backfill left it null (see backfillTimestamps: seeding timestamps is
	 * one cheap UPDATE per table, but hashing every body in one request is
	 * not, so hashes fill in as rows are read).
	 *
	 * Safe to be lazy because the timestamp - the thing ?updated_since filters
	 * on - was already seeded, so nothing can be missed while hashes catch up.
	 * Writing here never moves the timestamp: discovering a hash is not the
	 * same event as the content changing.
	 *
	 * @param string $kind 'post' | 'log' | 'character'
	 */
	public static function hashFor($kind, $row)
	{
		static $map = array(
			'post'      => array('posts',        'post_id'),
			'log'       => array('personallogs', 'log_id'),
			'character' => array('characters',   'charid'),
		);
		if ( ! isset($map[$kind])) {
			return null;
		}
		list($table, $pk) = $map[$kind];
		if ( ! self::enabled($table) || ! is_object($row)) {
			return null;
		}

		if ( ! empty($row->sc_content_hash)) {
			return (string) $row->sc_content_hash;
		}

		// A character's hash needs its bio values, and a list projection does
		// not carry them - one extra query per un-hashed character, once.
		if ($kind === 'post')           { $hash = self::postHash($row); }
		elseif ($kind === 'log')        { $hash = self::logHash($row); }
		else                            { $hash = self::characterHash($row); }

		if ( ! property_exists($row, $pk)) {
			return $hash;
		}

		$ci =& get_instance();
		$update = array('sc_content_hash' => $hash);
		// Seed the timestamp too if the row predates even the backfill.
		if (empty($row->sc_content_updated_at)) {
			$update['sc_content_updated_at'] = time();
			$row->sc_content_updated_at      = $update['sc_content_updated_at'];
		}
		$ci->db->where($pk, (int) $row->$pk)->update($table, $update);
		$row->sc_content_hash = $hash;

		return $hash;
	}

	/** ISO 8601 in UTC with a Z suffix, or null. The API's timestamp format. */
	public static function iso($unix)
	{
		$unix = (int) $unix;
		return ($unix > 0) ? gmdate('Y-m-d\TH:i:s\Z', $unix) : null;
	}

	/**
	 * Parse an ?updated_since value. ISO 8601; a bare date or datetime with no
	 * offset is read as UTC, so a client that never learned the sim's timezone
	 * cannot be wrong. Returns unix seconds, or null when unparseable (the
	 * caller turns that into a 422 rather than silently ignoring the filter).
	 */
	public static function parseSince($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}
		// All-digits is a unix timestamp - convenient for scripts, and
		// unambiguous since no ISO 8601 datetime is bare digits.
		if (ctype_digit($value)) {
			return (int) $value;
		}
		try {
			$dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
		} catch (\Exception $e) {
			return null;
		}
		return $dt->getTimestamp();
	}

	// ---------- setup ----------

	/**
	 * Seed sc_content_updated_at for rows that predate the feature, using the
	 * best timestamp each table already has. One UPDATE per table, no content
	 * read - see hashFor() for why the hashes are not computed here.
	 *
	 * Idempotent: only ever touches rows where the column is still null, so
	 * re-running Setup database (or the automatic post-update housekeeping)
	 * costs one no-op statement per table.
	 *
	 * @return int rows seeded
	 */
	public static function backfillTimestamps()
	{
		$ci =& get_instance();
		$prefix = $ci->db->dbprefix;
		$seeded = 0;

		// Characters have no created/updated column of their own, so they start
		// from now: every character is "changed as of setup", which costs a
		// first-run client one full roster read and nothing after that.
		$plan = array(
			'posts'        => array('post_id', 'post_date'),
			'personallogs' => array('log_id',  'log_date'),
			'characters'   => array('charid',  null),
		);

		foreach ($plan as $table => $cols) {
			if ( ! self::enabled($table)) {
				continue;
			}
			list($pk, $dateCol) = $cols;
			$source = ($dateCol !== null && Migrations::hasColumn($table, $dateCol))
				? 'COALESCE(NULLIF(`'.$dateCol.'`, 0), '.time().')'
				: (string) time();

			$ci->db->query(
				'UPDATE `'.$prefix.$table.'` SET `sc_content_updated_at` = '.$source
				.' WHERE `sc_content_updated_at` IS NULL'
			);
			$seeded += (int) $ci->db->affected_rows();
		}

		return $seeded;
	}
}
