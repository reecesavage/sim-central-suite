<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	This sim requires every user to have a linked Discord account.
	Click the button below to authorize the sim to read your Discord
	identity (username, email, avatar). After linking, you can use the
	site as normal.
</p>

<p class="fontSmall gray">
	The sim only reads your Discord identity to confirm who you are &mdash;
	it doesn't post on your behalf, read your DMs, or access your servers.
	Linking happens through <a href="https://github.com/reecesavage/sim-central-broker" target="_blank" rel="noopener">an OAuth2 broker</a>;
	the sim never sees your Discord password.
</p>

<br>

<p>
	<?php echo $button_html;?>
</p>

<br>
<br>

<p class="fontSmall gray">
	Don't want to link Discord? You can <?php echo anchor($logout_url, 'sign out');?>
	and use a different account.
</p>
