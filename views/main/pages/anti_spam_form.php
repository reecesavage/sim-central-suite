<p>
	<kbd><?php echo $label['nova_ext_anti_spam_questions_question'] ?> <span class="red bold">*</span></kbd>

	<input type="hidden" name="nova_ext_anti_spam_questions_setting_id" value="<?=$inputs['nova_ext_anti_spam_questions_setting_id']?>">
	<?php echo form_input($inputs['nova_ext_anti_spam_questions_answer']) ?>
</p>
<script type="text/javascript">
// Submit guard for the security question. The input deliberately has no
// HTML `required` attribute: on skins that render the form inside jQuery
// UI tabs (LCARS), a required control in a hidden tab panel makes the
// browser abort the submit with only a console warning and zero visible
// feedback. This guard alerts instead, reveals the tab holding the
// question when it can, and the server re-checks the answer regardless.
(function(){
	if (window.novaExtAntiSpamGuard) { return; }
	window.novaExtAntiSpamGuard = true;
	document.addEventListener("submit", function(e){
		var form = e.target;
		var input = form && form.querySelector
			? form.querySelector('input[name="nova_ext_anti_spam_questions_answer"]')
			: null;
		if (!input) { return; }
		if (String(input.value || "").replace(/^\s+|\s+$/g, "") !== "") { return; }
		e.preventDefault();
		e.stopPropagation();
		alert("Please answer the security question (marked with *) before submitting.");
		try {
			// If the question sits in a hidden tab panel, click that
			// panel's tab link so the user can see the field.
			var panel = input.closest ? input.closest('div[id]') : null;
			if (panel && panel.offsetParent === null) {
				var link = document.querySelector('a[href="#' + panel.id + '"]');
				if (link) { link.click(); }
			}
			input.scrollIntoView({ block: "center" });
			input.focus();
		} catch (err) {}
	}, true);
})();
</script>
