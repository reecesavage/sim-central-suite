<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/index', '&laquo; Back to Sim Central Suite', array('class' => 'image'));?>
</p>

<p><?php echo $feature['summary'];?></p>

<br>

<?php echo text_output('Labels and configuration', 'h3', 'page-subhead');?>

<?php echo form_open('extensions/nova_ext_sim_central/Manage/summary/');?>
	<?php if (isset($jsons['nova_ext_mission_post_summary']) && is_array($jsons['nova_ext_mission_post_summary'])): ?>
		<?php foreach ($jsons['nova_ext_mission_post_summary'] as $key => $field): ?>
			<p>
				<kbd><?php echo $field['name'];?></kbd>
				<input type="text" name="<?php echo $key;?>" value="<?php echo htmlspecialchars($field['value'], ENT_QUOTES);?>">
			</p>
		<?php endforeach; ?>
	<?php endif; ?>

	<p>
		<kbd>Default Summary Field Size (rows)</kbd>
		<input type="text" name="rows"
			onkeypress="return (function(evt){var charCode=(evt.which)?evt.which:event.keyCode;if(charCode>31 && (charCode<48||charCode>57)) return false; return true;})(event)"
			value="<?php echo isset($jsons['setting']['rows']) ? (int) $jsons['setting']['rows'] : 5;?>">
	</p>

	<p>
		<kbd>Include summary in post emails</kbd>
		<input type="checkbox" name="summary_mode" value="1" <?php echo (isset($jsons['setting']['summary_mode']) && $jsons['setting']['summary_mode'] == 1) ? 'checked' : '';?>>
	</p>

	<br>
	<button name="action" type="submit" class="button-main" value="save_summary_config"><span>Save Configuration</span></button>
<?php echo form_close();?>
