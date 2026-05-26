<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<span class="green bold">&check; <?php echo htmlspecialchars($message, ENT_QUOTES);?></span>
</p>

<br>

<?php echo text_output('Next step', 'h3', 'page-subhead');?>

<p>
	The new code is on disk and PHP's opcache has been invalidated, but
	your current request is still running the old version. Reload the
	dashboard to start using <strong>v<?php echo htmlspecialchars($version, ENT_QUOTES);?></strong>.
</p>

<p>
	<?php echo anchor(
		'extensions/nova_ext_sim_central/Manage/index',
		'Reload dashboard',
		array('class' => 'button-main')
	);?>
</p>

<br>

<?php echo text_output('Rolling back', 'h3', 'page-subhead');?>

<p>
	The previous version is preserved in <code><?php echo htmlspecialchars($backup, ENT_QUOTES);?></code>
	next to the extension directory. If anything goes wrong after the reload
	you can restore it by hand:
</p>

<pre><code>cd application/extensions
rm -rf nova_ext_sim_central
mv <?php echo htmlspecialchars($backup, ENT_QUOTES);?> nova_ext_sim_central</code></pre>

<p class="fontSmall gray">
	The backup is kept until you delete it &mdash; the updater never auto-prunes old backups.
</p>
