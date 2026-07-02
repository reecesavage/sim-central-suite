<?php

// Inject a "Discord linking" section at the top of the join form.
//
// Three states:
//   - No pending link, optional   - shows a "Link Discord account" button.
//   - No pending link, required   - same button + a red notice + a small
//                                   JS guard that blocks the form submit
//                                   until linking is done.
//   - Pending link in session     - shows the linked Discord identity
//                                   ("Linked: @username"), hides the button,
//                                   and embeds the signed JWT as a hidden
//                                   form field. The db.insert.prepare.users
//                                   event re-verifies that field (falling back
//                                   to the session claims) and stamps the
//                                   Discord columns onto the new user row at
//                                   submit time - so it works even if the
//                                   session was lost across the OAuth hop.

$this->event->listen(['location', 'view', 'output', 'main', 'main_join_1'], function($event){

	$pending = $this->session->userdata('discord_auth_pending_join');
	$claims  = is_string($pending) ? json_decode($pending, true) : null;
	$linked  = is_array($claims) && ! empty($claims['sub']);
	$jwt     = $this->session->userdata('discord_auth_pending_join_jwt');

	$required = \nova_ext_sim_central\DiscordAuth::requiredOnJoin();

	// Flash set by the server-side join gate (init.php bounces the submit
	// back here when required linking wasn't satisfied) or by the OAuth
	// callback. Without this, a gated submit looked like it did nothing.
	$flash = $this->session->flashdata('discord_auth_message');
	$flashHtml = $flash
		? '<p style="font-size:12px;color:#a94400;font-weight:bold;margin:0 0 0.5em;">'
			.htmlspecialchars((string) $flash, ENT_QUOTES).'</p>'
		: '';

	// All colours in this block are explicit: the box backgrounds are
	// light, and skins with light body text (Titan etc.) would otherwise
	// render light-on-light. Never rely on skin classes inside the box.
	if ($linked) {
		$user = htmlspecialchars((string) $claims['username'], ENT_QUOTES);
		$email = isset($claims['email']) ? htmlspecialchars((string) $claims['email'], ENT_QUOTES) : '';

		$block = '<div class="nova_ext_discord_auth_join" style="margin-bottom:1em; padding:0.5em 1em; background:#eafbe7; border:1px solid #b7e3ad; color:#222;">'
			.$flashHtml
			.'<p style="color:#222;"><strong>&check; Discord account linked:</strong> @'.$user.($email ? ' &lt;'.$email.'&gt;' : '').'</p>'
			.'<p style="font-size:12px;color:#555;">'
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
			? '<p style="color:#c05000;font-weight:bold;">Linking a Discord account is required to join this sim.</p>'
			: '<p style="font-size:12px;color:#555;">Optional. You can also link Discord later from your account page.</p>';

		$btn = \nova_ext_sim_central\DiscordAuth::brandedButtonHtml('Link Discord', $startUrl);
		$block = '<div class="nova_ext_discord_auth_join" style="margin-bottom:1em; padding:0.5em 1em; background:#f7f7f7; border:1px solid #e1e1e1; color:#222;">'
			.$flashHtml
			.'<p style="color:#222;"><strong>Link your Discord account</strong></p>'
			.$reqNotice
			.'<p>'.$btn.'</p>'
			.'</div>';

		if ($required) {
			// Client-side guard: refuse to submit the join form unless
			// Discord is linked. Capture-phase listener so we run before
			// any other handler. Scoped to forms posting to main/join
			// that carry an email field - skins like LCARS render a
			// header sign-in form (which also has an email input) on
			// every page, and it must never be blocked. This is the
			// friendly first line of defence; it's backed by a hard
			// server-side gate in init.php (requiredJoinMissingLink)
			// that blocks the submit even if a user bypasses this JS.
			$block .= '<script type="text/javascript">'
				.'(function(){'
				.'document.addEventListener("submit", function(e){'
				.'var form = e.target;'
				.'if (!form || String(form.action || "").indexOf("main/join") === -1) return;'
				.'if (!form.querySelector(\'input[name="email"]\')) return;'
				.'alert('.json_encode('Discord linking is required to join this sim. Click "Link Discord" above first.').');'
				.'e.preventDefault();'
				.'e.stopPropagation();'
				.'}, true);'
				.'})();'
				.'</script>';
		}
	}

	// selectNearest: target the join view's OWN form (the nearest one
	// before this event's output), not the first form in the document -
	// LCARS-style skins put a hidden sign-in form in the header, and a
	// document-wide first() injected this block into that invisible panel.
	$event['output'] .= \nova_ext_sim_central\Generator::selectNearest('form')->first()
		->before($block);

	// When linked, carry the signed JWT as a hidden field INSIDE the join
	// form (prepend, so it's submitted with the form). The db-stamp event
	// re-verifies it from POST, so the Discord identity is persisted even if
	// the session was lost across the OAuth round-trip.
	if ($linked && is_string($jwt) && $jwt !== '') {
		$hidden = '<input type="hidden" name="discord_auth_jwt" value="'
			.htmlspecialchars((string) $jwt, ENT_QUOTES).'">';
		$event['output'] .= \nova_ext_sim_central\Generator::selectNearest('form')->first()
			->prepend($hidden);
	}
});
