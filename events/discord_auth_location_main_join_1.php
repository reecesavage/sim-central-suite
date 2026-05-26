<?php

// Append "Sign up with Discord" to the join form (step 1).
// Only renders the button when the suite is in auto-create mode, since
// in link-only mode there's no useful action for a brand-new user
// coming through Discord (they'd just hit the "not linked yet" error).

$this->event->listen(['location', 'view', 'output', 'main', 'main_join_1'], function($event){
	if (\nova_ext_sim_central\DiscordAuth::mode() !== 'auto-create') {
		return;
	}
	$url = site_url('extensions/nova_ext_sim_central/DiscordAuth/start?intent=join');
	$button = '<p class="nova_ext_discord_auth_button" style="margin-top:1em;">'
		.anchor($url,
			'<span>Sign up with Discord</span>',
			array('class' => 'button-sec', 'style' => 'display:inline-block')
		)
		.'<br /><span class="fontSmall gray">Skip the form &mdash; we\'ll pull your email and username from Discord.</span>'
		.'</p>';

	$event['output'] .= \nova_ext_sim_central\Generator::select('form')->first()
		->before($button);
});
