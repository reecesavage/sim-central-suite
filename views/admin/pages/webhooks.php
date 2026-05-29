<?php
	$webhooks         = isset($webhooks) && is_array($webhooks) ? $webhooks : array();
	$availableEvents  = isset($available_events) && is_array($available_events) ? $available_events : array();
	$availableFormats = isset($available_formats) && is_array($available_formats) ? $available_formats : array();
	$availableNewsTypes = isset($available_news_types) && is_array($available_news_types) ? $available_news_types : array();
	$dbReady          = isset($db_ready) ? (bool) $db_ready : true;
	$editRow          = isset($edit_row) ? $edit_row : null;
	$defaultTitle     = isset($default_title_template) ? $default_title_template : '';
	$defaultDesc      = isset($default_description_template) ? $default_description_template : '';
	$defaultLogTitle  = isset($default_log_title_template) ? $default_log_title_template : '';
	$defaultLogDesc   = isset($default_log_description_template) ? $default_log_description_template : '';
	$defaultNewsTitle = isset($default_news_title_template) ? $default_news_title_template : '';
	$defaultNewsDesc  = isset($default_news_description_template) ? $default_news_description_template : '';

	// Pre-fill form values from edit_row if editing, otherwise empty defaults.
	$valLabel     = $editRow ? $editRow->label : '';
	$valUrl       = $editRow ? $editRow->url : '';
	$valFormat    = $editRow ? $editRow->format : 'discord';
	$valEvents    = $editRow ? (array) json_decode($editRow->events, true) : array();
	$valEnabled   = $editRow ? (int) $editRow->enabled : 1;
	$valNewsTypes = ($editRow && isset($editRow->news_types) && $editRow->news_types !== '') ? $editRow->news_types : 'public';
	$valRoleId    = ($editRow && isset($editRow->mention_role_id)) ? (string) $editRow->mention_role_id : '';
	$valRoleEvents = ($editRow && isset($editRow->mention_role_events)) ? (array) json_decode($editRow->mention_role_events, true) : array();
	$valTplTitle  = $editRow ? (string) $editRow->template_title : '';
	$valTplDesc   = $editRow ? (string) $editRow->template_description : '';
	$valTplLogTitle  = ($editRow && isset($editRow->template_log_title)) ? (string) $editRow->template_log_title : '';
	$valTplLogDesc   = ($editRow && isset($editRow->template_log_description)) ? (string) $editRow->template_log_description : '';
	$valTplNewsTitle = ($editRow && isset($editRow->template_news_title)) ? (string) $editRow->template_news_title : '';
	$valTplNewsDesc  = ($editRow && isset($editRow->template_news_description)) ? (string) $editRow->template_news_description : '';
?>

<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/index', '&laquo; Back to Sim Central Suite', array('class' => 'image'));?>
</p>

<p><?php echo $feature['summary'];?></p>

<?php if ( ! $dbReady): ?>
	<div style="border: 2px solid #c33; background: #fff0f0; padding: 12px;">
		<?php echo text_output('Database setup required', 'h3', 'red');?>
		<p>The <code>sim_central_webhooks</code> table doesn't exist yet. Go back to the dashboard and click <strong>Setup database</strong> on the Event Webhooks row.</p>
	</div>
	<?php return; ?>
<?php endif;?>

<br>
<?php echo text_output($editRow ? 'Edit webhook' : 'Create webhook', 'h3', 'page-subhead');?>

<?php echo form_open('extensions/nova_ext_sim_central/Manage/webhooks/');?>
	<input type="hidden" name="action" value="<?php echo $editRow ? 'update_webhook' : 'create_webhook';?>">
	<?php if ($editRow): ?>
		<input type="hidden" name="id" value="<?php echo (int) $editRow->id;?>">
	<?php endif;?>

	<p>
		<kbd>Label</kbd>
		<input type="text" name="label" size="40" maxlength="120" required
			value="<?php echo htmlspecialchars($valLabel, ENT_QUOTES);?>"
			placeholder="e.g. Discord #posts channel">
	</p>

	<p>
		<kbd>Webhook URL</kbd>
		<input type="url" name="url" size="80" required
			value="<?php echo htmlspecialchars($valUrl, ENT_QUOTES);?>"
			placeholder="https://discord.com/api/webhooks/...">
		<br>
		<span class="fontSmall gray">For Discord: paste the channel's webhook URL from <em>Channel Settings &rarr; Integrations &rarr; Webhooks</em>. For generic JSON consumers (n8n etc.): any https:// URL that accepts POST.</span>
	</p>

	<p>
		<kbd>Format</kbd>
		<?php foreach ($availableFormats as $fmt => $desc): ?>
			<label style="display: block; margin: 4px 0;">
				<input type="radio" name="format" value="<?php echo htmlspecialchars($fmt, ENT_QUOTES);?>"
					<?php if ($valFormat === $fmt) echo 'checked';?>
					onchange="document.getElementById('sc-discord-fields').style.display = (this.value === 'discord') ? 'block' : 'none';">
				<strong><?php echo htmlspecialchars($fmt, ENT_QUOTES);?></strong>
				&nbsp;<span class="fontSmall gray"><?php echo htmlspecialchars($desc, ENT_QUOTES);?></span>
			</label>
		<?php endforeach;?>
	</p>

	<p>
		<kbd>Events</kbd>
		<?php foreach ($availableEvents as $ev => $desc): ?>
			<label style="display: block; margin: 4px 0;">
				<input type="checkbox" name="events[]" value="<?php echo htmlspecialchars($ev, ENT_QUOTES);?>"
					<?php if (in_array($ev, $valEvents, true)) echo 'checked';?>
					<?php if ($ev === 'news.posted'): ?>onchange="document.getElementById('sc-news-types').style.display = this.checked ? 'block' : 'none';"<?php endif;?>>
				<code><?php echo htmlspecialchars($ev, ENT_QUOTES);?></code>
				&nbsp;<span class="fontSmall gray"><?php echo htmlspecialchars($desc, ENT_QUOTES);?></span>
			</label>
		<?php endforeach;?>
	</p>

	<div id="sc-news-types" style="<?php echo in_array('news.posted', $valEvents, true) ? 'display:block;' : 'display:none;';?> background: #f8fafc; border: 1px solid #cdd; padding: 12px; margin-top: 8px;">
		<p>
			<kbd>News types</kbd>
			<?php foreach ($availableNewsTypes as $nt => $ntLabel): ?>
				<label style="display: block; margin: 4px 0;">
					<input type="radio" name="news_types" value="<?php echo htmlspecialchars($nt, ENT_QUOTES);?>"
						<?php if ($valNewsTypes === $nt) echo 'checked';?>>
					<?php echo htmlspecialchars($ntLabel, ENT_QUOTES);?>
				</label>
			<?php endforeach;?>
			<span class="fontSmall gray">Which news items fire <code>news.posted</code>. Only applies to that event.</span>
		</p>
	</div>

	<p>
		<kbd>Enabled</kbd>
		<label><input type="checkbox" name="enabled" value="1" <?php if ($valEnabled) echo 'checked';?>>
			Receive deliveries</label>
	</p>

	<p>
		<kbd>Mention role (Discord)</kbd>
		<input type="text" name="mention_role_id" size="32" pattern="[0-9]*"
			value="<?php echo htmlspecialchars($valRoleId, ENT_QUOTES);?>"
			placeholder="e.g. 123456789012345678">
		<br>
		<span class="fontSmall gray">Optional. A Discord <strong>role ID</strong> to ping. Enable Developer Mode in Discord, right-click the role &rarr; <em>Copy Role ID</em>. Leave blank for no role ping. Only applies to the Discord format. Pick which events ping it below.</span>
	</p>

	<div style="background: #f8fafc; border: 1px solid #cdd; padding: 12px; margin-top: 8px;">
		<p>
			<kbd>Ping role on</kbd>
			<?php foreach ($availableEvents as $ev => $desc): ?>
				<label style="display: block; margin: 4px 0;">
					<input type="checkbox" name="mention_role_events[]" value="<?php echo htmlspecialchars($ev, ENT_QUOTES);?>"
						<?php if (in_array($ev, $valRoleEvents, true)) echo 'checked';?>>
					<code><?php echo htmlspecialchars($ev, ENT_QUOTES);?></code>
				</label>
			<?php endforeach;?>
			<span class="fontSmall gray">The role is pinged (via <code>&lt;@&amp;role&gt;</code> in the message content) only on the events checked here, and only for events this webhook is subscribed to above. Leave all unchecked for no role ping.</span>
		</p>
	</div>

	<div id="sc-discord-fields" style="<?php echo $valFormat === 'discord' ? 'display:block;' : 'display:none;';?> background: #f8fafc; border: 1px solid #cdd; padding: 12px; margin-top: 12px;">
		<?php echo text_output('Discord embed templates', 'h3', 'page-subhead');?>
		<p class="fontSmall gray">
			Customise the embed title and body for each announcement event. Leave a field blank to use its default.
			<code>post.saved</code> uses a fixed (non-templateable) format &mdash; it's a lightweight ping, not an announcement.
		</p>

		<?php echo text_output('Mission posts (post.posted)', 'h3', 'page-subhead');?>
		<p class="fontSmall">
			Variables: <code>{sim_name}</code>, <code>{post_title}</code>, <code>{title}</code>, <code>{post_type}</code>,
			<code>{authors}</code> (plain "Rank Name" &mdash; no pings), <code>{authors_plain}</code> (alias),
			<code>{authors_mentions}</code> (clickable Discord mentions, but silent &mdash; announcements never ping),
			<code>{mission}</code>, <code>{location}</code>, <code>{timeline}</code>, <code>{body}</code>,
			<code>{url}</code>, <code>{url_admin}</code>, <code>{actor}</code>.
		</p>
		<p class="fontSmall gray">
			Note: the default description already ends with a <code>[Read the full post]({url})</code> line, so
			<code>{body}</code> is the excerpt only (no trailing link). Authors are <strong>not</strong> pinged on
			<code>post.posted</code> &mdash; only <code>post.saved</code> tags them.
		</p>

		<p>
			<kbd>Embed title template</kbd>
			<br>
			<input type="text" name="template_title" size="80"
				value="<?php echo htmlspecialchars($valTplTitle, ENT_QUOTES);?>"
				placeholder="<?php echo htmlspecialchars($defaultTitle, ENT_QUOTES);?>">
		</p>

		<p>
			<kbd>Embed description template</kbd>
			<br>
			<textarea name="template_description" rows="9" cols="80" style="font-family: monospace; font-size: 12px;"
				placeholder="<?php echo htmlspecialchars($defaultDesc, ENT_QUOTES);?>"><?php echo htmlspecialchars($valTplDesc, ENT_QUOTES);?></textarea>
		</p>

		<hr>
		<?php echo text_output('Personal logs (log.posted)', 'h3', 'page-subhead');?>
		<p class="fontSmall">
			Variables: <code>{sim_name}</code>, <code>{title}</code>, <code>{authors}</code>,
			<code>{authors_plain}</code>, <code>{authors_mentions}</code>, <code>{body}</code>,
			<code>{url}</code>, <code>{url_admin}</code>, <code>{actor}</code>.
		</p>

		<p>
			<kbd>Embed title template</kbd>
			<br>
			<input type="text" name="template_log_title" size="80"
				value="<?php echo htmlspecialchars($valTplLogTitle, ENT_QUOTES);?>"
				placeholder="<?php echo htmlspecialchars($defaultLogTitle, ENT_QUOTES);?>">
		</p>

		<p>
			<kbd>Embed description template</kbd>
			<br>
			<textarea name="template_log_description" rows="6" cols="80" style="font-family: monospace; font-size: 12px;"
				placeholder="<?php echo htmlspecialchars($defaultLogDesc, ENT_QUOTES);?>"><?php echo htmlspecialchars($valTplLogDesc, ENT_QUOTES);?></textarea>
		</p>

		<hr>
		<?php echo text_output('News items (news.posted)', 'h3', 'page-subhead');?>
		<p class="fontSmall">
			Variables: <code>{sim_name}</code>, <code>{title}</code>, <code>{authors}</code>,
			<code>{authors_plain}</code>, <code>{authors_mentions}</code>, <code>{category}</code>,
			<code>{type}</code> (Public/Private), <code>{meta}</code> (the "Category &middot; Type &middot; by Author" line),
			<code>{body}</code>, <code>{url}</code>, <code>{url_admin}</code>, <code>{actor}</code>.
		</p>

		<p>
			<kbd>Embed title template</kbd>
			<br>
			<input type="text" name="template_news_title" size="80"
				value="<?php echo htmlspecialchars($valTplNewsTitle, ENT_QUOTES);?>"
				placeholder="<?php echo htmlspecialchars($defaultNewsTitle, ENT_QUOTES);?>">
		</p>

		<p>
			<kbd>Embed description template</kbd>
			<br>
			<textarea name="template_news_description" rows="6" cols="80" style="font-family: monospace; font-size: 12px;"
				placeholder="<?php echo htmlspecialchars($defaultNewsDesc, ENT_QUOTES);?>"><?php echo htmlspecialchars($valTplNewsDesc, ENT_QUOTES);?></textarea>
		</p>
	</div>

	<br>
	<button name="submit" type="submit" class="button-main"><span><?php echo $editRow ? 'Save Changes' : 'Create Webhook';?></span></button>
	<?php if ($editRow): ?>
		&nbsp;<?php echo anchor('extensions/nova_ext_sim_central/Manage/webhooks', 'Cancel');?>
	<?php endif;?>
<?php echo form_close();?>

<br>
<?php echo text_output('Configured webhooks', 'h3', 'page-subhead');?>

<?php if ( ! empty($webhooks)): ?>
	<table class="table100 zebra">
		<thead><tr>
			<th>Label</th>
			<th>Format</th>
			<th>Events</th>
			<th>Status</th>
			<th>Last fired</th>
			<th class="align_right">Actions</th>
		</tr></thead>
		<tbody>
		<?php foreach ($webhooks as $w): ?>
			<?php
				$events = json_decode($w->events, true);
				if ( ! is_array($events)) { $events = array(); }
				$statusBadge = $w->enabled ? '<span class="green">enabled</span>' : '<span class="gray">disabled</span>';
				$lastInfo = '';
				if ($w->last_fired_at) {
					$ok = ($w->last_status >= 200 && $w->last_status < 300);
					$lastInfo = '<span class="'.($ok ? 'green' : 'red').'">HTTP '.(int) $w->last_status.'</span> &middot; '
						.htmlspecialchars($w->last_fired_at, ENT_QUOTES);
					if ( ! $ok && ! empty($w->last_error)) {
						$lastInfo .= '<br><span class="gray fontSmall">'.htmlspecialchars(substr($w->last_error, 0, 100), ENT_QUOTES).'</span>';
					}
				} else {
					$lastInfo = '<span class="gray">never</span>';
				}
			?>
			<tr class="alt">
				<td style="max-width: 360px;">
					<strong><?php echo htmlspecialchars($w->label, ENT_QUOTES);?></strong><br>
					<span class="fontSmall gray" style="word-break: break-all; overflow-wrap: anywhere; display: block;"><?php echo htmlspecialchars($w->url, ENT_QUOTES);?></span>
				</td>
				<td><code><?php echo htmlspecialchars($w->format, ENT_QUOTES);?></code></td>
				<td class="fontSmall">
					<?php foreach ($events as $e): ?>
						<code><?php echo htmlspecialchars($e, ENT_QUOTES);?></code><br>
					<?php endforeach;?>
				</td>
				<td><?php echo $statusBadge;?></td>
				<td class="fontSmall"><?php echo $lastInfo;?></td>
				<td class="align_right">
					<?php echo anchor('extensions/nova_ext_sim_central/Manage/webhooks/edit/'.$w->id, 'Edit', array('class' => 'button-sec'));?>

					<?php echo form_open('extensions/nova_ext_sim_central/Manage/webhooks/', array('style' => 'display:inline;'));?>
						<input type="hidden" name="action" value="test_webhook">
						<input type="hidden" name="id" value="<?php echo (int) $w->id;?>">
						<select name="event" style="font-size: 12px;">
							<?php foreach ($events as $e): ?>
								<option value="<?php echo htmlspecialchars($e, ENT_QUOTES);?>"><?php echo htmlspecialchars($e, ENT_QUOTES);?></option>
							<?php endforeach;?>
						</select>
						<button type="submit" class="button-sec"><span>Test</span></button>
					<?php echo form_close();?>

					<?php echo form_open('extensions/nova_ext_sim_central/Manage/webhooks/', array('style' => 'display:inline;'));?>
						<input type="hidden" name="action" value="toggle_webhook">
						<input type="hidden" name="id" value="<?php echo (int) $w->id;?>">
						<button type="submit" class="button-sec"><span><?php echo $w->enabled ? 'Disable' : 'Enable';?></span></button>
					<?php echo form_close();?>

					<?php echo form_open('extensions/nova_ext_sim_central/Manage/webhooks/',
						array('style' => 'display:inline;', 'onsubmit' => "return confirm('Delete this webhook? Cannot be undone.');"));?>
						<input type="hidden" name="action" value="delete_webhook">
						<input type="hidden" name="id" value="<?php echo (int) $w->id;?>">
						<button type="submit" class="button-sec"><span>Delete</span></button>
					<?php echo form_close();?>
				</td>
			</tr>
		<?php endforeach;?>
		</tbody>
	</table>
<?php else: ?>
	<?php echo text_output('No webhooks configured yet. Create one above.', 'h3', 'orange');?>
<?php endif;?>
