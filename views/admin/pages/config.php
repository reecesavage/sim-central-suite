<?php
	$shimLabels = array(
		'current'         => 'Enabled and up to date',
		'outdated'        => 'Shim outdated - update available',
		'legacy'          => 'Older unmarked shim present - update available',
		'standalone_shim' => 'Standalone extension shim present - take over with Update Shim',
		'missing'         => 'Shim not installed',
		'missing_file'    => 'Target file not found',
		'none'            => 'Enabled',
	);
?>

<?php echo text_output($title, 'h1', 'page-head');?>

<p class="fontSmall gray">
	Installed: <strong>v<?php echo $version;?></strong>
	<?php
		$hasLatest = ! empty($update['latest_version']);
		$newer     = $hasLatest && \nova_ext_sim_central\UpdateCheck::isNewer($update['latest_version'], $version);
	?>
	<?php if ($newer): ?>
		&nbsp;&middot;&nbsp;
		<span class="orange bold">Update available:</span>
		v<?php echo htmlspecialchars($update['latest_version'], ENT_QUOTES);?>
		<?php echo anchor(
			$update['release_url'] ?: \nova_ext_sim_central\UpdateCheck::releasesUrl(),
			'View release',
			array('target' => '_blank', 'rel' => 'noopener')
		);?>
		&nbsp;
		<?php echo form_open('extensions/nova_ext_sim_central/Manage/index/', array('style' => 'display:inline'));?>
			<input type="hidden" name="action" value="do_update">
			<input type="hidden" name="target_version" value="<?php echo htmlspecialchars($update['latest_version'], ENT_QUOTES);?>">
			<button type="submit" class="button-sec"
				onclick="return confirm('Download and install v<?php echo htmlspecialchars($update['latest_version'], ENT_QUOTES);?>?\n\nYour settings will be preserved. The current version (v<?php echo htmlspecialchars($version, ENT_QUOTES);?>) will be backed up alongside the extension directory.\n\nContinue?');">
				<span>Update Now</span>
			</button>
		<?php echo form_close();?>
	<?php elseif ($hasLatest): ?>
		&nbsp;&middot;&nbsp;
		<span class="green">Up to date</span>
	<?php endif; ?>

	&nbsp;&middot;&nbsp;
	<span title="Cached for 24 hours. Click Check now to refresh.">
		Last checked <?php echo \nova_ext_sim_central\UpdateCheck::relativeCheckedAt(
			isset($update['checked_at']) ? $update['checked_at'] : 0
		);?>
	</span>
	<?php echo form_open('extensions/nova_ext_sim_central/Manage/index/', array('style' => 'display:inline'));?>
		<input type="hidden" name="action" value="recheck_update">
		<button type="submit" class="button-sec"><span>Check now</span></button>
	<?php echo form_close();?>
</p>

<p>One dashboard for every Sim Central feature. Each row below is independent - toggle one without touching the others. The suite will refuse to enable a feature whose standalone equivalent is still listed in <code>application/config/extensions.php</code>.</p>

<br>

<table class="table100 zebra">
	<thead>
		<tr>
			<th>Feature</th>
			<th>Status</th>
			<th class="align_right">Actions</th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ($features as $key => $f): ?>
			<tr class="alt">
				<td>
					<strong><?php echo $f['name'];?></strong><br />
					<span class="gray fontSmall"><?php echo $f['summary'];?></span>
				</td>
				<td>
					<?php if ($f['standalone_conflict']): ?>
						<span class="orange bold">Standalone <code><?php echo $f['standalone'];?></code> is enabled</span><br />
						<span class="gray fontSmall">
							Use <strong>Disable Standalone</strong> on the right to remove the enable line from
							<code>application/config/extensions.php</code> and drop the standalone's <em>Manage Extensions</em>
							menu item. The database tables stay - the suite reuses them.
						</span>
					<?php elseif ( ! $f['enabled']): ?>
						<span class="gray">Disabled</span>
					<?php elseif ( ! $f['db_ready']): ?>
						<span class="orange">Database setup needed</span><br />
						<span class="gray fontSmall">
							<?php
								$missingBits = isset($f['missing_columns']) ? $f['missing_columns'] : array();
								if ( ! empty($f['missing_tables'])) {
									foreach ($f['missing_tables'] as $t) {
										$missingBits[] = 'table '.$t;
									}
								}
								if ( ! empty($f['missing_indexes'])) {
									foreach ($f['missing_indexes'] as $idx) {
										$missingBits[] = 'index '.$idx;
									}
								}
								$bits = array();
								if ( ! empty($missingBits)) {
									$bits[] = 'Missing: '.implode(', ', $missingBits);
								}
								if ( ! empty($f['stale_columns'])) {
									$bits[] = 'No longer used (will be removed): '.implode(', ', $f['stale_columns']);
								}
								echo implode('<br />', $bits);
							?>
						</span>
					<?php else: ?>
						<?php
							$shimClass = ($f['shim_state'] === 'current' || $f['shim_state'] === 'none') ? 'green' : (($f['shim_state'] === 'missing_file') ? 'red bold' : 'orange');
						?>
						<span class="<?php echo $shimClass;?>"><?php echo $shimLabels[$f['shim_state']];?></span>
					<?php endif; ?>
				</td>
				<td class="align_right">
					<?php if ($f['standalone_conflict']): ?>
						<?php echo form_open('extensions/nova_ext_sim_central/Manage/index/');?>
							<input type="hidden" name="action" value="disable_standalone">
							<input type="hidden" name="feature" value="<?php echo $key;?>">
							<button type="submit" class="button-main"><span>Disable Standalone</span></button>
						<?php echo form_close();?>

					<?php elseif ( ! $f['enabled']): ?>
						<?php echo form_open('extensions/nova_ext_sim_central/Manage/index/');?>
							<input type="hidden" name="action" value="toggle_on">
							<input type="hidden" name="feature" value="<?php echo $key;?>">
							<button type="submit" class="button-main"><span>Enable</span></button>
						<?php echo form_close();?>

					<?php else: ?>
						<?php if ( ! $f['db_ready']): ?>
							<?php echo form_open('extensions/nova_ext_sim_central/Manage/index/');?>
								<input type="hidden" name="action" value="setup_database">
								<input type="hidden" name="feature" value="<?php echo $key;?>">
								<button type="submit" class="button-main"><span>Set Up Database</span></button>
							<?php echo form_close();?>
							<br>
						<?php elseif (in_array($f['shim_state'], array('outdated', 'legacy', 'standalone_shim', 'missing'), true)): ?>
							<?php echo form_open('extensions/nova_ext_sim_central/Manage/index/');?>
								<input type="hidden" name="action" value="install_shim">
								<input type="hidden" name="feature" value="<?php echo $key;?>">
								<button type="submit" class="button-main">
									<span><?php echo ($f['shim_state'] === 'missing') ? 'Install Shim' : 'Update Shim';?></span>
								</button>
							<?php echo form_close();?>
							<br>
						<?php elseif (($f['shim_state'] === 'current' || $f['shim_state'] === 'none') && isset($f['config_route'])): ?>
							<?php echo anchor($f['config_route'], 'Configure', array('class' => 'image'));?>
							<br><br>
						<?php endif; ?>

						<?php echo form_open('extensions/nova_ext_sim_central/Manage/index/');?>
							<input type="hidden" name="action" value="toggle_off">
							<input type="hidden" name="feature" value="<?php echo $key;?>">
							<button type="submit" class="button-sec"><span>Disable</span></button>
						<?php echo form_close();?>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<br>

<p class="fontSmall gray align_center">
	Created and maintained by <a href="https://discord.gg/simcentral" target="_blank" rel="noopener">Sim Central</a>.<br />
	&copy; <?php echo date('Y');?> Reece Savage of Sim Central.
	Distributed under the <a href="https://opensource.org/licenses/MIT" target="_blank" rel="noopener">MIT License</a>.<br />
	Join us on <a href="https://discord.gg/simcentral" target="_blank" rel="noopener">Discord</a>.
</p>
