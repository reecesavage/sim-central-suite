<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/url_parser', '&laquo; Back to URL Parser', array('class' => 'image'));?>
</p>

<?php echo form_open('extensions/nova_ext_sim_central/Manage/url_parser_create/');?>
	<p>
		<kbd>Title</kbd>
		<input type="text" name="title" required>
	</p>

	<p>
		<kbd>URL</kbd>
		<input type="text" name="url" required>
	</p>

	<p>
		<kbd>Post URL</kbd>
		<input type="text" name="post_url">
	</p>

	<p>
		<kbd>New Tab</kbd>
		<input type="checkbox" name="is_new_tab" value="1">
	</p>

	<br>
	<button name="submit" type="submit" class="button-main" value="Submit"><span>Submit</span></button>
<?php echo form_close(); ?>
