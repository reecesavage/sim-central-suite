<?php

$this->event->listen(['location', 'view', 'data', 'admin', 'manage_missions_action'], function($event){

	$this->config->load('extensions');
	$extensionsConfig = $this->config->item('extensions');

	$json = \nova_ext_sim_central\Config::load();

	$id = isset($event['data']['id']) ? $event['data']['id'] : '';
	$showLegacy = '0';
	$post = false;
	if ( ! empty($id)) {
		$query = $this->db->get_where('missions', array('mission_id' => $id));
		$post = ($query->num_rows() > 0) ? $query->row() : false;
		if ( ! empty($post)
			&& $post->mission_ext_ordered_is_new_record == 0
			&& (isset($json['setting']['legacy_mode']) && $json['setting']['legacy_mode'] == 1)) {
			$showLegacy = '1';
		}
	}

	$editConfigLabel = isset($json['nova_ext_ordered_mission_posts']['mission_ext_ordered_config_setting'])
		? $json['nova_ext_ordered_mission_posts']['mission_ext_ordered_config_setting']['value']
		: 'Timeline Configuration';

	$editPostNumberLabel = isset($json['nova_ext_ordered_mission_posts']['mission_ext_ordered_post_numbering'])
		? $json['nova_ext_ordered_mission_posts']['mission_ext_ordered_post_numbering']['value']
		: 'Post Numbering';

	$defaultMissionDateLabel = isset($json['nova_ext_ordered_mission_posts']['mission_ext_ordered_default_date'])
		? $json['nova_ext_ordered_mission_posts']['mission_ext_ordered_default_date']['value']
		: 'Default Mission Date';

	$defaultStardateLabel = isset($json['nova_ext_ordered_mission_posts']['mission_ext_ordered_default_stardate'])
		? $json['nova_ext_ordered_mission_posts']['mission_ext_ordered_default_stardate']['value']
		: 'Default Stardate';

	$legacyModeLabel = isset($json['nova_ext_ordered_mission_posts']['mission_ext_ordered_legacy_mode'])
		? $json['nova_ext_ordered_mission_posts']['mission_ext_ordered_legacy_mode']['value']
		: 'Day Time Legacy Mode';

	switch ($this->uri->segment(4)) {
		default:
			$event['data']['label']['mission_ext_ordered_config_setting']    = $editConfigLabel;
			$event['data']['inputs']['mission_ext_ordered_config_setting']   = 'mission_ext_ordered_config_setting';
			$event['data']['option']['mission_ext_ordered_config_setting']   = array(
				'default'   => 'Nova Default',
				'day_time'  => 'Day Time',
				'date_time' => 'Date Time',
				'stardate'  => 'Stardate',
			);
			$event['data']['value']['mission_ext_ordered_config_setting']    = $post ? $post->mission_ext_ordered_config_setting : 'default';
			$event['data']['configId']['mission_ext_ordered_config_setting'] = 'id="mission_ext_ordered_config_setting"';

			$event['data']['label']['mission_ext_ordered_post_numbering']   = $editPostNumberLabel;
			$event['data']['inputs']['mission_ext_ordered_post_numbering']  = 'mission_ext_ordered_post_numbering';
			$event['data']['value']['mission_ext_ordered_post_numbering']   = '1';
			$event['data']['checked']['mission_ext_ordered_post_numbering'] = $post ? $post->mission_ext_ordered_post_numbering : '0';

			$event['data']['label']['mission_ext_ordered_default_mission_date']  = $defaultMissionDateLabel;
			$event['data']['inputs']['mission_ext_ordered_default_mission_date'] = array(
				'name'  => 'mission_ext_ordered_default_mission_date',
				'id'    => 'mission_ext_ordered_default_mission_date',
				'type'  => 'date',
				'value' => $post ? $post->mission_ext_ordered_default_mission_date : '',
			);

			$event['data']['label']['mission_ext_ordered_default_stardate']  = $defaultStardateLabel;
			$event['data']['inputs']['mission_ext_ordered_default_stardate'] = array(
				'name'       => 'mission_ext_ordered_default_stardate',
				'id'         => 'mission_ext_ordered_default_stardate',
				'onkeypress' => 'return (function(evt)
					{
						var charCode = (evt.which) ? evt.which : event.keyCode
						if (charCode != 46 && charCode > 31
						&& (charCode < 48 || charCode > 57))
							return false;

						return true;
					})(event)',
				'value'      => $post ? $post->mission_ext_ordered_default_stardate : '1',
			);

			$event['data']['label']['mission_ext_ordered_legacy_mode']      = $legacyModeLabel;
			$event['data']['inputs']['mission_ext_ordered_legacy_mode']     = 'mission_ext_ordered_legacy_mode';
			$event['data']['value']['mission_ext_ordered_legacy_mode']      = '1';
			$event['data']['checked']['mission_ext_ordered_legacy_mode']    = $post ? $post->mission_ext_ordered_legacy_mode : '0';
			$event['data']['legacyMode']['mission_ext_ordered_legacy_mode'] = "data-legacy=$showLegacy";
	}
});

$this->event->listen(['location', 'view', 'output', 'admin', 'manage_missions_action'], function($event){
	switch ($this->uri->segment(4)) {
		case 'view':
			break;
		default:
			$this->config->load('extensions');
			$event['output'] .= $this->extension['nova_ext_sim_central']->inline_css('ordered_manage', 'admin', $event['data']);
			$event['output'] .= \nova_ext_sim_central\Generator::select('[name="mission_status"]')->closest('p')
				->after(
					$this->extension['nova_ext_sim_central']
						 ->view('ordered_mission_form', $this->skin, 'admin', $event['data'])
				);
	}
});
