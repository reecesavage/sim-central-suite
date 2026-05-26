<?php

// Add a "Linked Discord account" section to the user's "My Account"
// page (/user/account) so they can link, unlink, or change their
// linked Discord. Behaviour depends on suite settings:
//
//   - Required-link mode OFF, linked     -> show identity + Unlink
//   - Required-link mode OFF, not linked -> show "Link Discord" button
//   - Required-link mode ON,  linked     -> show identity + "Change Discord account"
//                                           (Unlink hidden - users can't fully detach
//                                           when linking is required, but they can
//                                           switch to a different Discord account)
//   - Required-link mode ON,  not linked -> show "Link Discord" button. (Shouldn't
//                                           normally be visible since the enforcement
//                                           hook would have redirected them, but
//                                           defensive in case of a stale tab.)

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
	$required = \nova_ext_sim_central\DiscordAuth::requiresLink();
	$linkUrl   = site_url('extensions/nova_ext_sim_central/DiscordAuth/start?intent=link');
	$unlinkUrl = site_url('extensions/nova_ext_sim_central/DiscordAuth/unlink');

	if ( ! empty($user->nova_ext_discord_auth_id)) {
		$username  = htmlspecialchars((string) $user->nova_ext_discord_auth_username, ENT_QUOTES);
		$discordId = htmlspecialchars((string) $user->nova_ext_discord_auth_id, ENT_QUOTES);

		$identity = '<p><strong>Discord:</strong> '.$username.' '
			.'<span class="fontSmall gray">(ID '.$discordId.')</span></p>';

		if ($required) {
			// Required mode: offer "Change Discord account" instead of Unlink.
			// Re-link goes through the same intent=link flow; the suite library's
			// linkUserToDiscord overwrites the stored Discord ID with the new one
			// (refusing only if the new ID is already taken by someone else).
			$changeBtn = \nova_ext_sim_central\DiscordAuth::brandedButtonHtml(
				'Change Discord account', $linkUrl
			);
			$actions = '<p>'.$changeBtn.'</p>'
				.'<p class="fontSmall gray italic">'
				.'This sim requires every user to keep Discord linked, so unlinking is disabled. '
				.'You can change which Discord account is linked by clicking the button above.'
				.'</p>';
		} else {
			// Optional mode: classic Unlink button.
			$actions = '<p>'
				.form_open($unlinkUrl, array('style' => 'display:inline'))
				.'<button type="submit" class="button-sec" '
				.'onclick="return confirm(\'Unlink your Discord account from this sim?\');">'
				.'<span>Unlink Discord</span></button>'
				.form_close()
				.'</p>';
		}

		$block = '<br><h3 class="page-subhead">Linked Discord account</h3>'
			.($flash ? '<p class="green bold">'.htmlspecialchars($flash, ENT_QUOTES).'</p>' : '')
			.$identity
			.$actions;
	} else {
		$linkBtn = \nova_ext_sim_central\DiscordAuth::brandedButtonHtml('Link Discord', $linkUrl);
		$intro = $required
			? '<p class="orange bold">This sim requires you to link a Discord account before continuing.</p>'
			: '<p>Link your Discord account so you can sign in with it next time.</p>';

		$block = '<br><h3 class="page-subhead">Linked Discord account</h3>'
			.($flash ? '<p class="green bold">'.htmlspecialchars($flash, ENT_QUOTES).'</p>' : '')
			.$intro
			.'<p>'.$linkBtn.'</p>';
	}

	$event['output'] .= $block;
});
