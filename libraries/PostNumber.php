<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Shared "what number is this post in its mission?" helper used by the
 * post-email shim and the RSS feed. Mirrors the website's chronological
 * sort exactly so the numbering is consistent across every surface.
 */
class PostNumber
{
	private static $cache = array();

	/**
	 * 1-based chronological position of $postId within $missionId's
	 * activated posts, or NULL when post numbering is not enabled for
	 * that mission or the post can't be located.
	 */
	public static function forPost($postId, $missionId)
	{
		if ( ! isset(self::$cache[$missionId])) {
			self::$cache[$missionId] = self::computeOrder($missionId);
		}

		$order = self::$cache[$missionId];
		if ($order === false) {
			return null;
		}

		return isset($order[$postId]) ? $order[$postId] : null;
	}

	private static function computeOrder($missionId)
	{
		$ci =& get_instance();

		$missionQuery = $ci->db->get_where('missions', array('mission_id' => $missionId));
		$mission = ($missionQuery->num_rows() > 0) ? $missionQuery->row() : false;

		if (empty($mission) || $mission->mission_ext_ordered_post_numbering != 1) {
			return false;
		}

		$sort = self::sortColumns(
			$mission->mission_ext_ordered_config_setting,
			$mission->mission_ext_ordered_legacy_mode
		);

		$ci->db->select('post_id');
		$ci->db->from('posts');
		$ci->db->where('post_mission', $missionId);
		$ci->db->where('post_status', 'activated');

		if ($sort !== null) {
			$cast = ($sort['day'] === 'nova_ext_ordered_post_date') ? 'DATE' : 'UNSIGNED';
			$ci->db->order_by('cast('.$sort['day'].' as '.$cast.')', 'asc');
			$ci->db->order_by($sort['time'], 'asc');
		} else {
			$ci->db->order_by('post_date', 'asc');
		}

		$order = array();
		$position = 1;
		foreach ($ci->db->get()->result() as $row) {
			$order[$row->post_id] = $position++;
		}

		return $order;
	}

	private static function sortColumns($config, $legacyMode)
	{
		if ($config === 'day_time') {
			return ($legacyMode == 1)
				? array('day' => 'post_chronological_mission_post_day', 'time' => 'post_chronological_mission_post_time')
				: array('day' => 'nova_ext_ordered_post_day',          'time' => 'nova_ext_ordered_post_time');
		}
		if ($config === 'date_time') {
			return array('day' => 'nova_ext_ordered_post_date', 'time' => 'nova_ext_ordered_post_time');
		}
		if ($config === 'stardate') {
			return array('day' => 'nova_ext_ordered_post_stardate', 'time' => 'nova_ext_ordered_post_time');
		}
		return null;
	}
}
