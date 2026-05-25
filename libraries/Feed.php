<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Builds the post RSS feed. The Feed controller's posts() shim delegates here
 * so the feed can live inside the suite.
 *
 * The library handles every cross-extension integration we know about
 * (mission_post_summary, ordered_mission_posts, content_filter) so the feed
 * stays coherent no matter which suite feature owns the file. Integrations
 * activate based on `$config['extensions']['enabled']`, so a disabled feature
 * stays inert.
 */
class Feed
{
	public static function buildPosts()
	{
		$ci =& get_instance();

		$ci->load->model('posts_model', 'posts');
		$ci->load->model('missions_model', 'mis');
		$ci->load->helper('xml');
		$ci->load->library('session');

		$posts = $ci->posts->get_post_list(null, 'desc', $ci->config->item('rss_num_entries'), 0, 'activated');

		$data = array();
		$extensionsConfig = $ci->config->item('extensions');

		// Feature flags from the merged suite config (state row over
		// config.json defaults) so integrations stay active even after
		// the standalone extension has been disabled.
		$suiteFeatures = Config::features();
		$summaryActive = in_array('nova_ext_mission_post_summary', $extensionsConfig['enabled'])
			|| ! empty($suiteFeatures['summary']);
		$orderedActive = in_array('nova_ext_ordered_mission_posts', $extensionsConfig['enabled'])
			|| ! empty($suiteFeatures['ordered_mission_posts']);

		$json = array();
		$contentFilterPath = APPPATH.'extensions/nova_ext_content_filter/config.json';
		if (file_exists($contentFilterPath)) {
			$json = json_decode(file_get_contents($contentFilterPath), true);
		}

		// Pull summary + ordered labels from the suite's merged config
		// (state row over config.json defaults). Fall back to the
		// standalone's own config.json if the suite hasn't taken those
		// over yet - keeps the feed correct when standalone is still
		// in use.
		$suite       = Config::load();
		$summaryJson = isset($suite['nova_ext_mission_post_summary'])
			? array('nova_ext_mission_post_summary' => $suite['nova_ext_mission_post_summary'])
			: array();
		if (empty($summaryJson)) {
			$summaryPath = APPPATH.'extensions/nova_ext_mission_post_summary/config.json';
			if (file_exists($summaryPath)) {
				$summaryJson = json_decode(file_get_contents($summaryPath), true);
			}
		}

		$summaryLabel = isset($summaryJson['nova_ext_mission_post_summary']['nova_ext_mission_post_summary'])
			? $summaryJson['nova_ext_mission_post_summary']['nova_ext_mission_post_summary']['value']
			: 'Summary';

		$jsonOrder = isset($suite['nova_ext_ordered_mission_posts'])
			? array('nova_ext_ordered_mission_posts' => $suite['nova_ext_ordered_mission_posts'])
			: array();
		if (empty($jsonOrder)) {
			$orderPath = APPPATH.'extensions/nova_ext_ordered_mission_posts/config.json';
			if (file_exists($orderPath)) {
				$jsonOrder = json_decode(file_get_contents($orderPath), true);
			}
		}

		$editDayLabel = isset($jsonOrder['nova_ext_ordered_mission_posts']['label_view_prefix'])
			? $jsonOrder['nova_ext_ordered_mission_posts']['label_view_prefix']['value']
			: 'Mission Day';
		$editDateLabel = isset($jsonOrder['nova_ext_ordered_mission_posts']['label_view_prefix'])
			? $jsonOrder['nova_ext_ordered_mission_posts']['label_view_prefix']['value']
			: 'Date';
		$editStartDateLabel = isset($jsonOrder['nova_ext_ordered_mission_posts']['label_view_prefix'])
			? $jsonOrder['nova_ext_ordered_mission_posts']['label_view_prefix']['value']
			: 'Stardate';
		$viewConcatLabel = isset($jsonOrder['nova_ext_ordered_mission_posts']['label_view_concat'])
			? $jsonOrder['nova_ext_ordered_mission_posts']['label_view_concat']['value']
			: 'at';
		$viewSuffixLabel = isset($jsonOrder['nova_ext_ordered_mission_posts']['label_view_suffix'])
			? $jsonOrder['nova_ext_ordered_mission_posts']['label_view_suffix']['value']
			: '';

		if ($posts->num_rows() > 0) {
			$i = 1;
			foreach ($posts->result() as $post) {
				if (in_array('nova_ext_content_filter', $extensionsConfig['enabled'])) {
					if ($post->language != 100) {
						$json['default']['language'] = $post->language;
					}
					if ($post->sex != 100) {
						$json['default']['sex'] = $post->sex;
					}
					if ($post->violence != 100) {
						$json['default']['violence'] = $post->violence;
					}
				}

				$data['entries'][$i]['link'] = site_url('sim/viewpost/'.$post->post_id);

				$title = $post->post_title;
				// PostNumber is only loaded when the suite's ordered feature
				// is enabled. The standalone keeps its own numbering in its
				// own Feed shim, so we no-op here if our class isn't loaded.
				if ($orderedActive && class_exists('nova_ext_sim_central\\PostNumber')) {
					$number = PostNumber::forPost($post->post_id, $post->post_mission);
					if ($number !== null) {
						$title = 'Post '.$number.' - '.$title;
					}
				}
				$data['entries'][$i]['title'] = $title;
				$data['entries'][$i]['date'] = $post->post_date;

				$authors = explode(',', $post->post_authors);
				foreach ($authors as $value) {
					$authors['full_names'][] = $ci->char->get_character_name($value, true);
				}

				$post_header  = ucfirst(lang('labels_a').' '.lang('global_missionpost').' '.lang('labels_by'));
				$post_header .= ' '.implode(', ', $authors['full_names'])."\r\n\r\n";
				$post_header .= '<b>'.ucfirst(lang('global_mission')).'</b> - '.$ci->mis->get_mission($post->post_mission, 'mission_title')."\r\n";
				$post_header .= ( ! empty($post->post_location)) ? '<b>'.ucfirst(lang('labels_location')).'</b> - '.$post->post_location."\r\n" : '';

				$timeline = $post->post_timeline;

				if ($summaryActive) {
					$post_header .= ( ! empty($post->nova_ext_mission_post_summary))
						? '<b>'.ucfirst(lang($summaryLabel)).'</b> - '.$post->nova_ext_mission_post_summary."\r\n"
						: '';
				}

				if (in_array('nova_ext_content_filter', $extensionsConfig['enabled'])) {
					$post_header .= '<b>'.ucfirst(lang('Content Level')).'</b> - '.$json['default']['language'].$json['default']['sex'].$json['default']['violence']."\r\n";
				}

				if ($orderedActive) {
					$query = $ci->db->get_where('missions', array('mission_id' => $post->post_mission));
					$model = ($query->num_rows() > 0) ? $query->row() : false;

					$data['mission_day']  = '';
					$data['mission_time'] = '';
					$viewPrefixLabel = '';

					if ( ! empty($model)) {
						if ($model->mission_ext_ordered_config_setting == 'day_time') {
							if ($model->mission_ext_ordered_legacy_mode == 1) {
								$data['mission_day']  = 'post_chronological_mission_post_day';
								$data['mission_time'] = 'post_chronological_mission_post_time';
							} else {
								$data['mission_day']  = 'nova_ext_ordered_post_day';
								$data['mission_time'] = 'nova_ext_ordered_post_time';
							}
							$viewPrefixLabel = $editDayLabel;
						} elseif ($model->mission_ext_ordered_config_setting == 'date_time') {
							$data['mission_day']  = 'nova_ext_ordered_post_date';
							$data['mission_time'] = 'nova_ext_ordered_post_time';
							$viewPrefixLabel = $editDateLabel;
						} elseif ($model->mission_ext_ordered_config_setting == 'stardate') {
							$data['mission_day']  = 'nova_ext_ordered_post_stardate';
							$data['mission_time'] = 'nova_ext_ordered_post_time';
							$viewPrefixLabel = $editStartDateLabel;
						}
					}

					if ( ! empty($data['mission_day'])) {
						$column     = $data['mission_day'];
						$timeColumn = $data['mission_time'];
						$timeline   = $viewPrefixLabel.' '.$post->$column.' '.$viewConcatLabel.' '.$post->$timeColumn.' '.$viewSuffixLabel;
					} else {
						$timeline = $post->post_timeline;
					}
				}

				$post_header .= ( ! empty($timeline))
					? '<b>'.ucfirst(lang('labels_timeline')).'</b> - '.$timeline."\r\n"
					: '';
				$post_header .= "\r\n";

				$data['entries'][$i]['content'] = nl2br($post_header.$post->post_content);

				if (in_array('nova_ext_content_filter', $extensionsConfig['enabled'])) {
					if ($json['default']['language'] == 3 || $json['default']['sex'] == 3 || $json['default']['violence'] == 3) {
						if ( ! \Auth::is_logged_in()) {
							$post->post_content = 'This post contains content viewable only to persons 18 years of age or older, and is not available for Public viewing.';
							$data['entries'][$i]['content'] = nl2br($post_header.$post->post_content);
						}
					}
				}

				++$i;
			}
		}

		return $data;
	}
}
