<?php

// Front-end /sim/missions page. Same shape as the admin manage_missions
// data ($data['missions'][status][mission_id] = array(...)), so we use
// the same batch lookup + inline-into-desc approach.
$this->event->listen(['location', 'view', 'data', 'main', 'sim_missions_all'], function($event){

	$buckets = array('current', 'upcoming', 'completed');

	$ids = array();
	foreach ($buckets as $b) {
		if (isset($event['data']['missions'][$b]) && is_array($event['data']['missions'][$b])) {
			foreach ($event['data']['missions'][$b] as $key => $value) {
				$id = is_numeric($key) ? (int) $key : (isset($value['id']) ? (int) $value['id'] : 0);
				if ($id > 0) {
					$ids[$id] = true;
				}
			}
		}
	}
	if (empty($ids)) {
		return;
	}

	$counts = \nova_ext_sim_central\PostWordCount::forMissions(array_keys($ids));

	foreach ($buckets as $b) {
		if ( ! isset($event['data']['missions'][$b]) || ! is_array($event['data']['missions'][$b])) {
			continue;
		}
		foreach ($event['data']['missions'][$b] as $key => $value) {
			$id = is_numeric($key) ? (int) $key : (isset($value['id']) ? (int) $value['id'] : 0);
			if ($id <= 0) {
				continue;
			}
			$count = isset($counts[$id]) ? $counts[$id] : 0;
			$line  = '<br /><strong class="fontSmall gray">Word count:</strong> <span class="fontSmall gray">'.number_format($count).'</span>';
			$event['data']['missions'][$b][$key]['desc'] = $value['desc'].$line;
		}
	}
});
