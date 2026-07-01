<?php

// Add the applicant's linked Discord identity to the "new applicant" email
// that goes to GMs/admins on join.
//
// Fires on the DATA phase of the join-page email parse (URI segments
// main/join, matching Nova's form_open('main/join')), before the template is
// rendered, and appends a Discord row to the GM email's {user} detail block -
// so no email template change is needed. Only the GM notification carries a
// 'user' detail array; the applicant's own welcome email does not, so it is
// left untouched.
//
// The identity comes from DiscordAuth::$joinClaims, set by
// events/discord_auth_db.php when it stamped the Discord columns onto the new
// user row earlier in the same request (the session stash is gone by the time
// the email is built).

$this->event->listen(['parser', 'parse_string', 'data', 'main', 'join'], function($event){
	$claims = \nova_ext_sim_central\DiscordAuth::$joinClaims;
	if ( ! is_array($claims) || empty($claims['sub'])) {
		return;
	}
	// GM notification only - it's the email with the applicant detail rows.
	if ( ! isset($event['data']['user']) || ! is_array($event['data']['user'])) {
		return;
	}

	$username = isset($claims['username']) ? (string) $claims['username'] : '';
	$value    = ($username !== '' ? '@'.$username.' ' : '').'(ID: '.(string) $claims['sub'].')';

	$event['data']['user'][] = array(
		'label' => 'Discord',
		'data'  => $value,
	);
});
