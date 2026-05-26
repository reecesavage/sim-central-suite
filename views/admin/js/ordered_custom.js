$(document).ready(function() {

	// Date / time inputs are native HTML5 (type="date" / type="time") as of
	// v1.0.1. The browser handles the picker UI, value formatting, and
	// keyboard nav, so there's no jQuery datepicker init or data-value
	// round-trip required here anymore.

	var mission = $('[name="mission"]').val();
	if (typeof mission === "undefined") {
		mission = $('[name="post_mission"]').val();
	}

	var config = $('[name="mission_ext_ordered_config_setting"]').val();

	showHideDefault(config);
	getMission(mission);

	$(document).on("change", '[name="mission"]', function(e) {
		mission = $(this).val();
		getMission(mission);
		$('[name="nova_ext_ordered_post_date"]').val('');
		$('[name="nova_ext_ordered_post_stardate"]').val('');
	});

	$(document).on("change", '[name="mission_ext_ordered_config_setting"]', function(e) {
		config = $(this).val();
		showHideDefault(config);
	});

	function showHideDefault(config) {
		$('.mission_ext_ordered_legacy_mode').css("display", 'none');

		if (config == 'date_time') {
			$('.mission_ext_ordered_default_mission_date').css("display", "block");
			$('.mission_ext_ordered_default_stardate').css("display", "none");
		} else if (config == 'stardate') {
			$('.mission_ext_ordered_default_mission_date').css("display", "none");
			$('.mission_ext_ordered_default_stardate').css("display", "block");
		} else if (config == 'day_time') {
			$('.mission_ext_ordered_default_mission_date').css("display", "none");
			$('.mission_ext_ordered_default_stardate').css("display", "none");

			if ($('[name="mission_ext_ordered_legacy_mode"]').attr('data-legacy') == 1) {
				$('.mission_ext_ordered_legacy_mode').css("display", 'block');
			}
		} else {
			$('.mission_ext_ordered_default_mission_date').css("display", "none");
			$('.mission_ext_ordered_default_stardate').css("display", "none");
		}
	}


	$(document).on("change", '[name="post_mission"]', function(e) {
		mission = $(this).val();
		getMission(mission);
		$('[name="nova_ext_ordered_post_date"]').val('');
		$('[name="nova_ext_ordered_post_stardate"]').val('');
	});

	function getMission(mission) {
		$.ajax({
			type: "get",
			url: "<?php echo site_url('extensions/nova_ext_sim_central/Ajax/ordered_mission')?>",
			data: {
				mission: mission
			},
			success: function(data) {
				var response = JSON.parse(data);
				if (response.status == 'OK') {

					if (response.post.mission_ext_ordered_legacy_mode == 1 && response.post.mission_ext_ordered_config_setting == 'day_time') {
						$('#nova_ext_ordered_post_day').attr('name', 'post_chronological_mission_post_day');
						$('#nova_ext_ordered_post_time').attr('name', 'post_chronological_mission_post_time');
					} else {
						$('#nova_ext_ordered_post_day').attr('name', 'nova_ext_ordered_post_day');
						$('#nova_ext_ordered_post_time').attr('name', 'nova_ext_ordered_post_time');
					}
					showHideFields(response.post.mission_ext_ordered_config_setting);

					var dateValue = $('[name="nova_ext_ordered_post_date"]').val();
					var stardate = $('[name="nova_ext_ordered_post_stardate"]').val();
					if (response.post.mission_ext_ordered_default_mission_date != null && dateValue == "") {
						$('[name="nova_ext_ordered_post_date"]').val(response.post.mission_ext_ordered_default_mission_date);
					}
					if (response.post.mission_ext_ordered_default_stardate != null && stardate == "") {
						$('[name="nova_ext_ordered_post_stardate"]').val(response.post.mission_ext_ordered_default_stardate);
					}
				}
			}
		});
	}

	function showHideFields(configId) {
		if (configId == 'day_time') {
			hideTimeLine();
			showDayTime();
			hideDateTime();
			hideStartDateTime();
		} else if (configId == 'date_time') {
			hideTimeLine();
			hideDayTime();
			hideStartDateTime();
			showDateTime();
		} else if (configId == 'stardate') {
			hideTimeLine();
			hideDayTime();
			hideDateTime();
			showStartDateTime();
		} else {
			showTimeLine();
			hideDayTime();
			hideDateTime();
			hideStartDateTime();
		}
	}

	function hideTimeLine() {
		$("#timeline").prev().css("display", "none");
		$("#timeline").css("display", "none");

		$('[name="post_timeline"]').prev().css("display", "none");
		$('[name="post_timeline"]').css("display", "none");

		$(".nova_ext_ordered_label_post_time").css("display", "block");
	}

	function showTimeLine() {
		$("#timeline").prev().css("display", "block");
		$("#timeline").css("display", "block");

		$('[name="post_timeline"]').prev().css("display", "block");
		$('[name="post_timeline"]').css("display", "block");
		$(".nova_ext_ordered_label_post_time").css("display", "none");
	}

	function hideDayTime()       { $(".nova_ext_ordered_label_post_day").css("display", "none"); }
	function showDayTime()       { $(".nova_ext_ordered_label_post_day").css("display", "block"); }
	function hideDateTime()      { $(".nova_ext_ordered_label_post_date").css("display", "none"); }
	function showDateTime()      { $(".nova_ext_ordered_label_post_date").css("display", "block"); }
	function hideStartDateTime() { $(".nova_ext_ordered_label_post_stardate").css("display", "none"); }
	function showStartDateTime() { $(".nova_ext_ordered_label_post_stardate").css("display", "block"); }
});
