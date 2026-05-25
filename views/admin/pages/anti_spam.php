<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/index', '&laquo; Back to Sim Central Suite', array('class' => 'image'));?>
</p>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/anti_spam_create', img($images['add']).' '.'Add Question', array('class' => 'image'));?>
</p>

<?php if ( ! empty($models)): ?>
	<table class="table100 zebra">
		<tbody>
		<?php foreach ($models as $model): ?>
			<?php $jsonDecode = json_decode($model->setting_value, true); ?>
			<tr class="alt">
				<td>
					<strong><?php echo htmlspecialchars(isset($jsonDecode['question']) ? $jsonDecode['question'] : '', ENT_QUOTES);?></strong><br />
					<span class="gray fontSmall">
						<?php echo htmlspecialchars(implode(', ', isset($jsonDecode['answer']) ? $jsonDecode['answer'] : array()), ENT_QUOTES);?>
					</span>
				</td>
				<td class="col_75 align_right">
					<a href="#" myAction="delete" myID="<?php echo $model->setting_id;?>" rel="facebox" class="image"><?php echo img($images['delete']);?></a>
					<?php echo anchor('extensions/nova_ext_sim_central/Manage/anti_spam_edit/'.$model->setting_id, img($images['edit']), array('class' => 'image'));?>
				</td>
			</tr>
		<?php endforeach;?>
		</tbody>
	</table>
<?php else: ?>
	<?php echo text_output('No questions yet. Add one above.', 'h3', 'orange');?>
<?php endif;?>
