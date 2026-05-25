<?php echo text_output($title, 'h1', 'page-head');?>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/index', '&laquo; Back to Sim Central Suite', array('class' => 'image'));?>
</p>

<p>
	<?php echo anchor('extensions/nova_ext_sim_central/Manage/url_parser_create', img($images['add']).' '.'Add Tag', array('class' => 'image'));?>
</p>

<?php if ( ! empty($models)): ?>
	<table class="table100 zebra">
		<tbody>
		<?php foreach ($models as $model): ?>
			<tr class="alt">
				<td>
					<strong><?php echo htmlspecialchars($model->title, ENT_QUOTES);?></strong><br />
					<span class="gray fontSmall">
						<strong><?php echo htmlspecialchars($model->url, ENT_QUOTES);?></strong>
					</span>
				</td>
				<td class="col_75 align_right">
					<a href="#" myAction="delete" myID="<?php echo $model->id;?>" rel="facebox" class="image"><?php echo img($images['delete']);?></a>
					&nbsp;
					<?php echo anchor('extensions/nova_ext_sim_central/Manage/url_parser_edit/'.$model->id, img($images['edit']), array('class' => 'image'));?>
				</td>
			</tr>
		<?php endforeach;?>
		</tbody>
	</table>
<?php else: ?>
	<?php echo text_output('No tags yet. Add one above.', 'h3', 'orange');?>
<?php endif;?>
