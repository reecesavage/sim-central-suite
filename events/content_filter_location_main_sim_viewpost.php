<?php

// Replace the post body with the age-gate notice on /sim/viewpost when:
//   - the content_filter feature is enabled (always true here - this
//     event is only loaded by init.php when the feature is on),
//   - the post is age-gated, and
//   - the viewer is not logged in.
//
// Logged-in users always see the full body regardless of gating.

$this->event->listen(['location', 'view', 'data', 'main', 'sim_viewpost'], function($event){
	if (\Auth::is_logged_in()) {
		return;
	}

	$id   = (is_numeric($this->uri->segment(3))) ? $this->uri->segment(3) : false;
	$post = $id ? $this->posts->get_post($id) : null;
	if (empty($post)) {
		return;
	}

	if ( ! \nova_ext_sim_central\ContentFilter::isPostGated($post)) {
		return;
	}

	$event['data']['content'] = \nova_ext_sim_central\ContentFilter::noticeText();
});
