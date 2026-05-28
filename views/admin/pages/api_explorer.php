<?php
	$endpoints   = isset($endpoints) && is_array($endpoints) ? $endpoints : array();
	$schemas     = isset($schemas) && is_array($schemas) ? $schemas : array();
	$tokenHints  = isset($token_hints) && is_array($token_hints) ? $token_hints : array();
	$apiBaseUrl  = isset($api_base_url) ? $api_base_url : '';
	$openapiUrl  = isset($openapi_url) ? $openapi_url : '';
?>

<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/rest_api', '&laquo; Back to REST API', array('class' => 'image'));?>
	&nbsp;|&nbsp;
	<a href="<?php echo htmlspecialchars($openapiUrl, ENT_QUOTES);?>" target="_blank" rel="noopener">View OpenAPI 3.0 spec &rarr;</a>
</p>

<p class="fontSmall gray">
	Interactive reference for the suite's REST API. Every endpoint below has a <strong>Try it</strong> button that calls the real API live; responses render inline so you can see actual shapes before wiring up an integration. The OpenAPI spec linked above is the same content in a machine-readable form &mdash; importable into Postman, Insomnia, n8n, Stoplight, etc.
</p>

<p class="fontSmall gray">
	Base URL: <code><?php echo htmlspecialchars($apiBaseUrl, ENT_QUOTES);?></code>
</p>

<br>

<div style="background: #f4f8ff; border: 1px solid #99c; padding: 12px;">
	<?php echo text_output('Your API token', 'h3', 'page-subhead');?>
	<p class="fontSmall">
		Paste the raw <code>scapi_...</code> value here. It's used only by this page's Try It buttons (sent as <code>X-API-Key</code> on each request) &mdash; never stored, never logged.
	</p>
	<p>
		<input type="text" id="sc-api-token" placeholder="scapi_..."
			style="width: 100%; font-family: monospace; font-size: 13px; padding: 6px;">
	</p>
	<?php if ( ! empty($tokenHints)): ?>
		<p class="fontSmall gray">
			Active tokens on this sim:
			<?php $first = true; foreach ($tokenHints as $t): ?>
				<?php
					$scopes = json_decode($t->scopes, true);
					if ( ! is_array($scopes)) { $scopes = array(); }
				?>
				<?php if ( ! $first) echo ' &middot; '; $first = false;?>
				<strong><?php echo htmlspecialchars($t->label, ENT_QUOTES);?></strong>
				<code><?php echo htmlspecialchars($t->token_prefix, ENT_QUOTES);?>&hellip;</code>
				<span class="gray">(<?php echo $scopes ? htmlspecialchars(implode(', ', $scopes), ENT_QUOTES) : 'no scopes';?>)</span>
			<?php endforeach;?>
		</p>
	<?php else: ?>
		<p class="fontSmall orange">
			No active tokens. <?php echo anchor('extensions/nova_ext_sim_central/Manage/rest_api', 'Create one on the REST API page');?> first.
		</p>
	<?php endif;?>
</div>

<br>

<?php foreach ($endpoints as $idx => $ep): ?>
	<?php
		$opId      = $ep['operation_id'];
		$pathParam = null;
		$queryParams = array();
		foreach ($ep['parameters'] as $p) {
			if ($p['in'] === 'path') { $pathParam = $p; }
			else { $queryParams[] = $p; }
		}
		$methodColor = strtolower($ep['method']) === 'get' ? '#2a7' : '#a72';
	?>
	<div style="border: 1px solid #ccc; margin-bottom: 18px;">
		<div style="background: #f7f7f7; padding: 8px 12px; border-bottom: 1px solid #ccc;">
			<span style="display: inline-block; background: <?php echo $methodColor;?>; color: white; font-family: monospace; font-weight: bold; padding: 2px 8px; border-radius: 3px; font-size: 12px;"><?php echo htmlspecialchars($ep['method'], ENT_QUOTES);?></span>
			&nbsp;
			<code style="font-size: 14px;"><?php echo htmlspecialchars($ep['path'], ENT_QUOTES);?></code>
			&nbsp;&nbsp;
			<span class="fontSmall gray">
				<?php if ( ! empty($ep['no_auth'])): ?>
					<span style="color: #888;">no auth</span>
				<?php elseif ($ep['scope']): ?>
					scope: <code><?php echo htmlspecialchars($ep['scope'], ENT_QUOTES);?></code>
				<?php else: ?>
					any valid token
				<?php endif;?>
			</span>
		</div>

		<div style="padding: 12px;">
			<p><strong><?php echo htmlspecialchars($ep['summary'], ENT_QUOTES);?></strong></p>
			<p class="fontSmall"><?php echo htmlspecialchars($ep['description'], ENT_QUOTES);?></p>

			<?php if ( ! empty($ep['parameters'])): ?>
				<p class="fontSmall gray"><strong>Parameters</strong></p>
				<table class="table100 zebra fontSmall" style="margin-bottom: 12px;">
					<thead><tr><th>Name</th><th>In</th><th>Type</th><th>Default</th><th>Description</th></tr></thead>
					<tbody>
					<?php foreach ($ep['parameters'] as $p): ?>
						<tr class="alt">
							<td><code><?php echo htmlspecialchars($p['name'], ENT_QUOTES);?></code><?php echo ! empty($p['required']) ? ' <span class="red">*</span>' : '';?></td>
							<td><?php echo htmlspecialchars($p['in'], ENT_QUOTES);?></td>
							<td><?php echo htmlspecialchars($p['type'], ENT_QUOTES);?></td>
							<td><?php echo ($p['default'] !== null) ? '<code>'.htmlspecialchars(var_export($p['default'], true), ENT_QUOTES).'</code>' : '<span class="gray">&mdash;</span>';?></td>
							<td><?php echo htmlspecialchars($p['description'], ENT_QUOTES);?></td>
						</tr>
					<?php endforeach;?>
					</tbody>
				</table>
			<?php endif;?>

			<p class="fontSmall gray"><strong>Response:</strong> <code><?php echo htmlspecialchars($ep['response_schema'], ENT_QUOTES);?></code>
				<?php if (isset($schemas[$ep['response_schema']])): ?>
					<a href="#" onclick="document.getElementById('sc-schema-<?php echo $opId;?>').style.display = document.getElementById('sc-schema-<?php echo $opId;?>').style.display === 'block' ? 'none' : 'block'; return false;">[toggle shape]</a>
					<pre id="sc-schema-<?php echo $opId;?>" style="display: none; background: #fafafa; border: 1px solid #ddd; padding: 8px; font-size: 11px; margin-top: 6px;"><?php echo htmlspecialchars(json_encode($schemas[$ep['response_schema']], JSON_PRETTY_PRINT), ENT_QUOTES);?></pre>
				<?php endif;?>
			</p>

			<?php if ( ! empty($ep['try_it'])): ?>
				<div style="background: #fafafa; border: 1px solid #ddd; padding: 8px; margin-top: 12px;">
					<strong class="fontSmall">Try it</strong>
					<table class="fontSmall" style="margin-top: 6px;">
						<?php foreach ($ep['parameters'] as $p): ?>
							<tr>
								<td style="padding: 4px 8px;"><code><?php echo htmlspecialchars($p['name'], ENT_QUOTES);?></code></td>
								<td style="padding: 4px 8px;">
									<input type="text" data-sc-param="<?php echo htmlspecialchars($p['name'], ENT_QUOTES);?>" data-sc-in="<?php echo htmlspecialchars($p['in'], ENT_QUOTES);?>"
										placeholder="<?php echo ($p['default'] !== null) ? htmlspecialchars((string) $p['default'], ENT_QUOTES) : '';?>"
										style="font-family: monospace; font-size: 12px;">
								</td>
							</tr>
						<?php endforeach;?>
					</table>
					<p style="margin-top: 8px;">
						<button type="button" class="button-main" onclick="scApiTryIt(this, '<?php echo $opId;?>', '<?php echo htmlspecialchars($ep['method'], ENT_QUOTES);?>', '<?php echo htmlspecialchars($ep['path'], ENT_QUOTES);?>', <?php echo ! empty($ep['no_auth']) ? 'true' : 'false';?>)"><span>Try it</span></button>
						<button type="button" class="button-sec" onclick="scApiCopyCurl(this, '<?php echo $opId;?>', '<?php echo htmlspecialchars($ep['method'], ENT_QUOTES);?>', '<?php echo htmlspecialchars($ep['path'], ENT_QUOTES);?>', <?php echo ! empty($ep['no_auth']) ? 'true' : 'false';?>)"><span>Copy curl</span></button>
						<span class="fontSmall gray" id="sc-status-<?php echo $opId;?>"></span>
					</p>
					<pre id="sc-response-<?php echo $opId;?>" style="display: none; background: #1e1e1e; color: #e0e0e0; padding: 10px; font-size: 11px; max-height: 400px; overflow: auto;"></pre>
				</div>
			<?php endif;?>
		</div>
	</div>
<?php endforeach;?>

<script>
(function() {
	var API_BASE = <?php echo json_encode($apiBaseUrl);?>;

	// Build the request URL for an endpoint by reading the inputs the user
	// filled in. Path params get substituted; query params get appended.
	function buildUrl(parentEl, path) {
		var inputs = parentEl.querySelectorAll('input[data-sc-param]');
		var qs = [];
		inputs.forEach(function(inp) {
			var name = inp.getAttribute('data-sc-param');
			var where = inp.getAttribute('data-sc-in');
			var val = inp.value.trim();
			if (val === '') { return; }
			if (where === 'path') {
				path = path.replace('{' + name + '}', encodeURIComponent(val));
			} else {
				qs.push(encodeURIComponent(name) + '=' + encodeURIComponent(val));
			}
		});
		// If a required path param wasn't filled in, the placeholder stays.
		// We don't try to be clever - the server will just 404 and the user
		// will see the response, which is the desired feedback.
		return API_BASE + path + (qs.length ? '?' + qs.join('&') : '');
	}

	function findCard(btn) {
		var el = btn;
		while (el && el.tagName !== 'DIV') { el = el.parentElement; }
		while (el && ! el.querySelector('input[data-sc-param]') && el.parentElement) {
			el = el.parentElement;
		}
		return el;
	}

	function getToken() {
		var el = document.getElementById('sc-api-token');
		return el ? el.value.trim() : '';
	}

	window.scApiTryIt = function(btn, opId, method, path, noAuth) {
		var card = findCard(btn);
		var url = buildUrl(card, path);
		var statusEl = document.getElementById('sc-status-' + opId);
		var responseEl = document.getElementById('sc-response-' + opId);
		var token = getToken();

		if ( ! noAuth && token === '') {
			statusEl.innerHTML = '<span class="red">Paste an API token at the top of the page first.</span>';
			return;
		}

		statusEl.innerHTML = 'Sending&hellip;';
		responseEl.style.display = 'none';

		var headers = {};
		if ( ! noAuth) { headers['X-API-Key'] = token; }

		var t0 = performance.now();
		fetch(url, { method: method, headers: headers })
			.then(function(r) {
				var ms = Math.round(performance.now() - t0);
				return r.text().then(function(body) {
					var statusColor = r.ok ? 'green' : 'red';
					statusEl.innerHTML = '<span class="' + statusColor + '">' + r.status + ' ' + r.statusText + '</span> &middot; ' + ms + ' ms &middot; ' + url;
					try {
						var json = JSON.parse(body);
						responseEl.textContent = JSON.stringify(json, null, 2);
					} catch (e) {
						responseEl.textContent = body || '(empty body)';
					}
					responseEl.style.display = 'block';
				});
			})
			.catch(function(err) {
				statusEl.innerHTML = '<span class="red">Network error: ' + err.message + '</span>';
			});
	};

	window.scApiCopyCurl = function(btn, opId, method, path, noAuth) {
		var card = findCard(btn);
		var url = buildUrl(card, path);
		var token = getToken();
		var headerPart = noAuth ? '' : ' -H "X-API-Key: ' + (token || 'scapi_...') + '"';
		var cmd = "curl -X " + method + headerPart + ' "' + url + '"';
		var statusEl = document.getElementById('sc-status-' + opId);

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(cmd).then(function() {
				statusEl.innerHTML = '<span class="green">curl copied to clipboard.</span>';
			}, function() {
				statusEl.innerHTML = '<span class="red">Couldn\'t copy &mdash; here it is:</span> <code>' + cmd + '</code>';
			});
		} else {
			statusEl.innerHTML = '<code>' + cmd + '</code>';
		}
	};
})();
</script>
