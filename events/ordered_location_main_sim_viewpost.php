<?php

$this->event->listen(['location', 'view', 'data', 'main', 'sim_viewpost'], function($event){

	$id   = (is_numeric($this->uri->segment(3))) ? $this->uri->segment(3) : false;
	$post = $id ? $this->posts->get_post($id) : null;

	$extensionsConfig  = $this->config->item('extensions');
	$json = \nova_ext_sim_central\Config::load();

	$editDayLabel = isset($json['nova_ext_ordered_mission_posts']['label_edit_day'])
		? $json['nova_ext_ordered_mission_posts']['label_edit_day']['value']
		: 'Mission Day';
	$editDateLabel = isset($json['nova_ext_ordered_mission_posts']['label_edit_date'])
		? $json['nova_ext_ordered_mission_posts']['label_edit_date']['value']
		: 'Date';
	$editStartDateLabel = isset($json['nova_ext_ordered_mission_posts']['label_edit_startdate'])
		? $json['nova_ext_ordered_mission_posts']['label_edit_startdate']['value']
		: 'Stardate';
	$viewConcatLabel = isset($json['nova_ext_ordered_mission_posts']['label_view_concat'])
		? $json['nova_ext_ordered_mission_posts']['label_view_concat']['value']
		: 'at';
	$viewSuffixLabel = isset($json['nova_ext_ordered_mission_posts']['label_view_suffix'])
		? $json['nova_ext_ordered_mission_posts']['label_view_suffix']['value']
		: '';

	if (empty($post->post_mission)) {
		return;
	}

	$query = $this->db->get_where('missions', array('mission_id' => $post->post_mission));
	$model = ($query->num_rows() > 0) ? $query->row() : false;
	if (empty($model)) {
		return;
	}

	$flag   = false;
	$column = '';
	if ($model->mission_ext_ordered_config_setting == 'day_time') {
		if ($model->mission_ext_ordered_legacy_mode == 1) {
			$column     = 'post_chronological_mission_post_day';
			$columnTime = 'post_chronological_mission_post_time';
		} else {
			$column     = 'nova_ext_ordered_post_day';
			$columnTime = 'nova_ext_ordered_post_time';
		}
		$viewPrefixLabel = $editDayLabel;
		$flag = true;
	} elseif ($model->mission_ext_ordered_config_setting == 'date_time') {
		$column     = 'nova_ext_ordered_post_date';
		$columnTime = 'nova_ext_ordered_post_time';
		$viewPrefixLabel = $editDateLabel;
		$flag = true;
	} elseif ($model->mission_ext_ordered_config_setting == 'stardate') {
		$column     = 'nova_ext_ordered_post_stardate';
		$columnTime = 'nova_ext_ordered_post_time';
		$viewPrefixLabel = $editStartDateLabel;
		$flag = true;
	}

	if ( ! $flag) {
		return;
	}

	$this->db->select('post_id');
	$this->db->from('posts');
	$this->db->where('post_mission', $event['data']['mission_id']);
	$this->db->where('post_status', 'activated');

	if ($model->mission_ext_ordered_post_numbering == 1) {
		$this->db->order_by('post_date', 'asc');
	} elseif ( ! empty($column)) {
		$cast = ($column == 'nova_ext_ordered_post_date') ? 'DATE' : 'UNSIGNED';
		$this->db->order_by('cast('.$column.' as '.$cast.')', 'desc');
		$this->db->order_by($columnTime, 'desc');
	} else {
		$this->db->order_by('post_date', 'asc');
	}

	$nextQuery = $this->db->get();
	$next = ($nextQuery->num_rows() > 0) ? $nextQuery->result_array() : false;

	$nextArray = array_map(function($value){
		return $value['post_id'];
	}, $next);
	if ( ! empty($nextArray)) {
		$arrayValue = array_search($event['data']['post_id'], $nextArray);
		$nextId = isset($nextArray[$arrayValue + 1]) ? $nextArray[$arrayValue + 1] : 0;
		$prevId = isset($nextArray[$arrayValue - 1]) ? $nextArray[$arrayValue - 1] : 0;

		if ( ! empty($nextId)) {
			$event['data']['next'] = $nextId;
		} elseif (isset($event['data']['next'])) {
			unset($event['data']['next']);
		}

		if ( ! empty($prevId)) {
			$event['data']['prev'] = $prevId;
		} elseif (isset($event['data']['prev'])) {
			unset($event['data']['prev']);
		}
	}

	$event['data']['timeline'] = \nova_ext_sim_central\TimelineFormat::buildLine(
		$viewPrefixLabel, $column, $post->$column,
		$post->$columnTime, $viewConcatLabel, $viewSuffixLabel
	);
});
