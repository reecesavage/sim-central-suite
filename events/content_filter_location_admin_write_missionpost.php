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

	// Helper text + submit confirmation phrasing flip based on the
	// admin's age-gate default. When default is ON, the writer is
	// "opting out" of the safer default - so we use cautionary wording.
	// When default is OFF, the writer is "opting in" to gating - so we
	// use encouraging-action wording. The confirm only fires when the
	// toggle is off at submit time either way (the case where unsafe
	// content might slip through unprotected).
	$defaultGated = \nova_ext_sim_central\ContentFilter::defaultAgeGate();
	$multi        = (count($definitions) !== 1);

	if ($defaultGated) {
		$helpIntro = $multi
			? 'Unselect only if this post does NOT contain any of:'
			: 'Unselect only if this post does NOT contain:';
	} else {
		$helpIntro = $multi
			? 'Select if this post contains any of:'
			: 'Select if this post contains:';
	}

	$attest = implode("\n - ", array_values($definitions));
	$confirmText = "Age-gating is OFF for this post - it will be visible to all visitors.\n\n"
		."Confirm the post does NOT contain:\n - ".$attest."\n\n"
		."Continue submitting?";

	$data = array(
		'label_toggle'    => 'Age-gate this post',
		'gated'           => $gated,
		'definitions'     => $definitions,
		'confirm_text_js' => $confirmText,
		'help_intro'      => $helpIntro,
		// When false (default) the submit-confirm only fires on Post (publish);
		// when true it also fires on Save (draft). Never fires on Delete.
		'confirm_on_save' => \nova_ext_sim_central\ContentFilter::confirmOnSave(),
	);

	// Place the block just above the timeline field, same anchor pattern
	// as ordered_form so suite-injected fields stay grouped.
	$event['output'] .= \nova_ext_sim_central\Generator::select('#timeline')->closest('p')
		->before(
			$this->extension['nova_ext_sim_central']
				->view('content_filter_form', $this->skin, 'admin', $data)
		);
});
