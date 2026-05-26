<?php

// Append a Discord-branded "Sign in with Discord" button below Nova's
// stock login form.

$this->event->listen(['location', 'view', 'output', 'login', 'login_index'], function($event){
	$btn = \nova_ext_sim_central\DiscordAuth::brandedButtonHtml(
		'Sign in with Discord',
		site_url('extensions/nova_ext_sim_central/DiscordAuth/start?intent=login')
	);
	$wrap = '<p style="margin-top:1.2em;">'.$btn.'</p>';

	$event['output'] .= \nova_ext_sim_central\Generator::select('form')->first()
		->after($wrap);
});
