<?php

// Surface "Sim Central Suite update available" on Nova's admin home,
// folded into the existing Notifications panel rather than as its own
// menu item. Two event hooks make this work:
//
//   1. data event   - bumps $notifycount by 1 so:
//                       a) Nova renders the notifications <table> at
//                          all (the view skips the whole table when
//                          $notifycount == 0).
//                       b) The Notifications nav badge shows the
//                          incremented count.
//   2. output event - prepends a <tr> with our update notice into the
//                     rendered notifications <tbody>.
//
// Same conditions both hooks: viewer must be a gamemaster, and the
// 24h-cached UpdateCheck result must have a latest_version newer than
// what's installed. Reuses the cache the dashboard populates - no
// extra GitHub traffic per admin/index hit.

if ( ! function_exists('_sim_central_admin_index_update_pending')) {
	function _sim_central_admin_index_update_pending()
	{
		$ci =& get_instance();
		if ( ! $ci->session) {
			return null;
		}
		$userid = (int) $ci->session->userdata('userid');
		if ($userid <= 0 || ! \Auth::is_gamemaster($userid)) {
			return null;
		}

		$update  = \nova_ext_sim_central\UpdateCheck::latest();
		$latest  = isset($update['latest_version']) ? $update['latest_version'] : null;
		$current = \nova_ext_sim_central\Config::version();

		if (empty($latest) || ! \nova_ext_sim_central\UpdateCheck::isNewer($latest, $current)) {
			return null;
		}
		return array(
			'latest'      => $latest,
			'current'     => $current,
			'release_url' => ! empty($update['release_url'])
				? $update['release_url']
				: \nova_ext_sim_central\UpdateCheck::releasesUrl(),
		);
	}
}

// ---------- data: bump notifycount so the table renders + the nav badge ticks ----------

$this->event->listen(['location', 'view', 'data', 'admin', 'admin_index'], function($event){
	if (_sim_central_admin_index_update_pending() === null) {
		return;
	}
	$current = isset($event['data']['notifycount']) ? (int) $event['data']['notifycount'] : 0;
	$event['data']['notifycount'] = $current + 1;
});

// ---------- output: prepend a row to the rendered notifications table ----------

$this->event->listen(['location', 'view', 'output', 'admin', 'admin_index'], function($event){
	$pending = _sim_central_admin_index_update_pending();
	if ($pending === null) {
		return;
	}

	$latest       = htmlspecialchars($pending['latest'], ENT_QUOTES);
	$releaseUrl   = htmlspecialchars($pending['release_url'], ENT_QUOTES);
	$dashboardUrl = htmlspecialchars(site_url('extensions/nova_ext_sim_central/Manage/index'), ENT_QUOTES);

	$row = '<tr>'
		.'<td class="col1">1</td>'
		.'<td class="cell-spacer"></td>'
		.'<td class="col2">'
		.'<a href="'.$dashboardUrl.'">Sim Central Suite v'.$latest.' available</a> '
		.'<span class="fontSmall gray">'
		.'&middot; <a href="'.$releaseUrl.'" target="_blank" rel="noopener">release notes</a>'
		.'</span>'
		.'</td>'
		.'</tr>';

	$event['output'] .= \nova_ext_sim_central\Generator::select('.notifications table tbody')->prepend($row);
});
