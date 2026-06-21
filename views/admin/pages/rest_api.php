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
					$mainName = trim(($u->display_name ?: trim(($u->first_name ?? '').' '.($u->last_name ?? ''))));
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
