<?php

// Injects the per-post "age-gate this post" checkbox + definitions + a
// submit-confirm onto the admin write/edit-post form. Renders only when
// the sim allows Sex=3 or Violence=3 (otherwise there's nothing to gate
// and the toggle would just clutter the form).

$this->event->listen(['location', 'view', 'output', 'admin', 'write_missionpost'], function($event){
	if ($this->uri->segment(4) === 'view') {
		return;
	}
	if ( ! \nova_ext_sim_central\ContentFilter::isActive()) {
		return;
	}

	$definitions = \nova_ext_sim_central\ContentFilter::gatedDefinitions();

	// Existing post? Use its stored gate value. New post? Default to gated (1).
	$id    = (is_numeric($this->uri->segment(3))) ? $this->uri->segment(3) : false;
	$post  = $id ? $this->posts->get_post($id) : null;
	// Existing post -> use its stored value. New post -> use the admin's
	// configured default (true = gated by default; false = writers opt in
	// per post, useful for sims with rare explicit content).
	$gated = $post
		? ((int) $post->nova_ext_content_filter_age_gated === 1)
		: \nova_ext_sim_central\ContentFilter::defaultAgeGate();

	// Confirm-on-submit message names exactly what the writer is attesting to.
	$attest = implode("\n - ", array_values($definitions));
	$confirmText = "You've marked this post as safe for public viewing.\n\n"
		."Please confirm the post does NOT contain:\n - ".$attest."\n\n"
		."Continue submitting?";

	$data = array(
		'label_toggle'    => 'Age-gate this post',
		'gated'           => $gated,
		'definitions'     => $definitions,
		'confirm_text_js' => $confirmText,
		'help_intro'      => (count($definitions) === 1)
			? 'Uncheck only if this post does not contain:'
			: 'Uncheck only if this post does not contain any of:',
	);

	// Place the block just above the timeline field, same anchor pattern
	// as ordered_form so suite-injected fields stay grouped.
	$event['output'] .= \nova_ext_sim_central\Generator::select('#timeline')->closest('p')
		->before(
			$this->extension['nova_ext_sim_central']
				->view('content_filter_form', $this->skin, 'admin', $data)
		);
});
