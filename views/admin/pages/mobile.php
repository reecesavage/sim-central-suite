<?php
	$route     = isset($route) && is_array($route) ? $route : array();
	$status    = isset($route['status']) ? $route['status'] : 'present';
	$mobileUrl = isset($mobile_url) ? $mobile_url : '';
	$extUrl    = isset($ext_url) ? $ext_url : '';
?>

<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/index', '&laquo; Back to Sim Central Suite', array('class' => 'image'));?>
</p>

<p><?php echo $feature['summary'];?></p>

<?php if ($status === 'added'): ?>
	<div style="border: 1px solid #2a7; background: #f0fff4; padding: 10px; margin-bottom: 12px;">
		<span class="fontSmall">&#10003; The clean <code>/mobile</code> URL is now active (a route hook was added to <code>application/config/hooks.php</code>).</span>
	</div>
<?php elseif (in_array($status, array('manual', 'unreadable', 'missing'), true)): ?>
	<div style="border: 2px solid #e6a700; background: #fffbe6; padding: 12px; margin-bottom: 12px;">
		<?php echo text_output('Action needed for the clean /mobile URL', 'h3', 'orange');?>
		<p class="fontSmall">
			The suite couldn't write to <code><?php echo htmlspecialchars(isset($route['path']) ? $route['path'] : 'application/config/hooks.php', ENT_QUOTES);?></code>.
			The mobile site still works at the full URL below; to enable the short <code>/mobile</code> URL, add this to <code>application/config/hooks.php</code> by hand (after the opening <code>&lt;?php</code>):
		</p>
		<code>$hook['pre_system'][] = array('class' =&gt; '', 'function' =&gt; 'sim_central_mobile_route', 'filename' =&gt; 'mobile_route_hook.php', 'filepath' =&gt; 'extensions/nova_ext_sim_central/hooks');</code>
	</div>
<?php endif; ?>

<br>
<?php echo text_output('How members reach it', 'h3', 'page-subhead');?>
<p>
	Share this link with your members &mdash; they log in there and manage their mission posts on mobile:
</p>
<p><strong><a href="<?php echo htmlspecialchars($mobileUrl, ENT_QUOTES);?>"><?php echo htmlspecialchars($mobileUrl, ENT_QUOTES);?></a></strong></p>
<p class="fontSmall gray">
	Always-available full URL (works even without the route hook):<br>
	<code><?php echo htmlspecialchars($extUrl, ENT_QUOTES);?></code>
</p>

<br>
<?php echo text_output('What it does', 'h3', 'page-subhead');?>
<p class="fontSmall gray">
	A lightweight, phone-friendly interface: members sign in (honouring your Discord Sign-In settings if that feature is on),
	see their drafts and recent posts, and create, edit, save, post, or delete their own mission posts &mdash; under the same
	permissions Nova enforces on the desktop site. It shares the suite's post-writing engine, so webhooks, emails, ordered
	timelines, and moderation behave exactly as they do everywhere else.
</p>
