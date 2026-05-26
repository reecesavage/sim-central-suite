<p class="nova_ext_content_filter_toggle">
	<kbd><?php echo $label_toggle;?></kbd>
	<?php /*
		Hidden field with the same name as the checkbox ensures the form
		always submits a value: 0 when unchecked, 1 when checked (PHP
		takes the last duplicate-named field). Saves us a `_present`
		sentinel column.
	*/ ?>
	<input type="hidden" name="nova_ext_content_filter_age_gated" value="0">
	<input type="checkbox" name="nova_ext_content_filter_age_gated" value="1"
		id="nova_ext_content_filter_age_gated" <?php echo $gated ? 'checked' : '';?>>
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
	var form        = cb.form;
	var confirmText = <?php echo json_encode($confirm_text_js);?>;

	// Capture-phase listener so this runs before any Nova-stock handler
	// that might also be wired to the same form, and so cancelling stops
	// the submission whether or not other listeners would have run.
	form.addEventListener('submit', function(e) {
		if (cb.checked) return;
		if ( ! window.confirm(confirmText)) {
			e.preventDefault();
			e.stopPropagation();
		}
	}, true);
})();
</script>
