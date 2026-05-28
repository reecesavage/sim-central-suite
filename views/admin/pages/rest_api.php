<?php
	$tokens          = isset($tokens) && is_array($tokens) ? $tokens : array();
	$availableScopes = isset($available_scopes) && is_array($available_scopes) ? $available_scopes : array();
	$newTokenRaw     = isset($new_token_raw) ? $new_token_raw : null;
	$apiBaseUrl      = isset($api_base_url) ? $api_base_url : '';
	$dbReady         = isset($db_ready) ? (bool) $db_ready : true;
?>

<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/index', '&laquo; Back to Sim Central Suite', array('class' => 'image'));?>
</p>

<p><?php echo $feature['summary'];?></p>

<?php if ( ! $dbReady): ?>
	<div style="border: 2px solid #c33; background: #fff0f0; padding: 12px;">
		<?php echo text_output('Database setup required', 'h3', 'red');?>
		<p>
			The <code>sim_central_api_tokens</code> table doesn't exist yet, so this page can't list or create tokens.
		</p>
		<p>
			Go back to the <?php echo anchor('extensions/nova_ext_sim_central/Manage/index', 'Sim Central Suite dashboard');?>
			and click <strong>Setup database</strong> on the REST API row. Then come back here.
		</p>
	</div>
	<?php return; ?>
<?php endif;?>

<p class="fontSmall gray">
	Base URL for API requests: <code><?php echo htmlspecialchars($apiBaseUrl, ENT_QUOTES);?></code><br>
	Send the token in the <strong><code>X-API-Key</code></strong> header.
	Quick check: <code>GET <?php echo htmlspecialchars($apiBaseUrl, ENT_QUOTES);?>/ping</code>.
	See <code>REST_API.md</code> for the full reference and troubleshooting.
</p>

<?php if ($newTokenRaw !== null): ?>
	<br>
	<div style="border: 2px solid #c80; background: #fff8e1; padding: 12px;">
		<?php echo text_output('New token - copy it now', 'h3', 'orange');?>
		<p class="fontSmall">
			This is the only time this token will be shown. After you leave or refresh
			this page, only its hash remains in the database and the raw value cannot
			be recovered. If you lose it, revoke the token and create a new one.
		</p>
		<p>
			<input type="text" readonly
				value="<?php echo htmlspecialchars($newTokenRaw, ENT_QUOTES);?>"
				style="width: 100%; font-family: monospace; font-size: 13px; padding: 6px;"
				onclick="this.select();">
		</p>
	</div>
<?php endif;?>

<br>
<?php echo text_output('Create token', 'h3', 'page-subhead');?>

<?php echo form_open('extensions/nova_ext_sim_central/Manage/rest_api/');?>
	<input type="hidden" name="action" value="create_token">

	<p>
		<kbd>Label</kbd>
		<input type="text" name="label" size="40" maxlength="120" required
			placeholder="e.g. n8n - feed sync">
		<br>
		<span class="fontSmall gray">Free-form name used to identify this token in this list. Not sent to clients.</span>
	</p>

	<p>
		<kbd>Scopes</kbd>
		<br>
		<?php foreach ($availableScopes as $scope => $description): ?>
			<label style="display: block; margin: 4px 0;">
				<input type="checkbox" name="scopes[]" value="<?php echo htmlspecialchars($scope, ENT_QUOTES);?>">
				<code><?php echo htmlspecialchars($scope, ENT_QUOTES);?></code>
				&nbsp;<span class="fontSmall gray"><?php echo htmlspecialchars($description, ENT_QUOTES);?></span>
			</label>
		<?php endforeach;?>
	</p>

	<p>
		<kbd>Expires at (optional)</kbd>
		<input type="datetime-local" name="expires_at">
		<br>
		<span class="fontSmall gray">Leave blank for a token that never expires. Revoke manually below at any time.</span>
	</p>

	<br>
	<button name="submit" type="submit" class="button-main" value="Submit"><span>Create Token</span></button>
<?php echo form_close();?>

<br>
<?php echo text_output('Existing tokens', 'h3', 'page-subhead');?>

<?php if ( ! empty($tokens)): ?>
	<table class="table100 zebra">
		<thead>
			<tr>
				<th>Label</th>
				<th>Prefix</th>
				<th>Scopes</th>
				<th>Created</th>
				<th>Last used</th>
				<th>Expires</th>
				<th>Status</th>
				<th class="align_right">Actions</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($tokens as $t): ?>
			<?php
				$revoked = ! empty($t->revoked_at);
				$expired = ! empty($t->expires_at) && strtotime($t->expires_at) < time();
				$scopeList = json_decode($t->scopes, true);
				if ( ! is_array($scopeList)) { $scopeList = array(); }

				if ($revoked) { $status = '<span class="red">revoked</span>'; }
				elseif ($expired) { $status = '<span class="gray">expired</span>'; }
				else { $status = '<span class="green">active</span>'; }
			?>
			<tr class="alt">
				<td>
					<strong><?php echo htmlspecialchars($t->label, ENT_QUOTES);?></strong>
				</td>
				<td><code><?php echo htmlspecialchars($t->token_prefix, ENT_QUOTES);?>&hellip;</code></td>
				<td class="fontSmall">
					<?php if (empty($scopeList)): ?>
						<span class="gray">(none)</span>
					<?php else: ?>
						<?php foreach ($scopeList as $s): ?>
							<code><?php echo htmlspecialchars($s, ENT_QUOTES);?></code><br>
						<?php endforeach;?>
					<?php endif;?>
				</td>
				<td class="fontSmall"><?php echo htmlspecialchars($t->created_at, ENT_QUOTES);?></td>
				<td class="fontSmall">
					<?php echo $t->last_used_at
						? htmlspecialchars($t->last_used_at, ENT_QUOTES)
						: '<span class="gray">never</span>';?>
				</td>
				<td class="fontSmall">
					<?php echo $t->expires_at
						? htmlspecialchars($t->expires_at, ENT_QUOTES)
						: '<span class="gray">never</span>';?>
				</td>
				<td class="fontSmall"><?php echo $status;?></td>
				<td class="align_right">
					<?php if ( ! $revoked): ?>
						<?php echo form_open('extensions/nova_ext_sim_central/Manage/rest_api/',
							array('style' => 'display: inline;',
							      'onsubmit' => "return confirm('Revoke this token? Any clients using it will start getting 401 immediately.');"));?>
							<input type="hidden" name="action" value="revoke_token">
							<input type="hidden" name="token_id" value="<?php echo (int) $t->id;?>">
							<button type="submit" class="button-sec"><span>Revoke</span></button>
						<?php echo form_close();?>
					<?php endif;?>

					<?php echo form_open('extensions/nova_ext_sim_central/Manage/rest_api/',
						array('style' => 'display: inline;',
						      'onsubmit' => "return confirm('Delete this token permanently? This is irreversible. (Revoke is safer if you may need an audit trail.)');"));?>
						<input type="hidden" name="action" value="delete_token">
						<input type="hidden" name="token_id" value="<?php echo (int) $t->id;?>">
						<button type="submit" class="button-sec"><span>Delete</span></button>
					<?php echo form_close();?>
				</td>
			</tr>
		<?php endforeach;?>
		</tbody>
	</table>
<?php else: ?>
	<?php echo text_output('No tokens yet. Create one above.', 'h3', 'orange');?>
<?php endif;?>
