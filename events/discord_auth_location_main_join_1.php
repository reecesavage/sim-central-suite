<?php

// Inject a "Discord linking" section at the top of the join form.
//
// Three states:
//   - No pending link, optional   - shows a "Link Discord account" button.
//   - No pending link, required   - same button + a red notice + a small
//                                   JS guard that blocks the form submit
//                                   until linking is done.
//   - Pending link in session     - shows the linked Discord identity
//                                   ("Linked: @username") and hides the
//                                   button. The db.insert.prepare.users
//                                   event will stamp the Discord columns
//                                   onto the new user row at submit time.

$this->event->listen(['location', 'view', 'output', 'main', 'main_join_1'], function($event){

	$pending = $this->session->userdata('discord_auth_pending_join');
	$claims  = is_string($pending) ? json_decode($pending, true) : null;
	$linked  = is_array($claims) && ! empty($claims['sub']);

	$required = \nova_ext_sim_central\DiscordAuth::requiredOnJoin();

	if ($linked) {
		$user = htmlspecialchars((string) $claims['username'], ENT_QUOTES);
		$email = isset($claims['email']) ? htmlspecialchars((string) $claims['email'], ENT_QUOTES) : '';

		$block = '<div class="nova_ext_discord_auth_join" style="margin-bottom:1em; padding:0.5em 1em; background:#eafbe7; border:1px solid #b7e3ad;">'
			.'<p><strong>&check; Discord account linked:</strong> @'.$user.($email ? ' &lt;'.$email.'&gt;' : '').'</p>'
			.'<p class="fontSmall gray">'
			.'Your Discord identity will be attached to this account when you submit the join form. '
			.'You can unlink later from your account page if you change your mind.'
			.'</p>'
			.'</div>';

		// If the form has an email field and the writer hasn't typed
		// one in yet, pre-fill from Discord. Done client-side so we
		// don't have to know the exact field markup.
		if ($email !== '') {
			$block .= '<script type="text/javascript">'
				.'(function(){'
				.'var el = document.querySelector(\'input[name="email"]\');'
				.'if (el && !el.value) { el.value = '.json_encode($claims['email']).'; }'
				.'})();'
				.'</script>';
		}
	} else {
		$startUrl = site_url('extensions/nova_ext_sim_central/DiscordAuth/start?intent=join');
		$reqNotice = $required
			? '<p class="orange bold">Linking a Discord account is required to join this sim.</p>'
			: '<p class="fontSmall gray">Optional. You can also link Discord later from your account page.</p>';

		$btn = \nova_ext_sim_central\DiscordAuth::brandedButtonHtml('Link Discord', $startUrl);
		$block = '<div class="nova_ext_discord_auth_join" style="margin-bottom:1em; padding:0.5em 1em; background:#f7f7f7; border:1px solid #e1e1e1;">'
			.'<p><strong>Link your Discord account</strong></p>'
			.$reqNotice
			.'<p>'.$btn.'</p>'
			.'</div>';

		if ($required) {
			// Client-side guard: refuse to submit the join form unless
			// Discord is linked. Capture-phase listener so we run
			// before any other handler. Server-side enforcement would
			// require modifying Nova's join controller, which we don't
			// do; this is best-effort UX. An admin can still reject
			// the character at approval time if they really want to
			// enforce it strictly.
			$block .= '<script type="text/javascript">'
				.'(function(){'
				.'document.addEventListener("submit", function(e){'
				.'var form = e.target;'
				.'if (!form.querySelector(\'input[name="email"]\')) return;'
				.'alert('.json_encode('Discord linking is required to join this sim. Click "Link Discord" above first.').');'
				.'e.preventDefault();'
				.'e.stopPropagation();'
				.'}, true);'
				.'})();'
				.'</script>';
		}
	}

	$event['output'] .= \nova_ext_sim_central\Generator::select('form')->first()
		->before($block);
});
