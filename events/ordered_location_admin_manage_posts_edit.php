<?php

$this->event->listen(['location', 'view', 'data', 'admin', 'manage_posts_edit'], function($event){

	$id   = (is_numeric($this->uri->segment(4))) ? $this->uri->segment(4) : false;
	$post = $id ? $this->posts->get_post($id) : null;

	$postDay      = $post ? $post->nova_ext_ordered_post_day  : 1;
	$postTime     = $post ? $post->nova_ext_ordered_post_time : '0000';
	$postDayName  = 'nova_ext_ordered_post_day';
	$postTimeName = 'nova_ext_ordered_post_time';
	if ( ! empty($post)) {
		$query = $this->db->get_where('missions', array('mission_id' => $post->post_mission));
		$model = ($query->num_rows() > 0) ? $query->row() : false;
		if ( ! empty($model)
			&& $model->mission_ext_ordered_legacy_mode == 1
			&& $model->mission_ext_ordered_config_setting == 'day_time') {
			$postDay      = $post->post_chronological_mission_post_day;
			$postTime     = $post->post_chronological_mission_post_time;
			$postDayName  = 'post_chronological_mission_post_day';
			$postTimeName = 'post_chronological_mission_post_time';
		}
	}

	// HTML5 <input type="time"> wants HH:MM. The DB stores HHmm to keep
	// the column as VARCHAR(4); ordered_db.php strips the colon back off
	// before the insert/update runs.
	$rawTime   = str_pad(preg_replace('/[^0-9]/', '', (string) $postTime), 4, '0', STR_PAD_LEFT);
	$timeValue = substr($rawTime, 0, 2).':'.substr($rawTime, 2, 2);

	$this->config->load('extensions');
	$extensionsConfig = $this->config->item('extensions');

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

	$editTimeLabel = isset($json['nova_ext_ordered_mission_posts']['label_edit_time'])
		? $json['nova_ext_ordered_mission_posts']['label_edit_time']['value']
		: 'Time';

	$event['data']['label']['nova_ext_ordered_post_day']  = $editDayLabel;
	$event['data']['inputs']['nova_ext_ordered_post_day'] = array(
		'name'       => $postDayName,
		'id'         => 'nova_ext_ordered_post_day',
		'onkeypress' => 'return (function(evt)
			{
				var charCode = (evt.which) ? evt.which : event.keyCode
				if (charCode > 31 && (charCode < 48 || charCode > 57))
					return false;

				return true;
			})(event)',
		'value'      => $postDay,
	);

	$event['data']['label']['nova_ext_ordered_post_time']  = $editTimeLabel;
	$event['data']['inputs']['nova_ext_ordered_post_time'] = array(
		'name'  => $postTimeName,
		'id'    => 'nova_ext_ordered_post_time',
		'type'  => 'time',
		'value' => $timeValue,
	);

	$event['data']['label']['nova_ext_ordered_post_date']  = $editDateLabel;
	$event['data']['inputs']['nova_ext_ordered_post_date'] = array(
		'name'  => 'nova_ext_ordered_post_date',
		'id'    => 'nova_ext_ordered_post_date',
		'type'  => 'date',
		'value' => $post ? $post->nova_ext_ordered_post_date : '',
	);

	$event['data']['label']['nova_ext_ordered_post_stardate']  = $editStartDateLabel;
	$event['data']['inputs']['nova_ext_ordered_post_stardate'] = array(
		'name'       => 'nova_ext_ordered_post_stardate',
		'id'         => 'nova_ext_ordered_post_stardate',
		'onkeypress' => 'return (function(evt)
			{
				var charCode = (evt.which) ? evt.which : event.keyCode
				if (charCode != 46 && charCode > 31
				&& (charCode < 48 || charCode > 57))
					return false;

				return true;
			})(event)',
		'value'      => $post ? $post->nova_ext_ordered_post_stardate : '',
	);
});

$this->event->listen(['location', 'view', 'output', 'admin', 'manage_posts_edit'], function($event){

	$event['output'] .= $this->extension['nova_ext_sim_central']->inline_css('ordered_manage', 'admin', $event['data']);

	$event['output'] .= \nova_ext_sim_central\Generator::select('[name="post_timeline"]')->closest('p')
		->before(
			$this->extension['nova_ext_sim_central']
				 ->view('ordered_form', $this->skin, 'admin', $event['data'])
		);
});
