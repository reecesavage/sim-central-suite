<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Logic the Write controller's _email shim delegates to. Everything here runs
 * before parent::_email so we can change the template variables Nova will
 * substitute into the email body. Each block is gated on its owning feature,
 * because this filter now runs for the Summary, URL Parser, AND Ordered
 * Mission Posts features (the write.txt shim is shared between them):
 *
 *   1. URL Parser  - expand [tag|title|display] shortcodes into links in the
 *                    body ($data['content']) for post/post_save/log/news.
 *   2. Summary     - append the per-post summary after the location line for
 *                    post/post_save announcements + draft-saved emails.
 *   3. Ordered     - replace $data['timeline'] with the configured ordered
 *                    string, and prefix "Post N -" to the title for `post`.
 *
 * These used to be done by parser_events listeners that ran on the parsed
 * email HTML; doing them here means the suite depends on no parse_string
 * events at all, so the third-party parser_events mod (MY_Parser.php) is not
 * required for any of these features.
 */
class Email
{
	public static function filter($type, $data)
	{
		$ci       = get_instance();
		$features = Config::features();

		// --- URL Parser ---------------------------------------------------
		// Expand [tag|title|display] shortcodes in the body. Runs before the
		// mission early-return so it also covers logs/news (no mission). Reuses
		// UrlParser - the same expander the on-site display uses - so the email
		// matches the website exactly.
		if ( ! empty($features['url_parser'])
			&& isset($data['content']) && $data['content'] !== ''
			&& in_array($type, array('post', 'post_save', 'log', 'news'), true)) {
			$parser = new UrlParser();
			$data['content'] = $parser->urlparser($data['content']);
		}

		$missionId = $ci->input->post('mission', true);
		if (empty($missionId)) {
			return $data;
		}

		// --- Mission Post Summary ----------------------------------------
		// Append the per-post summary right after the location line (matches
		// the old parser_events output).
		if ( ! empty($features['summary'])
			&& in_array($type, array('post', 'post_save'), true)) {
			$summaryLine = self::postSummaryLine();
			if ($summaryLine !== null) {
				$location = isset($data['location']) ? $data['location'] : '';
				$data['location'] = ($location !== '' ? $location."\n" : '').$summaryLine;
			}
		}

		// --- Ordered Mission Posts ---------------------------------------
		if ( ! empty($features['ordered_mission_posts'])) {
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
		}

		return $data;
	}

	/**
	 * The "Summary: ..." line to append to a mission-post email, or NULL when
	 * the summary mode is off or no summary was submitted. Ported from the old
	 * summary_parser_parse_string listener so the output matches: it reads the
	 * label from config and the value from the submitted post.
	 */
	private static function postSummaryLine()
	{
		$ci   = get_instance();
		$json = Config::load();

		if ( ! isset($json['setting']['summary_mode']) || (int) $json['setting']['summary_mode'] !== 1) {
			return null;
		}

		$value = $ci->input->post('nova_ext_mission_post_summary');
		if (empty($value)) {
			return null;
		}

		$label = isset($json['nova_ext_mission_post_summary']['nova_ext_mission_post_summary']['value'])
			? $json['nova_ext_mission_post_summary']['nova_ext_mission_post_summary']['value']
			: 'Summary';

		return $label.': '.$value;
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
