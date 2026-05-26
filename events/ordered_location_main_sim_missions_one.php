<?php

$this->event->listen(['location', 'view', 'data', 'main', 'sim_missions_one'], function($event){

	$this->config->load('extensions');
	$extensionsConfig = $this->config->item('extensions');

	$json = \nova_ext_sim_central\Config::load();

	$editDayLabel = isset($json['nova_ext_ordered_mission_posts']['label_view_prefix'])
		? $json['nova_ext_ordered_mission_posts']['label_view_prefix']['value']
		: 'Mission Day';
	$editDateLabel = isset($json['nova_ext_ordered_mission_posts']['label_view_prefix'])
		? $json['nova_ext_ordered_mission_posts']['label_view_prefix']['value']
		: 'Date';
	$editStartDateLabel = isset($json['nova_ext_ordered_mission_posts']['label_view_prefix'])
		? $json['nova_ext_ordered_mission_posts']['label_view_prefix']['value']
		: 'Stardate';
	$viewConcatLabel = isset($json['nova_ext_ordered_mission_posts']['label_view_concat'])
		? $json['nova_ext_ordered_mission_posts']['label_view_concat']['value']
		: 'at';
	$viewSuffixLabel = isset($json['nova_ext_ordered_mission_posts']['label_view_suffix'])
		? $json['nova_ext_ordered_mission_posts']['label_view_suffix']['value']
		: '';
	$postOrderColumnFallback = isset($json['setting']['post_order_column_fallback'])
		? $json['setting']['post_order_column_fallback']
		: 'post_date';

	$event['data']['posts'] = array();

	$query = $this->db->get_where('missions', array('mission_id' => $event['data']['mission']));
	$model = ($query->num_rows() > 0) ? $query->row() : false;
	if (empty($model)) {
		return;
	}

	$data = array('mission_day' => '', 'mission_time' => '');
	$viewPrefixLabel = '';

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

	$this->db->from('posts');
	$this->db->where('post_mission', $event['data']['mission']);
	$this->db->where('post_status', 'activated');
	if ( ! empty($data['mission_day'])) {
		$column     = $data['mission_day'];
		$timeColumn = $data['mission_time'];
		$cast       = ($data['mission_day'] == 'nova_ext_ordered_post_date') ? 'DATE' : 'UNSIGNED';
		$this->db->order_by('cast('.$column.' as '.$cast.')', 'asc');
		$this->db->order_by($timeColumn, 'asc');
	} else {
		$this->db->order_by($postOrderColumnFallback, 'asc');
	}

	$this->db->limit(25, 0);
	$posts = $this->db->get();
	if ($posts->num_rows() > 0) {
		foreach ($posts->result() as $key => $post) {
			$i = $key + 1;
			if ($model->mission_ext_ordered_post_numbering == 1) {
				$title = "Post $i - $post->post_title";
			} else {
				$title = $post->post_title;
			}

			if ( ! empty($data['mission_day'])) {
				$column     = $data['mission_day'];
				$timeColumn = $data['mission_time'];
				$timeline   = \nova_ext_sim_central\TimelineFormat::buildLine(
					$viewPrefixLabel, $column, $post->$column,
					$post->$timeColumn, $viewConcatLabel, $viewSuffixLabel
				);
			} else {
				$timeline = $post->post_timeline;
			}

			$event['data']['posts'][] = array(
				'id'       => $post->post_id,
				'title'    => $title,
				'authors'  => $this->char->get_authors($post->post_authors, true, true),
				'timeline' => $timeline,
				'location' => $post->post_location,
			);
		}
	}
});
