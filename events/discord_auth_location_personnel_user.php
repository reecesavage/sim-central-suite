<?php

// Add a "Discord" row to the public user info page (/personnel/user/{id}),
// alongside the Name / Email / Timezone block Nova renders above the tabs.
// Shows the linked public Discord ID - the one piece of Discord identity
// the suite stores. Nothing renders for users without a linked Discord.

$this->event->listen(['location', 'view', 'output', 'main', 'personnel_user'], function($event){

	$userId = (int) $this->uri->segment(3, 0);
	if ($userId <= 0) {
		return;
	}

	$query = $this->db
		->select('nova_ext_discord_auth_id')
		->where('userid', $userId)
		->limit(1)
		->get('users');
	if ($query->num_rows() === 0) {
		return;
	}
	$row = $query->row();
	if (empty($row->nova_ext_discord_auth_id)) {
		return;
	}

	$discordId = htmlspecialchars((string) $row->nova_ext_discord_auth_id, ENT_QUOTES);
	$block = '<p class="nova_ext_discord_auth_personnel">'
		.'<kbd>Discord</kbd> '
		.'<span class="fontSmall">ID '.$discordId.'</span>'
		.'</p>';

	// #tabs is the stable anchor on this view across skins (the tabbed
	// info area right below the Name / Email / Timezone paragraphs).
	$event['output'] .= \nova_ext_sim_central\Generator::select('#tabs')->first()
		->before($block);
});
