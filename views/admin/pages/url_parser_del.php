<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');?>

<?php echo text_output($header, 'h2');?>

<?php echo text_output($text);?>

<?php echo form_open('extensions/nova_ext_sim_central/Manage/url_parser/delete/'. $id);?>
	<?php echo form_hidden('id', $id);?>
