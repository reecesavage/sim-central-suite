<?php

// Add a "Linked Discord account" section to the user's "My Account"
// page (/user/account) so they can link or unlink Discord. Note: this
// is the per-user account page, NOT the admin's "Manage user-created
// settings" page (site_usersettings) - distinct things despite Nova's
// naming ambiguity.

$this->event->listen(['location', 'view', 'output', 'admin', 'user_account'], function($event){
	if ( ! \Auth::is_logged_in()) {
		return;
	}

	$ci =& get_instance();
	$ci->load->model('users_model', 'user');
	$user = $ci->user->get_user($ci->session->userdata('userid'));
	if ( ! $user) {
		return;
	}

	$flash = $ci->session->flashdata('discord_auth_message');

	if ( ! empty($user->nova_ext_discord_auth_id)) {
		// Already linked - show identity + Unlink button.
		$username = htmlspecialchars((string) $user->nova_ext_discord_auth_username, ENT_QUOTES);
		$discordId = htmlspecialchars((string) $user->nova_ext_discord_auth_id, ENT_QUOTES);
		$unlinkUrl = site_url('extensions/nova_ext_sim_central/DiscordAuth/unlink');
		$block = '<br><h3 class="page-subhead">Linked Discord account</h3>'
			.($flash ? '<p class="green bold">'.htmlspecialchars($flash, ENT_QUOTES).'</p>' : '')
			.'<p><strong>Discord:</strong> '.$username.' '
			.'<span class="fontSmall gray">(ID '.$discordId.')</span></p>'
			.'<p>'
			.form_open($unlinkUrl, array('style' => 'display:inline'))
			.'<button type="submit" class="button-sec" '
			.'onclick="return confirm(\'Unlink your Discord account from this sim?\');">'
			.'<span>Unlink Discord</span></button>'
			.form_close()
			.'</p>';
	} else {
		// Not linked - show Link button.
		$linkUrl = site_url('extensions/nova_ext_sim_central/DiscordAuth/start?intent=link');
		$block = '<br><h3 class="page-subhead">Linked Discord account</h3>'
			.($flash ? '<p class="green bold">'.htmlspecialchars($flash, ENT_QUOTES).'</p>' : '')
			.'<p>Link your Discord account so you can sign in with it next time.</p>'
			.'<p>'.anchor($linkUrl, '<span>Link Discord</span>',
				array('class' => 'button-main', 'style' => 'display:inline-block')).'</p>';
	}

	$event['output'] .= $block;
});
