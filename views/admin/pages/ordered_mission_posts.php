<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/index', '&laquo; Back to Sim Central Suite', array('class' => 'image'));?>
</p>

<p><?php echo $feature['summary'];?></p>

<br>

<?php echo text_output('Labels', 'h3', 'page-subhead');?>

<p>Customise the wording shown on the mission form, post form, and post views.</p>

<?php echo form_open('extensions/nova_ext_sim_central/Manage/ordered_mission_posts/');?>
	<?php if (isset($jsons['nova_ext_ordered_mission_posts']) && is_array($jsons['nova_ext_ordered_mission_posts'])): ?>
		<?php foreach ($jsons['nova_ext_ordered_mission_posts'] as $key => $field): ?>
			<p>
				<kbd><?php echo $field['name'];?></kbd>
				<input type="text" name="<?php echo $key;?>" value="<?php echo htmlspecialchars($field['value'], ENT_QUOTES);?>">
			</p>
		<?php endforeach; ?>
	<?php endif; ?>

	<br>
	<?php echo text_output('Fallback ordering', 'h3', 'page-subhead');?>

	<p>
		When a mission is set to <em>Nova Default</em> timeline, posts in <code>sim/missions/id/&hellip;</code>
		and <code>sim/listposts/&hellip;</code> are ordered by this column.
	</p>

	<p>
		<kbd>Post order column fallback</kbd>
		<input type="text" name="post_order_column_fallback"
			value="<?php echo isset($jsons['setting']['post_order_column_fallback']) ? htmlspecialchars($jsons['setting']['post_order_column_fallback'], ENT_QUOTES) : 'post_date';?>">
	</p>

	<br>
	<?php echo text_output('Legacy mode (chronological_mission_posts)', 'h3', 'page-subhead');?>

	<?php if ($legacy_available): ?>
		<p>
			The columns from the old <code>chronological_mission_posts</code> extension were detected.
			Enabling legacy mode lets existing missions reuse those Day/Time values when their timeline
			configuration is set to Day Time.
		</p>
		<p>
			<kbd>Legacy mode enabled</kbd>
			<input type="checkbox" name="legacy_mode" value="1" <?php echo (isset($jsons['setting']['legacy_mode']) && $jsons['setting']['legacy_mode'] == 1) ? 'checked' : '';?>>
		</p>
	<?php else: ?>
		<p class="gray italic">
			Legacy mode is unavailable - the <code>post_chronological_mission_post_day</code> column from
			<code>chronological_mission_posts</code> is not present on the posts table, so there is nothing
			to reuse. (This is normal if you never used that extension.)
		</p>
		<input type="hidden" name="legacy_mode" value="0">
	<?php endif; ?>

	<br>
	<button name="action" type="submit" class="button-main" value="save_ordered_config"><span>Save Configuration</span></button>
<?php echo form_close();?>
