$(document).ready(function() {
	// Start past the highest server-rendered data-id so newly added rows
	// never collide with existing ones (jQuery's #id selector would match the
	// first DOM occurrence and remove the wrong row).
	var i = $('.answer').length;

	$(document).on("click",".add-more",function() {
		var html = "<div class='answer' id='answer_" + i + "' data-id=" + i + ">";
		html += "<div class='col s12 m10 l10'>";
		html += "<input type='text' name='answer[]' required value=''>";
		html += "</div>";
		html += "<div class='col s12 m2 l2'>";
		html += "<a class='remove-more' data-id=" + i + ">Remove Row</a>";
		html += "</div>";
		html += "</div>";
		i++;
		$(".append_html").append(html);
	});

	$(document).on("click",".remove-more",function() {
		var id = $(this).attr('data-id');
		$("#answer_" + id + "").remove();
	});

	$("a[rel*=facebox]").click(function() {
		var action = $(this).attr('myAction');
		var id = $(this).attr('myID');
		var location = '<?php echo site_url('extensions/nova_ext_sim_central/Ajax/anti_spam_del/'); ?>/' + id;

		$.facebox(function() {
			$.get(location, function(data) {
				$.facebox(data);
			});
		});

		return false;
	});
});
