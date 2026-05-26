<?php

// On a user-row INSERT (most relevant: the join flow), if the session
// has a pending Discord link from the broker round-trip, merge the
// Discord columns onto the row before it gets written. Then clear the
// session so a second insert in the same request doesn't double-stamp.
//
// The DB column UNIQUE index on nova_ext_discord_auth_id is the
// last-line guard against two users ending up linked to the same
// Discord account.

$this->event->listen(['db', 'insert', 'prepare', 'users'], function($event){
	$pending = $this->session->userdata('discord_auth_pending_join');
	if (empty($pending)) {
		return;
	}
	$claims = json_decode((string) $pending, true);
	if ( ! is_array($claims) || empty($claims['sub'])) {
		return;
	}

	$cols = \nova_ext_sim_central\DiscordAuth::columnsForClaims($claims);
	foreach ($cols as $key => $value) {
		$event['data'][$key] = $value;
	}

	$this->session->unset_userdata('discord_auth_pending_join');
});
