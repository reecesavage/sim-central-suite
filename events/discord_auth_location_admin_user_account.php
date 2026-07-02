<?php

// Add a "Linked Discord account" section to the user account page
// (/admin/user/account[/{id}]). Two distinct render modes depending on whose
// account is being viewed:
//
//   Viewing OWN account (the typical case):
//     - Required-link mode OFF, linked     -> identity + Unlink
//     - Required-link mode OFF, not linked -> "Link Discord" button
//     - Required-link mode ON,  linked     -> identity + "Change Discord account"
//                                             (Unlink hidden - users can't fully detach
//                                             when linking is required, but they can
//                                             switch to a different Discord account)
//     - Required-link mode ON,  not linked -> "Link Discord" button. (Shouldn't
//                                             normally be visible since the enforcement
//                                             hook would have redirected them, but
//                                             defensive in case of a stale tab.)
//
//   Viewing SOMEONE ELSE'S account (sysadmin / level 2 only - Nova's own User::account
//   refuses level 1 anyway):
//     - Linked     -> identity only (read-only, no action buttons). Sysadmin can
//                     fix link state via direct SQL or the user editing flow if
//                     they really need to; the account page doesn't expose a
//                     per-user kick-link control yet.
//     - Not linked -> short "No Discord account linked." note. No button (clicking
//                     it would link the SYSADMIN'S Discord, not the viewed user's).
//
// The viewed user id follows the same rule Nova's User::account() uses:
//   level 2 (sysadmin) -> uri->segment(3) if present, else own id
//   level 1            -> own id always

$this->event->listen(['location', 'view', 'output', 'admin', 'user_account'], function($event){
	if ( ! \Auth::is_logged_in()) {
		return;
	}

	$ci =& get_instance();
	$ci->load->model('users_model', 'user');

	$loggedInId = (int) $ci->session->userdata('userid');
	$level      = \Auth::get_access_level();
	$viewedId   = ($level == 2)
		? (int) $ci->uri->segment(3, $loggedInId, true)
		: $loggedInId;

	$viewedUser = $ci->user->get_user($viewedId);
	if ( ! $viewedUser) {
		return;
	}

	$isOwnAccount = ($viewedId === $loggedInId);
	$flash    = $isOwnAccount ? $ci->session->flashdata('discord_auth_message') : null;
	$required = \nova_ext_sim_central\DiscordAuth::requiresLink();
	$linkUrl   = site_url('extensions/nova_ext_sim_central/DiscordAuth/start?intent=link');
	$unlinkUrl = site_url('extensions/nova_ext_sim_central/DiscordAuth/unlink');

	$hasLinked = ! empty($viewedUser->nova_ext_discord_auth_id);

	if ($hasLinked) {
		// The public Discord ID is the only identity detail the suite
		// stores; usernames change and can be looked up live from the ID.
		$discordId = htmlspecialchars((string) $viewedUser->nova_ext_discord_auth_id, ENT_QUOTES);

		$identity = '<p><strong>Discord linked</strong> '
			.'<span class="fontSmall gray">(ID '.$discordId.')</span></p>';
	} else {
		$identity = '';
	}

	if ( ! $isOwnAccount) {
		// Sysadmin viewing another user's account. Render read-only:
		// either the linked identity or a "not linked" note, no buttons.
		// Buttons would be wrong here because all DiscordAuth controller
		// endpoints (start, unlink) act on the SESSION'S user, not the
		// URL's user - clicking them would mutate the sysadmin's own
		// link state, not the viewed user's.
		$body = $hasLinked
			? $identity
			.'<p class="fontSmall gray italic">Read-only. To change this user\'s Discord link, the user must do it from their own account page.</p>'
			: '<p class="gray">No Discord account linked.</p>';

		$block = '<br><h3 class="page-subhead">Linked Discord account</h3>'.$body;
		$event['output'] .= $block;
		return;
	}

	// Viewing own account - full interactive UI.
	if ($hasLinked) {
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
