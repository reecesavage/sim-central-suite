<?php /*
	$message is rendered as trusted HTML so admin-configured snippets
	(e.g. the "join one of these servers" help text on the
	guild_not_member error) can include invite-link anchors. Every
	caller of the controller's _renderError() must ensure the string
	is either:
	  - hardcoded English,
	  - the result of Nova's lang() (already-safe),
	  - or has any user-influenced fragment escaped with
	    htmlspecialchars() at the point it's concatenated in.
*/ ?>

<?php echo text_output($title, 'h1', 'page-head');?>

<p class="orange bold"><?php echo $message;?></p>

<br>

<p>
	<?php echo anchor($login_url, '&laquo; Back to sign in', array('class' => 'button-main'));?>
	&nbsp;
	<?php echo anchor($join_url, 'Or sign up', array('class' => 'image'));?>
</p>
