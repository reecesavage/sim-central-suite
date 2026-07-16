<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Astrolabe snapshot builder (v1.30.0).
 *
 * Assembles ONE read-only JSON snapshot describing this sim - crew manifest,
 * stories (missions), recent posts, and counts - for the Astrolabe platform
 * to mirror on its per-game page. Served by the REST API's Api::snapshot()
 * endpoint (scope astrolabe:read); this class does the data assembly + caching
 * only, no auth.
 *
 * Rules baked in per the Astrolabe brief:
 *   - Every url / avatar_url / rank.image is an absolute https:// URL or null.
 *   - description / excerpt are plain text (HTML stripped) and length-capped.
 *   - Missing single value -> null; missing list -> [].
 *   - recent_posts capped at RECENT_LIMIT, newest first.
 *   - No private data: only public roster / character / player display-name /
 *     mission / post data. Never email, real name, IP, or account internals.
 *
 * The snapshot is cached in a settings row on a short TTL because Astrolabe
 * polls on a schedule (~15 min) - regenerating live on every request would be
 * wasteful. One consumer, so no cache-stampede concern.
 */
class AstrolabeSnapshot
{
	const CACHE_KEY    = 'astrolabe_snapshot_cache';
	const CACHE_LABEL  = 'Astrolabe snapshot cache (auto-generated - safe to delete)';
	const CACHE_TTL    = 600;   // 10 minutes
	const CAP_GAME     = 500;
	const CAP_STORY    = 300;
	const CAP_EXCERPT  = 300;
	const RECENT_LIMIT = 10;

	/**
	 * Return the snapshot as a JSON string, served from cache when fresh.
	 * Rebuilds and stores when the cache is missing or older than $ttl.
	 */
	public static function cached($ttl = self::CACHE_TTL)
	{
		$ci =& get_instance();
		$version = Config::version();
		$row = $ci->db->get_where('settings', array('setting_key' => self::CACHE_KEY), 1)->row();
		if ($row) {
			$blob = json_decode((string) $row->setting_value, true);
			// Version-aware: a suite update invalidates the cache immediately,
			// so the snapshot reflects new fields on the next request instead
			// of serving up to a full TTL of pre-update data.
			if (is_array($blob) && isset($blob['at'], $blob['json'])
				&& (isset($blob['ver']) && $blob['ver'] === $version)
				&& (time() - (int) $blob['at']) < (int) $ttl) {
				return $blob['json'];
			}
		}

		$json = json_encode(self::build(), JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			$json = json_encode(array('version' => 1, 'generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'error' => 'encode_failed'));
		}
		self::store($json);
		return $json;
	}

	private static function store($json)
	{
		$ci =& get_instance();
		$value = json_encode(array('at' => time(), 'ver' => Config::version(), 'json' => $json));
		$existing = $ci->db->get_where('settings', array('setting_key' => self::CACHE_KEY), 1);
		if ($existing->num_rows() > 0) {
			$ci->db->where('setting_id', $existing->row()->setting_id)
				->update('settings', array('setting_value' => $value, 'setting_label' => self::CACHE_LABEL));
		} else {
			$ci->db->insert('settings', array(
				'setting_key'          => self::CACHE_KEY,
				'setting_value'        => $value,
				'setting_label'        => self::CACHE_LABEL,
				'setting_user_created' => 'n',
			));
		}
	}

	/** Build the full snapshot array from Nova's models. */
	public static function build()
	{
		$ci =& get_instance();
		$ci->load->model('settings_model',   'sc_settings');
		$ci->load->model('depts_model',       'sc_dept');
		$ci->load->model('positions_model',   'sc_pos');
		$ci->load->model('characters_model',  'sc_char');
		$ci->load->model('ranks_model',       'sc_ranks');
		$ci->load->model('missions_model',    'sc_missions');
		// count_mission_posts() lives on the POSTS model, not missions.
		$ci->load->model('posts_model',       'sc_posts');

		// Each section is guarded so a model quirk on one sim degrades that
		// section (empty / null) rather than 500ing the whole snapshot -
		// Astrolabe then still gets a usable, cacheable payload.
		return array(
			'version'      => 1,
			'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'game'         => self::guard(function () { return self::game(); }, array('name' => 'Sim', 'url' => self::absUrl(''), 'description' => null)),
			'stats'        => self::guard(function () { return self::stats(); }, array('players' => null, 'characters' => null, 'stories' => null)),
			'manifest'       => self::guard(function () { return self::manifest(); }, array()),
			'stories'        => self::guard(function () { return self::stories(); }, array()),
			'recent_posts'   => self::guard(function () { return self::recentPosts(); }, array()),
			'open_positions' => self::guard(function () { return self::openPositions(); }, array()),
		);
	}

	/** Run a section builder, returning $fallback (and logging) on any error. */
	private static function guard(callable $fn, $fallback)
	{
		try {
			return $fn();
		} catch (\Throwable $e) {
			log_message('error', 'nova_ext_sim_central AstrolabeSnapshot section failed: '.$e->getMessage());
			return $fallback;
		}
	}

	// ---------- sections ----------

	private static function game()
	{
		$ci =& get_instance();
		$name = $ci->sc_settings->get_setting('sim_name');
		return array(
			'name'        => ($name !== false && $name !== '') ? self::dec((string) $name) : 'Sim',
			'url'         => self::absUrl(''),
			// Astrolabe owns the blurb (admins enter it on the Astrolabe side).
			'description' => null,
		);
	}

	private static function stats()
	{
		$ci =& get_instance();
		$players = (int) $ci->db->where('status', 'active')->from('users')->count_all_results();
		$chars   = (int) $ci->db->where_in('crew_type', array('active', 'npc'))->from('characters')->count_all_results();
		$stories = (int) $ci->sc_missions->count_missions();
		return array('players' => $players, 'characters' => $chars, 'stories' => $stories);
	}

	private static function manifest()
	{
		$ci =& get_instance();
		$out = array();

		$manifests = $ci->sc_dept->get_all_manifests();
		if ( ! $manifests || $manifests->num_rows() === 0) {
			return $out;
		}

		$rankMeta = self::rankMeta();
		$usedSlugs = array();

		foreach ($manifests->result() as $man) {
			$slug = self::uniqueSlug($man->manifest_name, (int) $man->manifest_id, $usedSlugs);

			$departments = array();

			// Top-level departments in this manifest. get_all_depts() reads a
			// dept_manifest WHERE set on the query builder first (Nova's own
			// pattern in nova_personnel).
			$ci->db->where('dept_manifest', (int) $man->manifest_id);
			$depts = $ci->sc_dept->get_all_depts();
			if ($depts && $depts->num_rows() > 0) {
				foreach ($depts->result() as $dept) {
					$deptEntry = self::departmentEntry((int) $dept->dept_id, $dept->dept_name, $rankMeta);
					if ($deptEntry !== null) {
						$departments[] = $deptEntry;
					}

					// Sub-departments render as their own department entries.
					$subs = $ci->sc_dept->get_sub_depts((int) $dept->dept_id);
					if ($subs && $subs->num_rows() > 0) {
						foreach ($subs->result() as $sub) {
							$subEntry = self::departmentEntry((int) $sub->dept_id, $sub->dept_name, $rankMeta);
							if ($subEntry !== null) {
								$departments[] = $subEntry;
							}
						}
					}
				}
			}

			$out[] = array(
				'name'        => self::dec((string) $man->manifest_name),
				'slug'        => $slug,
				'departments' => $departments,
			);
		}

		return $out;
	}

	/**
	 * Build one department entry: its positions' characters (active + npc),
	 * flattened and de-duplicated by charid. Returns null when the department
	 * has no shown characters (keeps empty departments out of the payload).
	 */
	private static function departmentEntry($deptId, $deptName, array $rankMeta)
	{
		$ci =& get_instance();
		$positions = $ci->sc_pos->get_dept_positions($deptId);
		if ( ! $positions || $positions->num_rows() === 0) {
			return null;
		}

		$characters = array();
		$seen = array();

		foreach ($positions->result() as $pos) {
			$chars = $ci->sc_char->get_characters_for_position((int) $pos->pos_id, array('ranks.rank_order' => 'asc'));
			if ( ! $chars || $chars->num_rows() === 0) {
				continue;
			}
			foreach ($chars->result() as $char) {
				// Public roster only: active players + NPCs. Excludes inactive
				// and the 'pending' the model already filters.
				if ( ! in_array($char->crew_type, array('active', 'npc'), true)) {
					continue;
				}
				$cid = (int) $char->charid;
				if (isset($seen[$cid])) {
					continue;
				}
				$seen[$cid] = true;
				$characters[] = self::character($char, $pos->pos_name, $rankMeta);
			}
		}

		if (empty($characters)) {
			return null;
		}

		return array(
			'department' => self::dec((string) $deptName),
			'characters' => $characters,
		);
	}

	private static function character($char, $positionName, array $rankMeta)
	{
		// Prefer the Display Name feature's override when present (parity with
		// the public manifest), else First / Last / Suffix. No rank prefix.
		$name = ( ! empty($char->display_name))
			? (string) $char->display_name
			: trim(($char->first_name ?? '').' '.($char->last_name ?? '').(empty($char->suffix) ? '' : ' '.$char->suffix));

		$rank = null;
		if ( ! empty($char->rank_name) || ! empty($char->rank_short_name) || ! empty($char->rank_image)) {
			$rank = array(
				'name'         => ! empty($char->rank_name) ? self::dec((string) $char->rank_name) : null,
				'abbreviation' => ! empty($char->rank_short_name) ? self::dec((string) $char->rank_short_name) : null,
				'image'        => self::rankImage(isset($char->rank_image) ? $char->rank_image : '', $rankMeta),
			);
		}

		return array(
			'name'       => $name !== '' ? self::dec($name) : '(unnamed)',
			'position'   => ($positionName !== '' && $positionName !== null) ? self::dec((string) $positionName) : null,
			'avatar_url' => self::absImg(isset($char->images) ? $char->images : ''),
			'url'        => self::absUrl('personnel/character/'.(int) $char->charid),
			'rank'       => $rank,
			'player'     => self::player(isset($char->user) ? (int) $char->user : 0),
		);
	}

	private static function stories()
	{
		$ci =& get_instance();
		$out = array();
		$missions = $ci->sc_missions->get_all_missions();
		if ( ! $missions || $missions->num_rows() === 0) {
			return $out;
		}
		foreach ($missions->result() as $m) {
			$out[] = array(
				'title'       => self::dec((string) $m->mission_name),
				'description' => self::plain(isset($m->mission_desc) ? $m->mission_desc : '', self::CAP_STORY),
				'status'      => isset($m->mission_status) ? (string) $m->mission_status : null,
				// Nova has no in-character free-text mission dates.
				'start_date'  => null,
				'end_date'    => null,
				// 'single' count_pref returns the row count; the default empty
				// pref hits no switch case and always returns 0.
				'posts_count' => (int) $ci->sc_posts->count_mission_posts((int) $m->mission_id, 'single'),
				'url'         => self::absUrl('sim/missions/id/'.(int) $m->mission_id),
			);
		}
		return $out;
	}

	private static function recentPosts()
	{
		$ci =& get_instance();
		$out = array();
		$rows = $ci->db
			->select('post_id, post_title, post_authors, post_content, post_date')
			->from('posts')
			->where('post_status', 'activated')
			->order_by('post_date', 'desc')
			->limit(self::RECENT_LIMIT)
			->get()->result();

		foreach ($rows as $p) {
			$out[] = array(
				'title'        => self::dec((string) $p->post_title),
				'authors'      => self::authorNames(isset($p->post_authors) ? $p->post_authors : ''),
				'published_at' => ! empty($p->post_date) ? gmdate('Y-m-d\TH:i:s\Z', (int) $p->post_date) : null,
				'excerpt'      => self::plain(isset($p->post_content) ? $p->post_content : '', self::CAP_EXCERPT),
				'url'          => self::absUrl('sim/viewpost/'.(int) $p->post_id),
			);
		}
		return $out;
	}

	/**
	 * Positions the sim is actively recruiting for - Nova's open-positions
	 * concept (pos_open > 0, displayed), the same set shown on the join page.
	 * Department labels match the manifest's where they share a department.
	 */
	private static function openPositions()
	{
		$ci =& get_instance();
		$out = array();
		$rows = $ci->sc_pos->get_open_positions('y', false);
		if ( ! $rows || $rows->num_rows() === 0) {
			return $out;
		}

		$deptNames = array();
		foreach ($rows->result() as $p) {
			$deptId = isset($p->pos_dept) ? (int) $p->pos_dept : 0;
			if ($deptId > 0 && ! array_key_exists($deptId, $deptNames)) {
				$n = $ci->sc_dept->get_dept($deptId, 'dept_name');
				$deptNames[$deptId] = ($n !== false && $n !== null && $n !== '') ? self::dec((string) $n) : null;
			}

			$out[] = array(
				'name'        => self::dec(isset($p->pos_name) ? (string) $p->pos_name : ''),
				'department'  => ($deptId > 0 && ! empty($deptNames[$deptId])) ? $deptNames[$deptId] : null,
				'openings'    => isset($p->pos_open) ? (int) $p->pos_open : 0,
				// true when featured on the sim's "top open positions" list.
				'top'         => (isset($p->pos_top_open) && $p->pos_top_open === 'y'),
				'description' => self::plain(isset($p->pos_desc) ? $p->pos_desc : '', self::CAP_STORY),
				// Best public destination for applying is the join page.
				'url'         => self::absUrl('main/join'),
			);
		}
		return $out;
	}

	// ---------- helpers ----------

	/**
	 * Decode HTML entities in a human-readable string so names arrive clean
	 * (e.g. "Security &amp; Tactical" -> "Security & Tactical"). json_encode
	 * handles output escaping, so decoding here is safe and non-double-encoding.
	 */
	private static function dec($s)
	{
		if ($s === null) {
			return null;
		}
		return html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	/** Public player display name for a user id (users.name), or null. */
	private static function player($userId)
	{
		static $cache = array();
		$userId = (int) $userId;
		if ($userId <= 0) {
			return null;
		}
		if ( ! array_key_exists($userId, $cache)) {
			$ci =& get_instance();
			$row = $ci->db->select('name')->get_where('users', array('userid' => $userId), 1)->row();
			$cache[$userId] = ($row && $row->name !== '' && $row->name !== null) ? self::dec((string) $row->name) : null;
		}
		return $cache[$userId] !== null ? array('name' => $cache[$userId]) : null;
	}

	/** Character display names from a CSV of charids (batched, cached). */
	private static function authorNames($csv)
	{
		$ids = array_values(array_filter(array_map('intval', explode(',', (string) $csv)), function ($v) { return $v > 0; }));
		if (empty($ids)) {
			return array();
		}
		$ci =& get_instance();
		$rows = $ci->db
			->select('charid, first_name, last_name, suffix')
			->from('characters')
			->where_in('charid', $ids)
			->get()->result();

		$byId = array();
		foreach ($rows as $r) {
			$byId[(int) $r->charid] = self::dec(trim(($r->first_name ?? '').' '.($r->last_name ?? '').(empty($r->suffix) ? '' : ' '.$r->suffix)));
		}
		$names = array();
		foreach ($ids as $id) {
			if (isset($byId[$id]) && $byId[$id] !== '') {
				$names[] = $byId[$id];
			}
		}
		return $names;
	}

	/** Resolve the sim's active rank set + file extension once per build. */
	private static function rankMeta()
	{
		$ci =& get_instance();
		$set = $ci->sc_settings->get_setting('display_rank');
		$ext = '';
		if ($set !== false && $set !== '') {
			$cat = $ci->sc_ranks->get_rankcat($set);
			if ($cat && isset($cat->rankcat_extension)) {
				$ext = (string) $cat->rankcat_extension;
			}
		}
		return array('set' => ($set !== false ? (string) $set : ''), 'ext' => $ext);
	}

	private static function rankImage($image, array $rankMeta)
	{
		$image = trim((string) $image);
		if ($image === '' || $rankMeta['set'] === '' || $rankMeta['ext'] === '') {
			return null;
		}
		$path = \Location::rank($rankMeta['set'], $image, $rankMeta['ext']);
		return self::https(base_url($path));
	}

	/** Absolute https URL for a site-relative path. */
	private static function absUrl($path)
	{
		return self::https(site_url($path));
	}

	/**
	 * Absolute https avatar URL from a character's images CSV (first entry),
	 * or null. Mirrors Nova's own rule: already-absolute stays, otherwise it's
	 * an asset under images/characters.
	 */
	private static function absImg($imagesCsv)
	{
		$imagesCsv = (string) $imagesCsv;
		if (trim($imagesCsv) === '') {
			return null;
		}
		$first = trim(explode(',', $imagesCsv)[0]);
		if ($first === '') {
			return null;
		}
		if (stripos($first, 'http://') === 0 || stripos($first, 'https://') === 0) {
			return self::https($first);
		}
		return self::https(base_url(\Location::asset('images/characters', $first)));
	}

	/** Force http -> https so Astrolabe never drops a URL. */
	private static function https($url)
	{
		return preg_replace('#^http://#i', 'https://', (string) $url);
	}

	/** Strip HTML, decode entities, collapse whitespace, cap length. */
	private static function plain($html, $cap)
	{
		$text = strip_tags((string) $html);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = trim(preg_replace('/\s+/u', ' ', $text));
		if ($text === '') {
			return null;
		}
		if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $cap) {
			return rtrim(mb_substr($text, 0, $cap - 1, 'UTF-8')).'…';
		}
		if (strlen($text) > $cap) {
			return rtrim(substr($text, 0, $cap - 1)).'…';
		}
		return $text;
	}

	/** URL-safe, unique-within-array slug for a manifest name. */
	private static function uniqueSlug($name, $id, array &$used)
	{
		$slug = strtolower(trim((string) $name));
		$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
		$slug = trim($slug, '-');
		if ($slug === '') {
			$slug = 'manifest-'.$id;
		}
		if (isset($used[$slug])) {
			$slug = $slug.'-'.$id;
		}
		$used[$slug] = true;
		return $slug;
	}
}
