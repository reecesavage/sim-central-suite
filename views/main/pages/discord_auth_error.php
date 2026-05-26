<?php echo text_output($title, 'h1', 'page-head');?>

<p class="orange bold"><?php echo htmlspecialchars($message, ENT_QUOTES);?></p>

<br>

<p>
	<?php echo anchor($login_url, '&laquo; Back to sign in', array('class' => 'button-main'));?>
	&nbsp;
	<?php echo anchor($join_url, 'Or sign up', array('class' => 'image'));?>
</p>
