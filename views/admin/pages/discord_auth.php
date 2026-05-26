<?php
	$settings    = isset($jsons['setting']) && is_array($jsons['setting']) ? $jsons['setting'] : array();
	$brokerUrl   = isset($settings['discord_auth_broker_url']) ? $settings['discord_auth_broker_url'] : 'https://auth.simcentral.host';
	$publicKey   = isset($settings['discord_auth_public_key']) ? $settings['discord_auth_public_key'] : '';
	$mode        = isset($settings['discord_auth_mode'])       ? $settings['discord_auth_mode']       : 'link-only';
	$keyConfigured = trim($publicKey) !== '';
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
	<?php echo text_output('Account creation mode', 'h3', 'page-subhead');?>

	<p>
		<label>
			<input type="radio" name="discord_auth_mode" value="link-only"
				<?php echo ($mode === 'link-only') ? 'checked' : '';?>>
			<strong>Link-only</strong> &mdash; users must already have a sim account;
			Discord sign-in matches by Discord ID, never creates new accounts.
			New users sign up the normal way, then link Discord from their User Settings.
		</label>
	</p>
	<p>
		<label>
			<input type="radio" name="discord_auth_mode" value="auto-create"
				<?php echo ($mode === 'auto-create') ? 'checked' : '';?>>
			<strong>Auto-create</strong> &mdash; if Discord sign-in matches no existing
			user, a new account is created on the spot using the Discord identity
			(email + username). Equivalent to "Sign up with Discord."
		</label>
	</p>
	<p class="fontSmall gray italic">
		Both modes still require the user to have a verified email on Discord &mdash;
		the broker refuses to issue a token otherwise.
	</p>

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
