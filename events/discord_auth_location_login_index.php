<?php

// Append a "Sign in with Discord" button below Nova's stock login form.
// Uses the Generator to inject after the form's submit button.

$this->event->listen(['location', 'view', 'output', 'login', 'login_index'], function($event){
	$url = site_url('extensions/nova_ext_sim_central/DiscordAuth/start?intent=login');
	$button = '<p class="nova_ext_discord_auth_button" style="margin-top:1em;">'
		.anchor($url,
			'<span>Sign in with Discord</span>',
			array('class' => 'button-sec', 'style' => 'display:inline-block')
		)
		.'</p>';

	$event['output'] .= \nova_ext_sim_central\Generator::select('form')->first()
		->after($button);
});
