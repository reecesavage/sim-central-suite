<?php

$this->event->listen(['location', 'view', 'data', 'main', 'sim_listposts'], function($event){

	$postsModel = isset($event['data']['posts']) ? $event['data']['posts'] : array();
	$id = 0;
	if ( ! empty($postsModel)) {
		foreach ($postsModel as $postModel) {
			$id = $postModel['mission_id'];
			break;
		}
	}

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

	$query = $this->db->get_where('missions', array('mission_id' => $id));
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
	$this->db->where('post_mission', $id);
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

	$offset = $this->uri->segment(5, 0, true);
	$this->db->limit($this->pagination->per_page, $offset);

	$posts = $this->db->get();
	if ($posts->num_rows() > 0) {
		foreach ($posts->result() as $key => $post) {
			$i = $offset + $key + 1;
			if ($model->mission_ext_ordered_post_numbering == 1) {
				$title = "Post $i - $post->post_title";
			} else {
				$title = $post->post_title;
			}

			if ( ! empty($data['mission_day'])) {
				$column     = $data['mission_day'];
				$timeColumn = $data['mission_time'];
				$timeline   = $viewPrefixLabel.' '.$post->$column.' '.$viewConcatLabel.' '.$post->$timeColumn.' '.$viewSuffixLabel;
			} else {
				$timeline = $post->post_timeline;
			}

			$event['data']['posts'][] = array(
				'id'         => $post->post_id,
				'title'      => $title,
				'author'     => $this->char->get_authors($post->post_authors, true, true),
				'timeline'   => $timeline,
				// 'date' mirrors 'timeline' for the stock core view, which Nova
				// renders then discards before our output listener replaces it.
				'date'       => $timeline,
				'location'   => $post->post_location,
				'mission'    => $this->mis->get_mission($post->post_mission, 'mission_title'),
				'mission_id' => $post->post_mission,
			);
		}
	}
});

$this->event->listen(['location', 'view', 'output', 'main', 'sim_listposts'], function($event){
	$event['output'] = $this->extension['nova_ext_sim_central']
		->view('ordered_sim_listposts', $this->skin, 'main', $event['data']);
});
