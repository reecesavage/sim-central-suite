<?php /*
	Per-post age-gate toggle injected into the write/edit-post form.

	The hidden field with the same name guarantees PHP always sees a
	0 or 1 in $_POST regardless of whether the toggle is on - hidden
	comes first, then the checkbox, and PHP takes the last duplicate.

	The checkbox is visually styled as an iOS/Android-style slider via
	the inline <style> block below. It's still a real <input type="checkbox">
	underneath so accessibility, keyboard control, and the form-submission
	behavior all work unchanged.
*/ ?>

<style>
	.nova-ext-cf-toggle {
		display: inline-flex;
		align-items: center;
		gap: 12px;
		margin: 0;
	}
	.nova-ext-cf-toggle kbd {
		margin: 0;
	}
	.nova-ext-cf-switch {
		position: relative;
		display: inline-block;
		width: 46px;
		height: 24px;
		vertical-align: middle;
	}
	.nova-ext-cf-switch input {
		opacity: 0;
		width: 0;
		height: 0;
		margin: 0;
		position: absolute;
	}
	.nova-ext-cf-slider {
		position: absolute;
		cursor: pointer;
		inset: 0;
		background: #c4c8d0;
		border-radius: 24px;
		transition: background 0.18s ease-out;
	}
	.nova-ext-cf-slider:before {
		content: "";
		position: absolute;
		height: 18px;
		width: 18px;
		left: 3px;
		top: 3px;
		background: #ffffff;
		border-radius: 50%;
		transition: transform 0.18s ease-out;
		box-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
	}
	.nova-ext-cf-switch input:checked + .nova-ext-cf-slider {
		background: #38a169;
	}
	.nova-ext-cf-switch input:checked + .nova-ext-cf-slider:before {
		transform: translateX(22px);
	}
	.nova-ext-cf-switch input:focus-visible + .nova-ext-cf-slider {
		outline: 2px solid #4c6ef5;
		outline-offset: 2px;
	}
</style>

<p class="nova-ext-cf-toggle">
	<kbd><?php echo $label_toggle;?></kbd>
	<label class="nova-ext-cf-switch" for="nova_ext_content_filter_age_gated">
		<input type="hidden" name="nova_ext_content_filter_age_gated" value="0">
		<input type="checkbox" name="nova_ext_content_filter_age_gated" value="1"
			id="nova_ext_content_filter_age_gated" <?php echo $gated ? 'checked' : '';?>>
		<span class="nova-ext-cf-slider" aria-hidden="true"></span>
	</label>
</p>

<?php if ( ! empty($definitions)): ?>
	<p class="nova_ext_content_filter_definitions fontSmall gray italic">
		<?php echo htmlspecialchars($help_intro, ENT_QUOTES);?>
		<br />
		<?php foreach ($definitions as $dim => $def): ?>
			&bull; <?php echo htmlspecialchars($def, ENT_QUOTES);?><br />
		<?php endforeach; ?>
	</p>
<?php endif; ?>

<script type="text/javascript">
(function() {
	var cb = document.getElementById('nova_ext_content_filter_age_gated');
	if ( ! cb || ! cb.form) return;
	var form          = cb.form;
	var confirmText   = <?php echo json_encode($confirm_text_js);?>;
	var confirmOnSave = <?php echo ! empty($confirm_on_save) ? 'true' : 'false';?>;

	// The write-post form has three buttons, all name="submit" with
	// value post/save/delete (Post is also id="submitPost", Delete is
	// id="submitDelete"). We confirm on publish always; on save only when
	// the admin opted in; never on delete. Bind directly to each button so
	// detection doesn't depend on the button's `type` or on SubmitEvent
	// .submitter support - mousedown/click fire before the form submits.
	var pending = '';
	function mark(el, action) {
		if ( ! el) return;
		var set = function() { pending = action; };
		el.addEventListener('mousedown', set, true);
		el.addEventListener('click', set, true);
		el.addEventListener('keydown', function(ev) {
			if (ev.keyCode === 13 || ev.keyCode === 32) set();
		}, true);
	}
	mark(document.getElementById('submitPost'), 'post');
	mark(document.getElementById('submitDelete'), 'delete');
	var subs = form.querySelectorAll('[name="submit"]');
	for (var i = 0; i < subs.length; i++) {
		var v = (subs[i].value || subs[i].getAttribute('value') || '').toLowerCase();
		if (v) { mark(subs[i], v); }
	}

	// Capture-phase listener so this runs before any Nova-stock handler
	// that might also be wired to the same form, and so cancelling stops
	// the submission whether or not other listeners would have run.
	form.addEventListener('submit', function(e) {
		if (cb.checked) return;

		var action = (e.submitter && e.submitter.value)
			? String(e.submitter.value).toLowerCase()
			: pending;

		// Never prompt on delete.
		if (action === 'delete') return;
		// Prompt on save only when opted in.
		if (action === 'save' && ! confirmOnSave) return;
		// 'post' (publish) always prompts; an undetectable action falls
		// through to prompting too, which is the safe default.

		if ( ! window.confirm(confirmText)) {
			e.preventDefault();
			e.stopPropagation();
		}
	}, true);
})();
</script>
