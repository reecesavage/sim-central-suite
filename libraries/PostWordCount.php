<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Word count totals per mission, computed from activated post content.
 *
 * The mission list pages (admin manage_missions, main sim_missions_all)
 * each render multiple missions, so we batch-fetch the post bodies for
 * every mission on the page in a single query, then flatten each with
 * PostText and count the tokens. Results are cached per request so
 * repeated calls (e.g. across all three status tabs) only hit the DB once.
 *
 * PHP-side counting was chosen over SQL approximation
 * (LENGTH - LENGTH(REPLACE(' '))) because the SQL approach overcounts
 * runs of whitespace, miscounts posts with markdown/BBCode tokens, and
 * cannot flatten markup at all - which is most of the work here.
 */
class PostWordCount
{
	private static $cache = array();

	/**
	 * The one canonical word-count definition used everywhere in the suite:
	 * flatten the rich text to plain prose, then treat each remaining token as
	 * a word. Used for the mission-page figures, the per-post REST field, and
	 * mission totals, so they always agree.
	 *
	 * PostText::flatten(), not strip_tags() (v1.37.0) - strip_tags got four
	 * things wrong, every one of them visible in the number a reader saw:
	 *
	 *   - It deleted a block tag leaving nothing behind, so "</p><p>" fused the
	 *     words either side and a post lost a word per paragraph break.
	 *   - It read a bare "<" in prose ("the fee is <20 credits") as a tag opener
	 *     and ate to the next ">" - or to the END of the body - so most of the
	 *     post went uncounted. Not a fused pair: the whole remainder.
	 *   - It left entities as text, so "&nbsp;" counted as the word "nbsp".
	 *   - It removed <script>/<style> but KEPT their contents, so the CSS block
	 *     a Word paste carries counted as several hundred words.
	 *
	 * And flatten(), not excerpt(): excerpt() bounds its input to 16KB, which is
	 * right for a 300-character preview and wrong here. post_content is longtext,
	 * and a word count has to see the whole body.
	 *
	 * The tokeniser is Unicode-aware for the same reason. str_word_count()
	 * classifies BYTES with the C library's isalpha() under the server's locale,
	 * so "Bjorn" with an umlaut scored 3 - the two bytes of the accented letter
	 * are not alpha, so the word split in the middle. Every word carrying a
	 * non-ASCII letter was over-counted, on every host, whatever went in. A
	 * hyphenated or apostrophised word now scores once as well, and digits count
	 * as words, which str_word_count never did.
	 */
	public static function countText($content)
	{
		$text = PostText::flatten($content);
		if ($text === '') {
			return 0;
		}
		$count = preg_match_all('/[\p{L}\p{N}]+(?:[\'\x{2019}\-][\p{L}\p{N}]+)*/u', $text);

		// preg_match_all returns false only on a PCRE failure; flatten() has
		// already guaranteed valid UTF-8, but never report 0 words for a body
		// that plainly has some.
		return ($count === false) ? str_word_count($text) : $count;
	}

	/**
	 * Returns array(missionId => word_count) for the given mission IDs.
	 * Missions with no activated posts come back with 0.
	 */
	public static function forMissions(array $missionIds)
	{
		$missionIds = array_unique(array_filter(array_map('intval', $missionIds)));
		if (empty($missionIds)) {
			return array();
		}

		$todo = array();
		foreach ($missionIds as $id) {
			if ( ! isset(self::$cache[$id])) {
				$todo[] = $id;
			}
		}

		if ( ! empty($todo)) {
			$counts = array_fill_keys($todo, 0);

			$ci =& get_instance();
			$ci->db->select('post_mission, post_content');
			$ci->db->from('posts');
			$ci->db->where_in('post_mission', $todo);
			$ci->db->where('post_status', 'activated');
			$query = $ci->db->get();

			foreach ($query->result() as $row) {
				$counts[(int) $row->post_mission] += self::countText($row->post_content);
			}

			foreach ($counts as $id => $n) {
				self::$cache[$id] = $n;
			}
		}

		$out = array();
		foreach ($missionIds as $id) {
			$out[$id] = isset(self::$cache[$id]) ? self::$cache[$id] : 0;
		}
		return $out;
	}

	public static function forMission($missionId)
	{
		$map = self::forMissions(array($missionId));
		return isset($map[(int) $missionId]) ? $map[(int) $missionId] : 0;
	}
}
