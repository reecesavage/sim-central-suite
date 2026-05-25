<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/anti_spam', '&laquo; Back to Anti Spam Questions', array('class' => 'image'));?>
</p>

<?php $jsonDecode = json_decode($model->setting_value, true) ?>

<?php echo form_open("extensions/nova_ext_sim_central/Manage/anti_spam_edit/$model->setting_id");?>
	<p>
		<kbd>Question</kbd>
		<textarea name="question" required rows="4"><?php echo htmlspecialchars(isset($jsonDecode['question']) ? $jsonDecode['question'] : '', ENT_QUOTES);?></textarea>
	</p>

	<div class="row">
		<p><kbd>Acceptable answers</kbd></p>

		<?php foreach ((isset($jsonDecode['answer']) ? $jsonDecode['answer'] : array()) as $key => $answer): ?>
			<div class="answer" id="answer_<?php echo $key;?>" data-id="<?php echo $key;?>">
				<div class="col s12 m10 l10">
					<input type="text" name="answer[]" required value="<?php echo htmlspecialchars($answer, ENT_QUOTES);?>">
				</div>
				<div class="col s12 m2 l2">
					<a class="remove-more" data-id="<?php echo $key;?>">Remove Row</a>
				</div>
			</div>
		<?php endforeach; ?>

		<div class="append_html"></div>

		<div class="col s12">
			<a class="add-more">+ Add Row</a>
		</div>
	</div>

	<br>
	<button name="submit" type="submit" class="button-main" value="Submit"><span>Submit</span></button>
<?php echo form_close(); ?>
