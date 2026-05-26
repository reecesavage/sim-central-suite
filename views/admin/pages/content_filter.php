<?php
	$allows         = \nova_ext_sim_central\ContentFilter::allows();
	$defaultGate    = \nova_ext_sim_central\ContentFilter::defaultAgeGate();
	$active         = \nova_ext_sim_central\ContentFilter::isActive();
	$labels         = isset($jsons['nova_ext_content_filter']) && is_array($jsons['nova_ext_content_filter'])
		? $jsons['nova_ext_content_filter']
		: array();
?>

<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/index', '&laquo; Back to Sim Central Suite', array('class' => 'image'));?>
</p>

<p><?php echo $feature['summary'];?></p>

<p class="fontSmall gray">
	Definitions are based on the <a href="https://rpgrating.com/create" target="_blank" rel="noopener">rpgrating.com</a> scale.
	Tick a box below to declare that this sim permits explicit content
	in that dimension. Writers will see the per-post age-gate toggle on
	the write-post form whenever any of these is on; they confirm or
	override the default for each post they write.
</p>

<br>

<?php echo form_open('extensions/nova_ext_sim_central/Manage/content_filter/');?>

	<?php echo text_output('Permitted explicit content', 'h3', 'page-subhead');?>

	<p>
		<input type="hidden" name="content_filter_allows_language" value="0">
		<label>
			<input type="checkbox" name="content_filter_allows_language" value="1"
				<?php echo $allows['language'] ? 'checked' : '';?>>
			<strong>Adult language</strong>
		</label>
	</p>
	<p>
		<input type="hidden" name="content_filter_allows_violence" value="0">
		<label>
			<input type="checkbox" name="content_filter_allows_violence" value="1"
				<?php echo $allows['violence'] ? 'checked' : '';?>>
			<strong>Violence</strong>
		</label>
	</p>
	<p>
		<input type="hidden" name="content_filter_allows_sex" value="0">
		<label>
			<input type="checkbox" name="content_filter_allows_sex" value="1"
				<?php echo $allows['sex'] ? 'checked' : '';?>>
			<strong>Sex</strong>
		</label>
	</p>

	<?php if ( ! $active): ?>
		<p class="fontSmall gray italic">
			With no dimensions enabled, the filter has nothing to gate &mdash;
			the per-post age-gate toggle won't appear on the write-post form
			and post bodies will be fully visible to guests.
		</p>
	<?php endif; ?>

	<br>
	<?php echo text_output('Per-post age-gate default', 'h3', 'page-subhead');?>

	<p>
		<input type="hidden" name="content_filter_age_gate_default" value="0">
		<label>
			<input type="checkbox" name="content_filter_age_gate_default" value="1"
				<?php echo $defaultGate ? 'checked' : '';?>>
			<strong>New posts are age-gated by default</strong>
		</label>
	</p>
	<p class="fontSmall gray italic">
		Controls the initial state of the age-gate checkbox on the write-post
		form. Default is <strong>on</strong> (safer-by-default). Flip this <strong>off</strong>
		if your sim only occasionally has explicit content &mdash; writers will
		then opt IN to gating per post instead of opting out. The submit
		confirmation still fires whenever a writer leaves the checkbox
		unticked, so they always confirm their decision.
	</p>

	<br>
	<?php echo text_output('Definitions shown on the post form', 'h3', 'page-subhead');?>

	<p>
		These appear under the per-post toggle on the write/edit post page, so the writer
		knows what they're attesting to when they mark a post as safe. Only the
		definition(s) for whichever dimension(s) you've enabled above are shown to writers.
	</p>

	<?php foreach (array('definition_language', 'definition_violence', 'definition_sex', 'notice') as $key): ?>
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
