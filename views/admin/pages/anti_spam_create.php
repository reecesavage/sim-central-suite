<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/anti_spam', '&laquo; Back to Anti Spam Questions', array('class' => 'image'));?>
</p>

<?php echo form_open('extensions/nova_ext_sim_central/Manage/anti_spam_create/');?>
	<p>
		<kbd>Question</kbd>
		<textarea name="question" required rows="4"></textarea>
	</p>

	<div class="row">
		<p><kbd>Acceptable answers</kbd></p>

		<div class="answer" id="answer_0" data-id="0">
			<div class="col s12 m10 l10">
				<input type="text" name="answer[]" required value="">
			</div>
			<div class="col s12 m2 l2">
				<a class="remove-more" data-id="0">Remove Row</a>
			</div>
		</div>

		<div class="append_html"></div>

		<div class="col s12">
			<a class="add-more">+ Add Row</a>
		</div>
	</div>

	<br>
	<button name="submit" type="submit" class="button-main" value="Submit"><span>Submit</span></button>
<?php echo form_close(); ?>
