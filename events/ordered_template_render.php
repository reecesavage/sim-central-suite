<?php

// Injects the ordered-mission-posts form-behaviour script (show/hide
// timeline-specific fields, AJAX-driven default-date seeding when the
// mission changes) on every admin template render.
//
// As of v1.0.1 the date and time pickers are native HTML5 inputs
// (<input type="date"> / <input type="time">), so the legacy jQuery
// UI datepicker CSS + JS files are no longer loaded. Browsers ship a
// full calendar UI with year/month navigation, no z-index quirks, and
// no extra request weight.
$this->event->listen(['template', 'render', 'data'], function($event){
	$event['data']['javascript'] .=
		$this->extension['nova_ext_sim_central']->inline_js('ordered_custom', 'admin');
});
