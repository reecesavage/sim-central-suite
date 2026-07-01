<?php

// On a user-row INSERT (most relevant: the join flow), stamp the pending
// Discord link onto the row before it gets written.
//
// Two sources for the identity, in priority order:
//   1. The signed JWT the join form carries as a hidden field. It's
//      re-verified here (tamper-proof) and, being part of the POST, survives
//      even when the session was lost across the Discord OAuth round-trip -
//      the failure mode behind "linked but not saved" reports.
//   2. The claims the callback stashed in the session. Used when the JWT has
//      since expired (a slow form fill) but the session survived.
//
// Then clear both stashes so a second insert in the same request doesn't
// double-stamp. The DB column UNIQUE index on nova_ext_discord_auth_id is the
// last-line guard against two users ending up linked to the same Discord
// account.

$this->event->listen(['db', 'insert', 'prepare', 'users'], function($event){
	$claims = null;

	// 1. Signed JWT from the join form (same request, tamper-proof).
	$jwt = $this->input->post('discord_auth_jwt');
	if (is_string($jwt) && $jwt !== '') {
		list($status, $payload) = \nova_ext_sim_central\DiscordAuth::verifyToken($jwt);
		if ($status === 'ok') {
			$claims = $payload;
		}
	}

	// 2. Fall back to the session claims (covers an expired JWT).
	if ($claims === null) {
		$pending = $this->session->userdata('discord_auth_pending_join');
		if ( ! empty($pending)) {
			$decoded = json_decode((string) $pending, true);
			if (is_array($decoded) && ! empty($decoded['sub'])) {
				$claims = $decoded;
			}
		}
	}

	if ( ! is_array($claims) || empty($claims['sub'])) {
		return;
	}

	$cols = \nova_ext_sim_central\DiscordAuth::columnsForClaims($claims);
	foreach ($cols as $key => $value) {
		$event['data'][$key] = $value;
	}

	// Hand the identity to the join email listener (same request) - the GM
	// notification is built after this point, once the session stash is gone.
	\nova_ext_sim_central\DiscordAuth::$joinClaims = $claims;

	$this->session->unset_userdata('discord_auth_pending_join');
	$this->session->unset_userdata('discord_auth_pending_join_jwt');
});
