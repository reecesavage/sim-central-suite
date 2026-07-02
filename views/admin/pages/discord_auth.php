<?php
	$settings       = isset($jsons['setting']) && is_array($jsons['setting']) ? $jsons['setting'] : array();
	$brokerUrl      = isset($settings['discord_auth_broker_url']) ? $settings['discord_auth_broker_url'] : 'https://auth.simcentral.host';
	$publicKey      = isset($settings['discord_auth_public_key']) ? $settings['discord_auth_public_key'] : '';
	$requestEmail     = ! empty($settings['discord_auth_request_email']);
	$requiredOnJoin   = ! empty($settings['discord_auth_required_on_join']);
	$requiredGlobal   = ! empty($settings['discord_auth_required']);
	$discordOnlyLogin = ! empty($settings['discord_auth_login_discord_only']);
	$keyConfigured    = trim($publicKey) !== '';

	$guildIds   = isset($settings['discord_auth_required_guild_ids']) && is_array($settings['discord_auth_required_guild_ids'])
		? $settings['discord_auth_required_guild_ids']
		: array();
	$guildMode  = isset($settings['discord_auth_required_guild_mode']) ? (string) $settings['discord_auth_required_guild_mode'] : 'any';
	$guildHelp  = isset($settings['discord_auth_required_guild_help']) ? (string) $settings['discord_auth_required_guild_help'] : '';

	$excludeUserIds = isset($settings['discord_auth_required_exclude_user_ids']) && is_array($settings['discord_auth_required_exclude_user_ids'])
		? $settings['discord_auth_required_exclude_user_ids']
		: array();
?>

<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/index', '&laquo; Back to Sim Central Suite', array('class' => 'image'));?>
</p>

<p><?php echo $feature['summary'];?></p>

<p class="fontSmall gray">
	Sign-in flow goes through the <a href="https://github.com/reecesavage/sim-central-broker" target="_blank" rel="noopener">Sim Central Broker</a>
	(a small Cloudflare Worker) so this sim never has to be registered as
	a redirect URI with Discord. The broker mints a short-lived signed
	token; this sim verifies it locally with the broker's public key.
</p>

<br>

<?php echo form_open('extensions/nova_ext_sim_central/Manage/discord_auth/');?>

	<?php echo text_output('Broker', 'h3', 'page-subhead');?>

	<p>
		<kbd>Broker URL</kbd>
		<input type="url" name="discord_auth_broker_url" size="50"
			value="<?php echo htmlspecialchars($brokerUrl, ENT_QUOTES);?>">
		<br>
		<span class="fontSmall gray">
			Default is the canonical broker at <code>https://auth.simcentral.host</code>.
			Change only if you've self-hosted your own.
		</span>
	</p>

	<p>
		<kbd>Broker public key (PEM)</kbd>
		<br>
		<textarea name="discord_auth_public_key" rows="8" cols="65"
			style="font-family: monospace; font-size: 11px;"><?php echo htmlspecialchars($publicKey, ENT_QUOTES);?></textarea>
		<br>
		<span class="fontSmall gray">
			RSA public key the broker uses to sign tokens. Without this set,
			Discord sign-in will refuse every token as unverified.
		</span>
	</p>

	<p>
		<button name="action" type="submit" class="button-sec" value="fetch_public_key">
			<span>Fetch from broker JWKS</span>
		</button>
		<span class="fontSmall gray">
			&nbsp;Hits the broker's <code>/.well-known/jwks.json</code> endpoint
			and fills in the key field above. Save afterwards.
		</span>
	</p>

	<br>
	<?php echo text_output('Privacy', 'h3', 'page-subhead');?>

	<p class="fontSmall gray">
		The suite stores only the user's <strong>public Discord ID</strong> (and when it
		was linked) on their user record. By default it doesn't request the
		user's Discord email address at all.
	</p>

	<p>
		<input type="hidden" name="discord_auth_request_email" value="0">
		<label>
			<input type="checkbox" name="discord_auth_request_email" value="1"
				<?php echo $requestEmail ? 'checked' : '';?>>
			<strong>Request Discord email (pre-fills the join form)</strong>
		</label>
	</p>
	<p class="fontSmall gray italic">
		When on, the Discord consent screen additionally asks for the user's email
		address, sign-in requires the Discord email to be verified, and the join
		form's email field is pre-filled with it. The address is only ever shown
		back to the user &mdash; it is never stored. Requires broker v1.2.0+;
		older brokers always request the email scope regardless of this setting.
	</p>

	<br>
	<?php echo text_output('Sign-up behaviour', 'h3', 'page-subhead');?>

	<p class="fontSmall gray">
		New users always go through Nova's normal join form (so the character
		is queued for GM approval like any other join). On the join form, the
		suite injects a <strong>Link Discord</strong> button. If the user clicks
		it, the Discord identity gets attached to the new account when the
		form is submitted; the character still has to be approved.
	</p>

	<p>
		<input type="hidden" name="discord_auth_required_on_join" value="0">
		<label>
			<input type="checkbox" name="discord_auth_required_on_join" value="1"
				<?php echo ($requiredOnJoin || $requiredGlobal) ? 'checked' : '';?>
				<?php echo $requiredGlobal ? 'disabled' : '';?>>
			<strong>Require linking Discord to join</strong>
		</label>
	</p>
	<p class="fontSmall gray italic">
		When on, the join form refuses to submit unless the user has linked Discord first.
		Enforced client-side; admins should still verify at character-approval time if strictness matters.
		<?php if ($requiredGlobal): ?>
			<br><strong>Implicitly enforced</strong> because <em>Require all users to keep Discord linked</em> is on below.
		<?php endif; ?>
	</p>

	<br>
	<?php echo text_output('Site-wide enforcement', 'h3', 'page-subhead');?>

	<p>
		<input type="hidden" name="discord_auth_required" value="0">
		<label>
			<input type="checkbox" name="discord_auth_required" value="1"
				<?php echo $requiredGlobal ? 'checked' : '';?>>
			<strong>Require all users to keep Discord linked</strong>
		</label>
	</p>
	<p class="fontSmall gray italic">
		When on, every logged-in user without a Discord ID is redirected to a forced-link page
		until they link. Existing users will be prompted on their next page load.
		<strong>Unlinking is disabled</strong> while this is on &mdash; users can still <em>change</em>
		to a different Discord account from their User Account page.
	</p>
	<p class="fontSmall gray italic">
		The email + password login form itself is NOT blocked &mdash; users can still sign in with
		their sim password (useful if Discord OAuth is temporarily unavailable). They just can't
		navigate anywhere except the forced-link page until they finish linking.
	</p>
	<p class="fontSmall gray italic">
		When <em>Request Discord email</em> is on (see Privacy above), the user must also have
		a verified email on Discord &mdash; the broker refuses to issue a token otherwise.
	</p>

	<p>
		<input type="hidden" name="discord_auth_login_discord_only" value="0">
		<label>
			<input type="checkbox" name="discord_auth_login_discord_only" value="1"
				<?php echo $discordOnlyLogin ? 'checked' : '';?>>
			<strong>Lock sign-in to Discord (sysadmin email + password escape hatch)</strong>
		</label>
	</p>
	<p class="fontSmall gray italic">
		When on, the login page hides the email + password form behind a "Sysadmin sign-in"
		toggle &mdash; visitors see the <strong>Sign in with Discord</strong> button by default.
		Sysadmins can still use email + password (escape hatch for when Discord OAuth is down).
		Non-sysadmins who somehow sign in via email + password are bounced to the forced sign-in
		page on every request, with a button to sign in via Discord (if they're linked) or
		link Discord (if they aren't).
	</p>
	<p class="fontSmall gray italic">
		Turning this on also turns on <em>Require all users to keep Discord linked</em> (implicit
		dependency) &mdash; users must have Discord linked to sign in via Discord.
	</p>

	<p>
		<kbd>Exempt user IDs</kbd>
		<br>
		<textarea name="discord_auth_required_exclude_user_ids" rows="3" cols="40"
			style="font-family: monospace;"
			placeholder="One Nova user ID per line, e.g.&#10;1&#10;42"><?php echo htmlspecialchars(implode("\n", $excludeUserIds), ENT_QUOTES);?></textarea>
	</p>
	<p class="fontSmall gray italic">
		Nova user IDs listed here are never redirected to the forced-link / Discord-only page, even
		when the enforcement above is on. Use for service accounts or members who legitimately can't
		link Discord. These are <strong>Nova user IDs</strong> (not Discord IDs), and there is no
		automatic sysadmin exemption from the link requirement &mdash; list any sysadmins you want
		exempt here too.
	</p>

	<br>
	<?php echo text_output('Required Discord guild membership', 'h3', 'page-subhead');?>

	<p class="fontSmall gray">
		Optional. Limit Discord sign-in / sign-up / linking to users who are a member of one or more
		specific Discord servers. The broker fetches the user's guild list via Discord's OAuth
		<code>guilds</code> scope (no bot required) and the suite checks it locally.
	</p>

	<p>
		<kbd>Required guild IDs</kbd>
		<br>
		<textarea name="discord_auth_required_guild_ids" rows="4" cols="40"
			style="font-family: monospace;"
			placeholder="One ID per line, e.g.&#10;123456789012345678&#10;987654321098765432"><?php
			echo htmlspecialchars(implode("\n", $guildIds), ENT_QUOTES);
		?></textarea>
		<br>
		<span class="fontSmall gray">
			Discord snowflake IDs, one per line (or comma-separated). Leave blank to disable the check entirely.
			To find a server's ID: open Discord, enable Developer Mode, right-click the server icon &rarr; <em>Copy Server ID</em>.
		</span>
	</p>

	<p>
		<kbd>Match mode</kbd>
		&nbsp;
		<label>
			<input type="radio" name="discord_auth_required_guild_mode" value="any"
				<?php echo ($guildMode !== 'all') ? 'checked' : '';?>>
			<strong>Any of</strong>
		</label>
		&nbsp;&nbsp;
		<label>
			<input type="radio" name="discord_auth_required_guild_mode" value="all"
				<?php echo ($guildMode === 'all') ? 'checked' : '';?>>
			<strong>All of</strong>
		</label>
		<br>
		<span class="fontSmall gray">
			<strong>Any of</strong> &mdash; user must be a member of at least one listed server. The common case.
			<br>
			<strong>All of</strong> &mdash; user must be in every listed server. Use for layered access (e.g. game server <em>and</em> player community).
		</span>
	</p>

	<p>
		<kbd>Help text shown on refusal</kbd>
		<br>
		<textarea name="discord_auth_required_guild_help" rows="4" cols="60"
			placeholder='e.g. Join us at <a href="https://discord.gg/yoursim">discord.gg/yoursim</a> and try signing in again.'><?php
			echo htmlspecialchars($guildHelp, ENT_QUOTES);
		?></textarea>
		<br>
		<span class="fontSmall gray">
			Shown on the "you're not a member of any required server" error page underneath
			the standard intro. <strong>HTML allowed</strong> &mdash; this is the right place to
			paste your Discord invite links as clickable anchors.
		</span>
	</p>

	<?php if ( ! empty($guildIds)): ?>
		<p class="fontSmall orange">
			Note: when this check is on, the suite passes <code>?guilds=1</code> to the broker.
			The broker must be v1.1.0 or newer to support this; older brokers will return a token
			without the guild list and the suite will refuse the sign-in with a clear error.
		</p>
	<?php endif; ?>

	<br>
	<button name="action" type="submit" class="button-main" value="save_discord_auth_config">
		<span>Save Configuration</span>
	</button>

<?php echo form_close();?>

<br><br>

<?php echo text_output('Sim-side callback URL', 'h3', 'page-subhead');?>

<p>
	The broker is told to return users to this URL after Discord auth:
</p>

<p><code><?php echo htmlspecialchars($callback_url, ENT_QUOTES);?></code></p>

<p class="fontSmall gray">
	The broker accepts any return_to on the fly &mdash; you don't need to
	register this URL anywhere. Shown for reference / troubleshooting.
</p>

<?php if ( ! $keyConfigured): ?>
	<br>
	<p class="orange bold">
		Heads-up: no public key is configured yet. Discord sign-in won't work until you fetch or paste one in.
	</p>
<?php endif; ?>
