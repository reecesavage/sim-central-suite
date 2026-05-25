<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/url_parser', '&laquo; Back to URL Parser', array('class' => 'image'));?>
</p>

<?php echo form_open("extensions/nova_ext_sim_central/Manage/url_parser_edit/$model->id");?>
	<p>
		<kbd>Title</kbd>
		<input type="text" name="title" value="<?php echo htmlspecialchars($model->title, ENT_QUOTES);?>" required>
	</p>

	<p>
		<kbd>URL</kbd>
		<input type="text" name="url" value="<?php echo htmlspecialchars($model->url, ENT_QUOTES);?>" required>
	</p>

	<p>
		<kbd>Post URL</kbd>
		<input type="text" name="post_url" value="<?php echo htmlspecialchars($model->post_url, ENT_QUOTES);?>">
	</p>

	<p>
		<kbd>New Tab</kbd>
		<input type="checkbox" name="is_new_tab" <?php echo ($model->is_new_tab == 1) ? 'checked' : '';?> value="1">
	</p>

	<br>
	<button name="submit" type="submit" class="button-main" value="Submit"><span>Submit</span></button>
<?php echo form_close(); ?>
