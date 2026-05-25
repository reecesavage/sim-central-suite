$(document).ready(function() {
	$("a[rel*=facebox]").click(function() {
		var action = $(this).attr('myAction');
		var id = $(this).attr('myID');
		var location = '<?php echo site_url('extensions/nova_ext_sim_central/Ajax/url_parser_del/'); ?>/' + id;

		$.facebox(function() {
			$.get(location, function(data) {
				$.facebox(data);
			});
		});

		return false;
	});
});
