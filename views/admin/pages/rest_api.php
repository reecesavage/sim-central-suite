<?php
	$tokens          = isset($tokens) && is_array($tokens) ? $tokens : array();
	$availableScopes = isset($available_scopes) && is_array($available_scopes) ? $available_scopes : array();
	$newTokenRaw     = isset($new_token_raw) ? $new_token_raw : null;
	$apiBaseUrl      = isset($api_base_url) ? $api_base_url : '';
	$dbReady         = isset($db_ready) ? (bool) $db_ready : true;
	$users           = isset($users) && is_array($users) ? $users : array();
	$userNames       = isset($user_names) && is_array($user_names) ? $user_names : array();
	$csrf            = isset($csrf_exclusion) && is_array($csrf_exclusion) ? $csrf_exclusion : array();
	$csrfStatus      = isset($csrf['status']) ? $csrf['status'] : 'present';
?>

<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/index', '&laquo; Back to Sim Central Suite', array('class' => 'image'));?>
</p>

<p><?php echo $feature['summary'];?></p>

<?php if ($csrfStatus === 'added'): ?>
	<div style="border: 1px solid #2a7; background: #f0fff4; padding: 10px; margin-bottom: 12px;">
		<span class="fontSmall">&#10003; Added the REST API path to your CSRF allowlist in <code>application/config/config.php</code> &mdash; token-authenticated write requests (create post, create webhook, etc.) are now permitted.</span>
	</div>
<?php elseif (in_array($csrfStatus, array('manual', 'unreadable', 'missing'), true)): ?>
	<div style="border: 2px solid #e6a700; background: #fffbe6; padding: 12px; margin-bottom: 12px;">
		<?php echo text_output('Action needed: allow API writes through CSRF', 'h3', 'orange');?>
		<p class="fontSmall">
			Nova's CSRF protection blocks token-authenticated <code>POST</code> requests (creating posts, webhooks, disabling users). The suite tried to add an exclusion automatically but <strong><?php echo $csrfStatus === 'missing' ? 'could not find' : 'could not write to'; ?> <code><?php echo htmlspecialchars(isset($csrf['path']) ? $csrf['path'] : 'application/config/config.php', ENT_QUOTES);?></code></strong>.
		</p>
		<p class="fontSmall">Add this line to <code>application/config/config.php</code> by hand (anywhere after the opening <code>&lt;?php</code>):</p>
		<code>$config['csrf_exclude_uris'][] = '<?php echo htmlspecialchars(isset($csrf['line']) ? $csrf['line'] : 'extensions/nova_ext_sim_central/Api/.*', ENT_QUOTES);?>';</code>
		<p class="fontSmall gray">Read-only <code>GET</code> endpoints work without this; only write endpoints need it.</p>
	</div>
<?php endif; ?>

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

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/api_explorer', '&rarr; Open the API Explorer', array('class' => 'image'));?>
	&nbsp;<span class="fontSmall gray">&mdash; interactive try-it page for every endpoint, plus a link to the OpenAPI 3.0 spec.</span>
</p>

<?php
	$sc            = isset($sim_central) && is_array($sim_central) ? $sim_central : array();
	$scScopes      = isset($sim_central_scopes) && is_array($sim_central_scopes) ? $sim_central_scopes : array();
	$brokerOk      = ! empty($broker_configured);
	$settings      = isset($jsons['setting']) && is_array($jsons['setting']) ? $jsons['setting'] : array();
	$brokerUrl     = isset($settings['sim_central_broker_url']) ? (string) $settings['sim_central_broker_url'] : '';
	$brokerSecret  = isset($settings['sim_central_broker_secret']) ? (string) $settings['sim_central_broker_secret'] : '';
	$scActive      = ! empty($sc['active']);
	$scGranted     = ! empty($sc['granted']);
?>

<br>
<div style="border: 2px solid #2a6db0; background: #eef5fc; padding: 12px; margin-bottom: 14px;">
	<?php echo text_output('Sim Central access', 'h3', 'page-subhead');?>
	<p class="fontSmall">
		Grant the Sim Central service a single pre-scoped token so it can reach this sim's API,
		manage webhooks, activate/deactivate members, and push suite upgrades. The token is registered
		with your broker automatically; revoking it tells the broker to stop using it.
	</p>

	<p class="fontSmall">
		<strong>Status:</strong>
		<?php if ($scActive): ?>
			<span style="color: #2a7;">&#10003; Granted &amp; active</span>
			<?php if ( ! empty($sc['token_prefix'])): ?>
				&mdash; <code><?php echo htmlspecialchars($sc['token_prefix'], ENT_QUOTES);?>&hellip;</code>
			<?php endif;?>
		<?php elseif ($scGranted): ?>
			<span style="color: #c33;">Revoked / inactive</span> &mdash; re-grant to issue a fresh token.
		<?php else: ?>
			<span class="gray">Not granted yet.</span>
		<?php endif;?>
		<?php if ( ! empty($sc['last_registered_at'])): ?>
			<br><span class="gray">Last broker sync: <?php echo htmlspecialchars($sc['last_registered_at'], ENT_QUOTES);?>
			(<?php echo htmlspecialchars(isset($sc['broker_status']) ? (string) $sc['broker_status'] : 'unknown', ENT_QUOTES);?><?php
				echo ( ! empty($sc['last_broker_error'])) ? ': '.htmlspecialchars($sc['last_broker_error'], ENT_QUOTES) : ''; ?>)</span>
		<?php endif;?>
	</p>

	<p class="fontSmall gray">
		Scopes granted: <?php echo htmlspecialchars(implode(', ', $scScopes), ENT_QUOTES);?>
	</p>

	<p class="fontSmall" style="color: #b36b00;">
		<strong>Note:</strong> this grant includes <code>tokens:write</code>, which lets Sim Central /
		Astrolabe <strong>mint per-member posting tokens</strong> (scoped to
		<code>posts:read.own</code> + <code>posts:write</code> and bound to one member) so your players
		can write and save posts on this sim from Astrolabe &mdash; with normal attribution, edit
		locking, and moderation. The granted token itself still <strong>cannot author posts</strong>,
		and minting only works while the granting admin's account is a sysadmin. Revoke access above
		at any time to cut everything off at once.
	</p>

	<?php if ( ! $brokerOk): ?>
		<p class="fontSmall" style="color: #b36b00;">Set a <strong>Broker URL</strong> below first so the token can be delivered to Sim Central automatically.</p>
	<?php endif;?>

	<?php if ($scActive): ?>
		<?php echo form_open('extensions/nova_ext_sim_central/Manage/rest_api/');?>
			<input type="hidden" name="action" value="revoke_sim_central">
			<input type="submit" class="button-sec" value="Revoke Sim Central access"
				onclick="return confirm('Revoke Sim Central access? The broker will be told to stop using this token.');">
		<?php echo form_close();?>
	<?php else: ?>
		<?php echo form_open('extensions/nova_ext_sim_central/Manage/rest_api/');?>
			<input type="hidden" name="action" value="grant_sim_central">
			<input type="submit" class="button-main" value="Grant Sim Central access">
		<?php echo form_close();?>
	<?php endif;?>

	<hr style="margin: 12px 0; border: none; border-top: 1px solid #cdd;">

	<?php echo form_open('extensions/nova_ext_sim_central/Manage/rest_api/');?>
		<input type="hidden" name="action" value="save_broker_config">
		<p class="fontSmall gray">
			These settings are only needed to <strong>grant access</strong> above. Anonymous usage
			stats are sent automatically on the suite's update check &mdash; no configuration required.
		</p>
		<p class="fontSmall">
			<kbd>Broker URL</kbd>
			<input type="text" name="sim_central_broker_url" size="40"
				value="<?php echo htmlspecialchars($brokerUrl, ENT_QUOTES);?>"
				placeholder="https://registry.simcentral.host">
			<br><span class="fontSmall gray">Base URL of your Sim Central broker Worker.</span>
		</p>
		<p class="fontSmall">
			<kbd>Broker secret</kbd>
			<input type="password" name="sim_central_broker_secret" size="40" autocomplete="new-password"
				placeholder="<?php echo $brokerSecret !== '' ? '•••••••• (set — leave blank to keep)' : 'shared secret';?>">
			<br><span class="fontSmall gray">Must match the Worker's <code>SC_SHARED_SECRET</code>. Leave blank to keep the current value.</span>
		</p>
		<input type="submit" class="button-sec" value="Save broker configuration">
	<?php echo form_close();?>
</div>

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
		<kbd>Act as user (optional)</kbd>
		<select name="user_id">
			<option value="">&mdash; none (read-only / public token) &mdash;</option>
			<?php foreach ($users as $u): ?>
				<?php
					$displayName = isset($u->display_name) ? $u->display_name : '';
					$mainName = trim($displayName ?: trim(($u->first_name ?? '').' '.($u->last_name ?? '')));
					$label    = $u->name.($mainName !== '' ? ' ('.$mainName.')' : '').($u->is_sysadmin === 'y' ? ' [sysadmin]' : '');
				?>
				<option value="<?php echo (int) $u->userid;?>"><?php echo htmlspecialchars($label, ENT_QUOTES);?></option>
			<?php endforeach;?>
		</select>
		<br>
		<span class="fontSmall gray">Binds the token to a Nova user so it can author/manage that user's posts. <strong>Required</strong> for the <code>posts:read.own</code> / <code>posts:write</code> / <code>posts:delete</code> scopes. The <code>*.all</code> bypass scopes additionally require the bound user to be a sysadmin.</span>
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
				<th>User</th>
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
					<?php
						$boundUid = isset($t->user_id) ? (int) $t->user_id : 0;
						if ($boundUid > 0):
							echo htmlspecialchars(isset($userNames[$boundUid]) ? $userNames[$boundUid] : ('#'.$boundUid), ENT_QUOTES);
						else:
							echo '<span class="gray">&mdash;</span>';
						endif;
					?>
				</td>
				<td class="fontSmall">
					<?php if ($revoked): ?>
						<?php if (empty($scopeList)): ?>
							<span class="gray">(none)</span>
						<?php else: ?>
							<?php foreach ($scopeList as $s): ?>
								<code><?php echo htmlspecialchars($s, ENT_QUOTES);?></code><br>
							<?php endforeach;?>
						<?php endif;?>
					<?php else: ?>
						<details>
							<summary style="cursor: pointer;" title="Click to edit scopes">
								<?php if (empty($scopeList)): ?>
									<span class="gray">(none)</span>
								<?php else: ?>
									<?php foreach ($scopeList as $s): ?><code><?php echo htmlspecialchars($s, ENT_QUOTES);?></code> <?php endforeach;?>
								<?php endif;?>
							</summary>
							<?php echo form_open('extensions/nova_ext_sim_central/Manage/rest_api/', array('style' => 'margin-top: 6px;'));?>
								<input type="hidden" name="action" value="update_token_scopes">
								<input type="hidden" name="token_id" value="<?php echo (int) $t->id;?>">
								<?php foreach ($availableScopes as $scope => $description): ?>
									<label style="display: block; margin: 2px 0;">
										<input type="checkbox" name="scopes[]" value="<?php echo htmlspecialchars($scope, ENT_QUOTES);?>"
											<?php echo in_array($scope, $scopeList, true) ? 'checked' : '';?>>
										<code><?php echo htmlspecialchars($scope, ENT_QUOTES);?></code>
									</label>
								<?php endforeach;?>
								<button type="submit" class="button-sec" style="margin-top: 4px;"><span>Save scopes</span></button>
							<?php echo form_close();?>
						</details>
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
