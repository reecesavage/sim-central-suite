<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Logic the Write controller's _email shim delegates to.
 *
 * Two responsibilities, both running before parent::_email so we can change
 * the template variables Nova will substitute into the email body:
 *
 *   1. Prefix "Post N -" to $data['title'] for `post` announcements when the
 *      mission has post numbering enabled.
 *   2. Replace $data['timeline'] with the configured ordered timeline string
 *      (e.g. "Mission Day 2 at 2300") for `post` and `post_save` emails so
 *      both the announcement and the draft-saved email match what the
 *      website shows.
 *
 * Timeline used to be rewritten by a parser_events listener that ran on the
 * parsed email HTML; doing it here means the suite no longer depends on the
 * parser_events Nova mod.
 */
class Email
{
	public static function filter($type, $data)
	{
		$ci =& get_instance();
		$missionId = $ci->input->post('mission', true);

		if (empty($missionId)) {
			return $data;
		}

		if (in_array($type, array('post', 'post_save'), true)) {
			$timeline = self::orderedTimeline($missionId);
			if ($timeline !== null) {
				$data['timeline'] = $timeline;
			}
		}

		if ($type === 'post') {
			// The post has been inserted by the time _email runs; the most
			// recent activated post for this mission is the one being
			// announced. Look it up by post_id so the chronological number
			// matches the website even when the new post sorts ahead of
			// existing ones.
			$latestQuery = $ci->db
				->select('post_id')
				->where('post_mission', $missionId)
				->where('post_status', 'activated')
				->order_by('post_date', 'desc')
				->limit(1)
				->get('posts');
			$latest = ($latestQuery->num_rows() > 0) ? $latestQuery->row() : false;

			if ( ! empty($latest)) {
				$number = PostNumber::forPost($latest->post_id, $missionId);
				if ($number !== null) {
					$data['title'] = 'Post '.$number.' - '.$data['title'];
				}
			}
		}

		return $data;
	}

	/**
	 * Build the ordered timeline string for a mission from its config + the
	 * submitted POST values, or NULL if the mission doesn't use an ordered
	 * timeline (Nova default).
	 */
	private static function orderedTimeline($missionId)
	{
		$ci =& get_instance();

		$query = $ci->db->get_where('missions', array('mission_id' => $missionId));
		$model = ($query->num_rows() > 0) ? $query->row() : false;
		if (empty($model)) {
			return null;
		}

		$labels = self::labels();

		if ($model->mission_ext_ordered_config_setting == 'day_time') {
			if ($model->mission_ext_ordered_legacy_mode == 1) {
				$dayCol  = 'post_chronological_mission_post_day';
				$timeCol = 'post_chronological_mission_post_time';
			} else {
				$dayCol  = 'nova_ext_ordered_post_day';
				$timeCol = 'nova_ext_ordered_post_time';
			}
			$prefix = $labels['day'];
		} elseif ($model->mission_ext_ordered_config_setting == 'date_time') {
			$dayCol  = 'nova_ext_ordered_post_date';
			$timeCol = 'nova_ext_ordered_post_time';
			$prefix  = $labels['date'];
		} elseif ($model->mission_ext_ordered_config_setting == 'stardate') {
			$dayCol  = 'nova_ext_ordered_post_stardate';
			$timeCol = 'nova_ext_ordered_post_time';
			$prefix  = $labels['stardate'];
		} else {
			return null;
		}

		$dayValue  = $ci->input->post($dayCol);
		$timeValue = $ci->input->post($timeCol);

		// HTML5 <input type="time"> submits HH:MM; storage is HHmm. The
		// TimelineFormat helper normalises whichever shape arrives here.
		return TimelineFormat::buildLine(
			$prefix, $dayCol, $dayValue, $timeValue, $labels['concat'], $labels['suffix']
		);
	}

	private static function labels()
	{
		$json    = Config::load();
		$section = isset($json['nova_ext_ordered_mission_posts']) ? $json['nova_ext_ordered_mission_posts'] : array();
		return array(
			'day'      => isset($section['label_edit_day'])       ? $section['label_edit_day']['value']       : 'Mission Day',
			'date'     => isset($section['label_edit_date'])      ? $section['label_edit_date']['value']      : 'Date',
			'stardate' => isset($section['label_edit_startdate']) ? $section['label_edit_startdate']['value'] : 'Stardate',
			'concat'   => isset($section['label_view_concat'])    ? $section['label_view_concat']['value']    : 'at',
			'suffix'   => isset($section['label_view_suffix'])    ? $section['label_view_suffix']['value']    : '',
		);
	}
}
