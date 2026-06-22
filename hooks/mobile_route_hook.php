<?php

defined('BASEPATH') OR exit('No direct script access allowed');

if ( ! function_exists('sim_central_mobile_route')) {
	/**
	 * pre_system hook: serve the Sim Central Suite mobile site at a clean
	 * "/mobile" URL.
	 *
	 * Nova's extension dispatcher keys off a URL that literally begins with
	 * "extensions/..." and reads the controller/method from the raw URI
	 * segments, so a normal CodeIgniter route can't alias /mobile. Instead we
	 * rewrite the request URI here, before CI parses it, so a request to
	 * "/mobile[/...]" is dispatched to the Mobile extension controller exactly
	 * as if "/extensions/nova_ext_sim_central/Mobile[/...]" had been requested.
	 *
	 * Matches a lowercase "mobile" path segment only (the documented pretty
	 * URL), so it never touches the capital-M extension path or any other
	 * Nova route. The query string is preserved. uri_protocol is REQUEST_URI
	 * on Nova, with a best-effort PATH_INFO fallback.
	 */
	function sim_central_mobile_route()
	{
		if (empty($_SERVER['REQUEST_URI'])) {
			return;
		}

		$target = 'extensions/nova_ext_sim_central/Mobile';

		$parts = explode('?', $_SERVER['REQUEST_URI'], 2);
		$path  = $parts[0];
		$query = isset($parts[1]) ? '?'.$parts[1] : '';

		// App base dir, so subdirectory installs work (dirname of index.php).
		$base = '';
		if ( ! empty($_SERVER['SCRIPT_NAME'])) {
			$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
		}

		// Path relative to the app root (strip the base and any /index.php).
		$rel = $path;
		if ($base !== '' && strpos($rel, $base) === 0) {
			$rel = substr($rel, strlen($base));
		}
		$rel = preg_replace('#^/index\.php#', '', $rel);
		$rel = '/'.ltrim($rel, '/');

		// ONLY the app-root "mobile" segment - never /Manage/mobile, the
		// capital-M extension path, or any other path that contains "mobile".
		if (preg_match('#^/mobile(/.*)?$#', $rel, $m)) {
			$rest = isset($m[1]) ? $m[1] : '';
			// Nova's extension dispatch needs an explicit method segment (it
			// doesn't default to index), so bare /mobile -> Mobile/index.
			if ($rest === '' || $rest === '/') {
				$rest = '/index';
			}
			$_SERVER['REQUEST_URI'] = $base.'/'.$target.$rest.$query;
			if (isset($_SERVER['PATH_INFO'])) {
				$_SERVER['PATH_INFO'] = '/'.$target.$rest;
			}
		}
	}
}
