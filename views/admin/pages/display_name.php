<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/index', '&laquo; Back to Sim Central Suite', array('class' => 'image'));?>
</p>

<p><?php echo $feature['summary'];?></p>

<br>

<?php echo text_output('Labels', 'h3', 'page-subhead');?>

<?php echo form_open('extensions/nova_ext_sim_central/Manage/display_name/');?>
	<?php if (isset($jsons['nova_ext_display_name']) && is_array($jsons['nova_ext_display_name'])): ?>
		<?php foreach ($jsons['nova_ext_display_name'] as $key => $field): ?>
			<p>
				<kbd><?php echo $field['name'];?></kbd>
				<input type="text" name="<?php echo $key;?>" value="<?php echo htmlspecialchars($field['value'], ENT_QUOTES);?>">
			</p>
		<?php endforeach; ?>
	<?php endif; ?>
	<br>
	<button name="action" type="submit" class="button-main" value="save_labels"><span>Save Labels</span></button>
<?php echo form_close();?>
