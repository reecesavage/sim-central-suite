<?php

// Injects the Discord-branded button CSS on every template render so
// the button styles work wherever a "Sign in with Discord" / "Link
// Discord" button is rendered (login form, join form, /user/account,
// forced-link page).

$this->event->listen(['template', 'render', 'data'], function($event){
	// Some render contexts don't pre-set a 'javascript' key; appending to a
	// missing key warns under PHP 8, so coalesce first.
	$existing = isset($event['data']['javascript']) ? $event['data']['javascript'] : '';
	$event['data']['javascript'] = $existing .
		$this->extension['nova_ext_sim_central']->inline_css('discord_auth_button', 'admin');
});
