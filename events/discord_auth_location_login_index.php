<?php

// Append a Discord-branded "Sign in with Discord" button below Nova's
// stock login form. In v1.8.0+, when "Discord-only login" is on, the
// email + password form is also hidden by default and gated behind a
// "sysadmin sign-in" reveal toggle. Discord-only enforcement on the
// server side (libraries/DiscordAuth.php::shouldEnforceDiscordOnly)
// bounces non-sysadmins who somehow get past the UI hide, so the JS
// is just polish - not the actual security boundary.

$this->event->listen(['location', 'view', 'output', 'login', 'login_index'], function($event){
	$startUrl = site_url('extensions/nova_ext_sim_central/DiscordAuth/start?intent=login');
	$btn = \nova_ext_sim_central\DiscordAuth::brandedButtonHtml('Sign in with Discord', $startUrl);

	// "Remember me" mirrors the checkbox on Nova's password form: ticking
	// it adds remember=yes to the start URL, and the callback sets Nova's
	// standard 14-day remember cookies after login.
	$wrap = '<p style="margin-top:1.2em;">'.$btn.'</p>'
		.'<p class="fontSmall" style="margin-top:0.4em;">'
		.'<label><input type="checkbox" id="nova-ext-discord-remember"> Remember me</label>'
		.'</p>'
		.'<script>(function(){'
		.'var box = document.getElementById("nova-ext-discord-remember");'
		.'var link = document.querySelector("a.nova-ext-discord-button");'
		.'if (!box || !link) return;'
		.'var base = link.getAttribute("href");'
		.'box.addEventListener("change", function(){'
		.'link.setAttribute("href", box.checked ? base + "&remember=yes" : base);'
		.'});'
		.'})();</script>';

	$event['output'] .= \nova_ext_sim_central\Generator::select('form')->first()
		->after($wrap);

	if ( ! \nova_ext_sim_central\DiscordAuth::loginDiscordOnly()) {
		return;
	}

	// Discord-only mode: collapse the stock email+password form behind
	// a reveal toggle so the Discord button is the visible default.
	// Real enforcement is server-side; this is just the right surface.
	$hideMarkup = '<style>'
		.'.nova-ext-stock-login-form { display: none !important; }'
		.'.nova-ext-stock-login-form.nova-ext-revealed { display: block !important; }'
		.'.nova-ext-sysadmin-toggle { '
		.'margin-top: 1em; '
		.'font-size: 12px; '
		.'color: #888; '
		.'cursor: pointer; '
		.'background: none; '
		.'border: none; '
		.'padding: 0; '
		.'text-decoration: underline; '
		.'}'
		.'</style>'
		.'<button type="button" class="nova-ext-sysadmin-toggle" id="nova-ext-reveal-sysadmin-login">'
		.'Sysadmin sign-in with email + password &raquo;'
		.'</button>'
		.'<script>(function(){'
		.'var form = document.querySelector(\'form[action$="/login"], form[action*="/login/"]\');'
		.'if (form) { form.classList.add("nova-ext-stock-login-form"); }'
		.'var btn = document.getElementById("nova-ext-reveal-sysadmin-login");'
		.'if (btn) {'
		.'btn.addEventListener("click", function(){'
		.'if (form) form.classList.add("nova-ext-revealed");'
		.'btn.style.display = "none";'
		.'});'
		.'}'
		.'})();</script>';

	$event['output'] .= $hideMarkup;
});
