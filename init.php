<?php

// Sim Central Suite - bootstrap.
//
// Reads the per-feature toggle state from config.json and conditionally
// requires each enabled feature's library + event files. Disabled features
// load nothing, so their events never register and their listeners stay out
// of the way. The Manage controller handles toggling, shim install/uninstall,
// and the conflict check against standalone extensions.

require_once dirname(__FILE__).'/controllers/Installer.php';
$manager = (new \nova_ext_sim_central\Installer())->install();

// Generator is used by most features' "view output" listeners to inject HTML
// at specific CSS selectors. Load it unconditionally - it's small, doesn't
// touch the DB, and dropping it from a feature's require list would mean
// also remembering to load it before any event that needs it.
require_once dirname(__FILE__).'/libraries/Generator.php';

// Config layers a row in the `settings` table on top of the bundled
// config.json so user-modified feature toggles, labels, and settings
// survive a code upgrade. See libraries/Config.php.
require_once dirname(__FILE__).'/libraries/Config.php';

// UpdateCheck displays "new version available" on the dashboard. It's
// dormant unless the dashboard explicitly calls latest(), so loading
// it unconditionally is cheap.
require_once dirname(__FILE__).'/libraries/UpdateCheck.php';

// Updater handles the dashboard's one-click upgrade. Dormant until
// invoked from the Manage controller's do_update action.
require_once dirname(__FILE__).'/libraries/Updater.php';

// TimelineFormat is consumed by Feed.php (loaded for summary OR ordered)
// and by ordered_mission_posts events; load unconditionally so both
// gates get it without duplication.
require_once dirname(__FILE__).'/libraries/TimelineFormat.php';

// ContentFilter is consumed by Feed.php (when the suite feature is on),
// the content_filter events (when the feature is on), and the Manage
// controller (so the admin Configure page can render even before the
// feature has been enabled). Cheap to load unconditionally.
require_once dirname(__FILE__).'/libraries/ContentFilter.php';

$simCentralFeatures = \nova_ext_sim_central\Config::features();

// ---------- Display Name ----------
if ( ! empty($simCentralFeatures['display_name'])) {
	require_once dirname(__FILE__).'/libraries/Character.php';
	require_once dirname(__FILE__).'/events/display_name_db.php';
	require_once dirname(__FILE__).'/events/display_name_location_admin_characters_bio.php';
	require_once dirname(__FILE__).'/events/display_name_location_admin_characters_create.php';
	require_once dirname(__FILE__).'/events/display_name_location_admin_characters_index.php';
	require_once dirname(__FILE__).'/events/display_name_location_admin_characters_npcs.php';
	require_once dirname(__FILE__).'/events/display_name_location_main_join2.php';
	require_once dirname(__FILE__).'/events/display_name_location_main_personnel_character.php';
}

// ---------- Anti Spam Questions ----------
if ( ! empty($simCentralFeatures['anti_spam'])) {
	require_once dirname(__FILE__).'/libraries/AntiSpam.php';
	require_once dirname(__FILE__).'/events/anti_spam_location_main_contact.php';
	require_once dirname(__FILE__).'/events/anti_spam_location_main_join.php';
}

// ---------- Mission Post Summary ----------
// Feed.php is shared with ordered_mission_posts and content_filter when
// any of them is enabled; the Feed library handles each integration based
// on what's actually enabled.
if ( ! empty($simCentralFeatures['summary'])
	|| ! empty($simCentralFeatures['ordered_mission_posts'])
	|| ! empty($simCentralFeatures['content_filter'])) {
	require_once dirname(__FILE__).'/libraries/Feed.php';
}

// ---------- Content Filter ----------
if ( ! empty($simCentralFeatures['content_filter'])) {
	require_once dirname(__FILE__).'/events/content_filter_db.php';
	require_once dirname(__FILE__).'/events/content_filter_location_admin_write_missionpost.php';
	require_once dirname(__FILE__).'/events/content_filter_location_main_sim_viewpost.php';
}
if ( ! empty($simCentralFeatures['summary'])) {
	require_once dirname(__FILE__).'/events/summary_db.php';
	require_once dirname(__FILE__).'/events/summary_location_admin_add_mission.php';
	require_once dirname(__FILE__).'/events/summary_location_admin_manage_posts_edit.php';
	require_once dirname(__FILE__).'/events/summary_location_admin_write_missionpost.php';
	require_once dirname(__FILE__).'/events/summary_location_main_sim_viewpost.php';
	require_once dirname(__FILE__).'/events/summary_parser_parse_string_nova_missionpost.php';
}

// ---------- URL Parser ----------
if ( ! empty($simCentralFeatures['url_parser'])) {
	require_once dirname(__FILE__).'/libraries/UrlParser.php';
	foreach (glob(dirname(__FILE__).'/events/url_parser_*.php') as $eventFile) {
		require_once $eventFile;
	}
}

// ---------- Ordered Mission Posts ----------
// Feed.php shim is shared with summary when both are enabled; that's already
// handled above via the summary OR ordered_mission_posts gate. The PostNumber
// + Email libraries are exclusive to this feature.
if ( ! empty($simCentralFeatures['ordered_mission_posts'])) {
	require_once dirname(__FILE__).'/libraries/PostNumber.php';
	require_once dirname(__FILE__).'/libraries/PostWordCount.php';
	require_once dirname(__FILE__).'/libraries/Email.php';

	require_once dirname(__FILE__).'/events/ordered_template_render.php';
	require_once dirname(__FILE__).'/events/ordered_db.php';
	require_once dirname(__FILE__).'/events/ordered_location_admin_add_mission.php';
	require_once dirname(__FILE__).'/events/ordered_location_admin_manage_missions.php';
	require_once dirname(__FILE__).'/events/ordered_location_admin_manage_posts_edit.php';
	require_once dirname(__FILE__).'/events/ordered_location_admin_write_missionpost.php';
	require_once dirname(__FILE__).'/events/ordered_location_main_sim_listposts.php';
	require_once dirname(__FILE__).'/events/ordered_location_main_sim_missions.php';
	require_once dirname(__FILE__).'/events/ordered_location_main_sim_missions_one.php';
	require_once dirname(__FILE__).'/events/ordered_location_main_sim_viewpost.php';
}
