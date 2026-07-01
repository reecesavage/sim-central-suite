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

// Broker / SimCentralAccess / PhoneHome back the Sim Central access button
// and the periodic status report. Loaded unconditionally and cheap: nothing
// here touches the DB or network until something explicitly calls it
// (SimCentralAccess from the REST API page, PhoneHome from UpdateCheck).
require_once dirname(__FILE__).'/libraries/Broker.php';
require_once dirname(__FILE__).'/libraries/SimCentralAccess.php';
require_once dirname(__FILE__).'/libraries/PhoneHome.php';

// TimelineFormat is consumed by Feed.php (loaded for summary OR ordered)
// and by ordered_mission_posts events; load unconditionally so both
// gates get it without duplication.
require_once dirname(__FILE__).'/libraries/TimelineFormat.php';

// ContentFilter is consumed by Feed.php (when the suite feature is on),
// the content_filter events (when the feature is on), and the Manage
// controller (so the admin Configure page can render even before the
// feature has been enabled). Cheap to load unconditionally.
require_once dirname(__FILE__).'/libraries/ContentFilter.php';

// Admin-index update notice. Surfaces "new Sim Central release
// available" to gamemasters on the admin home, alongside Nova's own
// update notice. Loaded unconditionally - it self-skips when the
// viewer isn't a GM or there's nothing newer cached.
require_once dirname(__FILE__).'/events/system_admin_index_update_notice.php';

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

// Email::filter (the Write controller's _email shim delegate) mutates post /
// log / news notification emails for Summary, URL Parser, AND Ordered Mission
// Posts. Load it when any of them is on; each transform inside is feature-gated
// and uses libraries loaded by its own feature block (UrlParser / PostNumber).
if ( ! empty($simCentralFeatures['summary'])
	|| ! empty($simCentralFeatures['url_parser'])
	|| ! empty($simCentralFeatures['ordered_mission_posts'])) {
	require_once dirname(__FILE__).'/libraries/Email.php';
}

// ---------- Content Filter ----------
if ( ! empty($simCentralFeatures['content_filter'])) {
	require_once dirname(__FILE__).'/events/content_filter_db.php';
	require_once dirname(__FILE__).'/events/content_filter_location_admin_write_missionpost.php';
	require_once dirname(__FILE__).'/events/content_filter_location_main_sim_viewpost.php';
}

// ---------- Discord Sign-In ----------
// Jwt + DiscordAuth load unconditionally so the Manage controller's
// configure page works even before the feature has been turned on
// (admin needs to set the broker URL + paste / fetch the public key
// BEFORE flipping the toggle, otherwise the feature would activate
// with no working JWT verification).
require_once dirname(__FILE__).'/libraries/Jwt.php';
require_once dirname(__FILE__).'/libraries/DiscordAuth.php';
if ( ! empty($simCentralFeatures['discord_auth'])) {
	require_once dirname(__FILE__).'/events/discord_auth_db.php';
	require_once dirname(__FILE__).'/events/discord_auth_template_render.php';
	require_once dirname(__FILE__).'/events/discord_auth_location_login_index.php';
	require_once dirname(__FILE__).'/events/discord_auth_location_main_join_1.php';
	require_once dirname(__FILE__).'/events/discord_auth_location_admin_user_account.php';

	// Enforcement hooks run here (post_controller_constructor time,
	// before the action method) so redirects actually short-circuit
	// the rest of the request. Two related gates:
	//
	//   requiresLink (v1.4.0+)        - logged-in user has no Discord
	//                                    ID; bounce to forced-link page.
	//   shouldEnforceDiscordOnly      - logged-in user signed in via
	//   (v1.8.0+)                       email+password (no Discord
	//                                    marker in session) and isn't
	//                                    a sysadmin. Bounce too -
	//                                    same forced page, but its
	//                                    CTA adapts to whether they
	//                                    have Discord linked yet.
	//
	// loginDiscordOnly() implies requiresLink(), so the second hook
	// only adds the post-Nova-auth bounce; the no-Discord case is
	// already handled by the first hook.
	$ci =& get_instance();
	// isset() (not a bare $ci->session) - some controllers (e.g. Feed) don't
	// load the session library, and a plain property access warns under PHP 8.
	if (isset($ci->session) && $ci->session) {
		$uri = $ci->uri->uri_string();
		if (\nova_ext_sim_central\DiscordAuth::shouldEnforceLink($uri)) {
			$ci->session->set_flashdata('discord_auth_message',
				'This sim requires a linked Discord account before you can continue.');
			header('Location: '.\nova_ext_sim_central\DiscordAuth::requiredPageUrl(), true, 302);
			exit;
		}
		if (\nova_ext_sim_central\DiscordAuth::shouldEnforceDiscordOnly($uri)) {
			$ci->session->set_flashdata('discord_auth_message',
				'This sim requires Discord sign-in. Please use the Sign in with Discord button.');
			header('Location: '.\nova_ext_sim_central\DiscordAuth::requiredPageUrl(), true, 302);
			exit;
		}
		// Join gate (v1.23.0+): when linking is required on join, block the
		// join-form submit server-side if no Discord link is proven. Backs up
		// the client-side JS guard on the join form so the requirement can't
		// be bypassed. No Nova core edit - we short-circuit before join() runs.
		if (\nova_ext_sim_central\DiscordAuth::requiredJoinMissingLink($ci)) {
			$ci->session->set_flashdata('discord_auth_message',
				'This sim requires a linked Discord account to join. Click "Link Discord" on the join form first.');
			header('Location: '.site_url('main/join'), true, 302);
			exit;
		}
	}
}
// ---------- REST API ----------
// ApiAuth is consumed by the Api controller (loaded on-demand by Nova's
// router) and by the Manage controller's token-management page. Loading
// it here keeps the require off the per-request hot path for the
// controllers themselves.
if ( ! empty($simCentralFeatures['rest_api'])) {
	require_once dirname(__FILE__).'/libraries/ApiAuth.php';
	// ApiEndpoints is consumed by Api::openapi() and Manage::api_explorer().
	// Loading it alongside ApiAuth keeps the on-demand work in the same place.
	require_once dirname(__FILE__).'/libraries/ApiEndpoints.php';
}

// PostWrite backs both the REST API post endpoints and the mobile site's
// post create/update/delete, so load it whenever either feature is on.
if ( ! empty($simCentralFeatures['rest_api']) || ! empty($simCentralFeatures['mobile'])) {
	require_once dirname(__FILE__).'/libraries/PostWrite.php';
}

// ---------- Event Webhooks ----------
// Webhooks library is consumed by the Posts_model shim (which fires after
// successful create/update) and by the Manage::webhooks ACP page. Loading
// unconditionally when the feature is on means the shim's class_exists()
// guard resolves true and the dispatch fires.
if ( ! empty($simCentralFeatures['webhooks'])) {
	require_once dirname(__FILE__).'/libraries/Webhooks.php';
}

if ( ! empty($simCentralFeatures['summary'])) {
	require_once dirname(__FILE__).'/events/summary_db.php';
	require_once dirname(__FILE__).'/events/summary_location_admin_add_mission.php';
	require_once dirname(__FILE__).'/events/summary_location_admin_manage_posts_edit.php';
	require_once dirname(__FILE__).'/events/summary_location_admin_write_missionpost.php';
	require_once dirname(__FILE__).'/events/summary_location_main_sim_viewpost.php';
}

// ---------- URL Parser ----------
if ( ! empty($simCentralFeatures['url_parser'])) {
	require_once dirname(__FILE__).'/libraries/UrlParser.php';
	foreach (glob(dirname(__FILE__).'/events/url_parser_*.php') as $eventFile) {
		require_once $eventFile;
	}
}

// ---------- Ordered Mission Posts ----------
// Feed.php + Email.php are shared with the Summary / URL Parser features and
// loaded above. PostNumber / PostWordCount are exclusive to this feature.
if ( ! empty($simCentralFeatures['ordered_mission_posts'])) {
	require_once dirname(__FILE__).'/libraries/PostNumber.php';
	require_once dirname(__FILE__).'/libraries/PostWordCount.php';

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
