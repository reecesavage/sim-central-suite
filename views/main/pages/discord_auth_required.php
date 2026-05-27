<?php echo text_output($title, 'h1', 'page-head');?>

<?php if ($has_linked): ?>
	<p>
		This sim requires every user to sign in with Discord. Your account
		is already linked to a Discord identity &mdash; just click the button
		below to authenticate with Discord and continue.
	</p>
<?php else: ?>
	<p>
		This sim requires every user to have a linked Discord account.
		Click the button below to authorize the sim to read your Discord
		identity (username, email, avatar). After linking, you can use the
		site as normal.
	</p>
<?php endif; ?>

<p class="fontSmall gray">
	The sim only reads your Discord identity to confirm who you are &mdash;
	it doesn't post on your behalf, read your DMs, or access your servers.
	The OAuth handoff goes through <a href="https://github.com/reecesavage/sim-central-broker" target="_blank" rel="noopener">an OAuth2 broker</a>;
	the sim never sees your Discord password.
</p>

<br>

<p>
	<?php echo $button_html;?>
</p>

<br>
<br>

<p class="fontSmall gray">
	<?php if ($has_linked): ?>
		Wrong account? You can <?php echo anchor($logout_url, 'sign out');?>
		and use a different one.
	<?php else: ?>
		Don't want to link Discord? You can <?php echo anchor($logout_url, 'sign out');?>
		and use a different account.
	<?php endif; ?>
</p>
