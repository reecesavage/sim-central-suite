<?php

// Injects the Discord-branded button CSS on every template render so
// the button styles work wherever a "Sign in with Discord" / "Link
// Discord" button is rendered (login form, join form, /user/account,
// forced-link page).

$this->event->listen(['template', 'render', 'data'], function($event){
	$event['data']['javascript'] .=
		$this->extension['nova_ext_sim_central']->inline_css('discord_auth_button', 'admin');
});
