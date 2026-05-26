<?php

// Persist the per-post age-gate toggle from the write/edit-post form.
// The hidden+checkbox pair always submits a 0 or 1 when our form was
// rendered. If the value isn't in POST at all (some other code path
// is inserting a post), we leave the column to take its DB default of
// 1 - safer to over-gate than under-gate.

$writeGate = function($event) {
	$value = $this->input->post('nova_ext_content_filter_age_gated', true);
	if ($value === null) {
		return;
	}
	$event['data']['nova_ext_content_filter_age_gated'] = ((int) $value === 1) ? 1 : 0;
};

$this->event->listen(['db', 'insert', 'prepare', 'posts'], $writeGate);
$this->event->listen(['db', 'update', 'prepare', 'posts'], $writeGate);
