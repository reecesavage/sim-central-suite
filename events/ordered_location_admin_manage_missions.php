<?php

// Appends an inline "Word count: N" line to every mission's `desc` on the
// admin manage_missions page (current / upcoming / completed tabs). The
// stock view renders `$i['desc']` as HTML inside the row's grey
// description block, so injecting an extra <strong>...</strong><br /> is
// the cleanest way to add the figure without a view override.
$this->event->listen(['location', 'view', 'data', 'admin', 'manage_missions'], function($event){

	$buckets = array('current', 'upcoming', 'completed');

	$ids = array();
	foreach ($buckets as $b) {
		if (isset($event['data']['missions'][$b]) && is_array($event['data']['missions'][$b])) {
			foreach ($event['data']['missions'][$b] as $key => $value) {
				// $key is the mission_id (see nova_manage::missions). Fall
				// back to the row's own id field just in case.
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
			$line  = '<strong>Word count:</strong> '.number_format($count).'<br />';
			$event['data']['missions'][$b][$key]['desc'] = $line.$value['desc'];
		}
	}
});
