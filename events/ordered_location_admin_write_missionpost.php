<?php

$this->event->listen(['location', 'view', 'data', 'admin', 'write_missionpost'], function($event){

	$id   = (is_numeric($this->uri->segment(3))) ? $this->uri->segment(3) : false;
	$post = $id ? $this->posts->get_post($id) : null;

	$this->config->load('extensions');
	$extensionsConfig = $this->config->item('extensions');

	$json = \nova_ext_sim_central\Config::load();

	// HTML5 <input type="time"> wants HH:MM. The DB stores HHmm to keep
	// the column as VARCHAR(4); the colon is stripped back off in
	// ordered_db.php before the insert/update runs.
	$rawTime = $post ? $post->nova_ext_ordered_post_time : '0000';
	$rawTime = str_pad(preg_replace('/[^0-9]/', '', (string) $rawTime), 4, '0', STR_PAD_LEFT);
	$timeValue = substr($rawTime, 0, 2).':'.substr($rawTime, 2, 2);

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

	$viewPrefixLabel = isset($json['nova_ext_ordered_mission_posts']['label_view_prefix'])
		? $json['nova_ext_ordered_mission_posts']['label_view_prefix']['value']
		: 'Mission Day';

	$viewConcatLabel = isset($json['nova_ext_ordered_mission_posts']['label_view_concat'])
		? $json['nova_ext_ordered_mission_posts']['label_view_concat']['value']
		: 'at';

	$viewSuffixLabel = isset($json['nova_ext_ordered_mission_posts']['label_view_suffix'])
		? $json['nova_ext_ordered_mission_posts']['label_view_suffix']['value']
		: '';

	switch ($this->uri->segment(4)) {

		case 'view':
			if ( ! empty($post->post_mission)) {
				$query = $this->db->get_where('missions', array('mission_id' => $post->post_mission));
				$model = ($query->num_rows() > 0) ? $query->row() : false;
				if ( ! empty($model)) {
					$flag = false;
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
					if ($flag) {
						$event['data']['inputs']['timeline']['value'] = $viewPrefixLabel.' '.$post->$column.' '.$viewConcatLabel.' '.$post->$columnTime.' '.$viewSuffixLabel;
					}
				}
			}
			break;

		default:
			$event['data']['label']['nova_ext_ordered_post_day']  = $editDayLabel;
			$event['data']['inputs']['nova_ext_ordered_post_day'] = array(
				'name'       => 'nova_ext_ordered_post_day',
				'id'         => 'nova_ext_ordered_post_day',
				'onkeypress' => 'return (function(evt)
					{
						var charCode = (evt.which) ? evt.which : event.keyCode
						if (charCode > 31 && (charCode < 48 || charCode > 57))
							return false;

						return true;
					})(event)',
				'value'      => $post ? $post->nova_ext_ordered_post_day : '1',
			);

			$event['data']['label']['nova_ext_ordered_post_time']  = $editTimeLabel;
			$event['data']['inputs']['nova_ext_ordered_post_time'] = array(
				'name'  => 'nova_ext_ordered_post_time',
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
	}
});

$this->event->listen(['location', 'view', 'output', 'admin', 'write_missionpost'], function($event){
	switch ($this->uri->segment(4)) {
		case 'view':
			break;
		default:
			$this->config->load('extensions');

			$event['output'] .= $this->extension['nova_ext_sim_central']->inline_css('ordered_manage', 'admin', $event['data']);
			$event['output'] .= \nova_ext_sim_central\Generator::select('#timeline')->closest('p')
				->before(
					$this->extension['nova_ext_sim_central']
						 ->view('ordered_form', $this->skin, 'admin', $event['data'])
				);
	}
});
