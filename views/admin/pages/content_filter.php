<?php
	$ratingChoices = array(
		0 => '0 - None',
		1 => '1 - Mild',
		2 => '2 - Moderate',
		3 => '3 - Explicit',
	);
	$settings = isset($jsons['setting']) && is_array($jsons['setting']) ? $jsons['setting'] : array();
	$labels   = isset($jsons['nova_ext_content_filter']) && is_array($jsons['nova_ext_content_filter'])
		? $jsons['nova_ext_content_filter']
		: array();

	$lang_val = isset($settings['content_filter_language']) ? (int) $settings['content_filter_language'] : 0;
	$sex_val  = isset($settings['content_filter_sex'])      ? (int) $settings['content_filter_sex']      : 0;
	$vio_val  = isset($settings['content_filter_violence']) ? (int) $settings['content_filter_violence'] : 0;
?>

<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/index', '&laquo; Back to Sim Central Suite', array('class' => 'image'));?>
</p>

<p><?php echo $feature['summary'];?></p>

<p class="fontSmall gray">
	The rating model follows <a href="https://rpgrating.com/create" target="_blank" rel="noopener">rpgrating.com</a>.
	Language is tracked for completeness but doesn't trigger age-gating &mdash; only Sex and Violence at rating 3 do.
</p>

<br>

<?php echo form_open('extensions/nova_ext_sim_central/Manage/content_filter/');?>

	<?php echo text_output('Sim ratings', 'h3', 'page-subhead');?>

	<p>
		<kbd>Language</kbd>
		<?php echo form_dropdown('content_filter_language', $ratingChoices, $lang_val);?>
	</p>
	<p>
		<kbd>Sex</kbd>
		<?php echo form_dropdown('content_filter_sex', $ratingChoices, $sex_val);?>
	</p>
	<p>
		<kbd>Violence</kbd>
		<?php echo form_dropdown('content_filter_violence', $ratingChoices, $vio_val);?>
	</p>

	<?php if ($sex_val < 3 && $vio_val < 3): ?>
		<p class="fontSmall gray italic">
			Neither Sex nor Violence is set to 3 &mdash; the filter is enabled but has nothing to gate.
			It can stay enabled without effect, or be disabled from the dashboard to keep things tidy.
		</p>
	<?php endif; ?>

	<br>
	<?php echo text_output('Definitions shown on the post form', 'h3', 'page-subhead');?>

	<p>
		These appear under the per-post toggle on the write/edit post page, so the writer
		knows what they're attesting to when they mark a post as safe. Only the
		definition(s) for whichever dimension(s) your sim allows at 3 are shown to writers.
	</p>

	<?php foreach (array('definition_sex', 'definition_violence', 'notice') as $key): ?>
		<?php if (isset($labels[$key])): ?>
			<p>
				<kbd><?php echo $labels[$key]['name'];?></kbd>
				<input type="text" name="<?php echo $key;?>" size="60"
					value="<?php echo htmlspecialchars($labels[$key]['value'], ENT_QUOTES);?>">
			</p>
		<?php endif; ?>
	<?php endforeach; ?>

	<br>
	<button name="action" type="submit" class="button-main" value="save_content_filter_config">
		<span>Save Configuration</span>
	</button>

<?php echo form_close();?>
