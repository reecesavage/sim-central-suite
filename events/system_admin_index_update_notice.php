<?php

// Injects a "Sim Central Suite update available" panel onto Nova's
// admin index page, sitting alongside Nova's own update notification.
//
// Triggers when:
//   - the viewer is a gamemaster (sysadmin or assistant GM),
//   - UpdateCheck has a cached latest_version that's newer than this
//     install's Config::version().
//
// Uses the same cache UpdateCheck populates on dashboard load, so this
// piggybacks on the existing 24h GitHub poll without making extra
// network calls on every admin/index hit. If the cache is empty (broker
// never reached, brand-new install), nothing renders.
//
// Always loaded (not feature-gated): the dashboard update banner
// already runs whenever the suite is on, so the admin-index notice
// is a natural complement that needs no extra opt-in.

$this->event->listen(['location', 'view', 'output', 'admin', 'admin_index'], function($event){
	$userId = $this->session->userdata('userid');
	if ( ! $userId || ! \Auth::is_gamemaster($userId)) {
		return;
	}

	$update  = \nova_ext_sim_central\UpdateCheck::latest();
	$current = \nova_ext_sim_central\Config::version();
	$latest  = isset($update['latest_version']) ? $update['latest_version'] : null;

	if (empty($latest) || ! \nova_ext_sim_central\UpdateCheck::isNewer($latest, $current)) {
		return;
	}

	$latestEsc      = htmlspecialchars($latest, ENT_QUOTES);
	$currentEsc     = htmlspecialchars($current, ENT_QUOTES);
	$releaseUrl     = ! empty($update['release_url'])
		? $update['release_url']
		: \nova_ext_sim_central\UpdateCheck::releasesUrl();
	$dashboardUrl   = site_url('extensions/nova_ext_sim_central/Manage/index');

	// Menu item. The id matches Nova's panel-switching JS expectation:
	// clicking #panelmenu a[id=X] reveals .panel div.X. Use a red
	// badge to draw the eye, same shape as Nova's update notice does
	// for critical / security severities.
	$navLi = '<li>'
		.'<a href="#" id="sim_central_update">'
		.'<span>'
		.'<div class="count ui-state-highlight"><div class="ui-icon ui-icon-arrowthick-1-n"></div></div>'
		.'Sim Central'
		.'</span>'
		.'</a>'
		.'</li>';

	// Panel content. Mirrors the structure of Nova's own .update div
	// so spacing and typography are consistent.
	$panel = '<div class="sim_central_update hidden">'
		.'<span class="bold fontMedium blue">Sim Central Suite v'.$latestEsc.'</span>'
		.'<p class="fontSmall gray">A new release of Sim Central Suite is available. '
		.'You are currently on v'.$currentEsc.'.</p>'
		.'<p class="fontSmall">'
		.'<a href="'.htmlspecialchars($dashboardUrl, ENT_QUOTES).'" class="button-main">Go to Sim Central dashboard</a>'
		.'</p>'
		.'<p class="fontSmall">'
		.'<a href="'.htmlspecialchars($releaseUrl, ENT_QUOTES).'" target="_blank" rel="noopener">View release notes &rarr;</a>'
		.'</p>'
		.'</div>';

	// Two injections, both via Generator (so they go in client-side
	// at the right DOM positions regardless of what skin is rendering).
	$event['output'] .= \nova_ext_sim_central\Generator::select('#panelmenu')->append($navLi);
	$event['output'] .= \nova_ext_sim_central\Generator::select('#acp-panel .panel')->append($panel);
});
