<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * In-place updater for the suite. Pulls a GitHub release zipball,
 * extracts it, atomically swaps the extension dir, invalidates opcache,
 * and keeps a timestamped backup of the previous version.
 *
 *   preflight()           - environment checks (cURL, ZipArchive, FS writable, no stale lock)
 *   update($version)      - the full pipeline; returns array($status, $message, $context)
 *
 * Safety model
 * ============
 * - Staging happens in a sibling dir of the extension (same filesystem) so
 *   the final two `rename()`s are atomic.
 * - The two-step swap (current -> backup, new -> current) means a failure
 *   in the second step is rolled back by renaming the backup back into
 *   place. The extension is either fully old or fully new at every point.
 * - A `.sim_central_updating.lock` file in the parent dir prevents a
 *   second concurrent update from starting. Stale locks (>5 min) are
 *   considered abandoned and overwritten.
 * - Recursive deletes are scoped to either sys_get_temp_dir() or paths
 *   that contain our well-known staging/backup prefixes - we will never
 *   walk an arbitrary path.
 * - The new tree is validated (`init.php` + `config.json` present, version
 *   field matches the requested version) BEFORE the swap. A bad download
 *   never reaches the live dir.
 *
 * What's preserved
 * ================
 * - The DB `settings` row holding user state - untouched (we never query it).
 * - The previous version, kept in `<extension_dir>.backup-YYYYMMDD-HHMMSS/`
 *   indefinitely. Users can manually delete it when satisfied.
 *
 * What's NOT preserved
 * ====================
 * - Any file you placed inside the extension dir that isn't in the new
 *   release - it's gone after the swap, same as `git pull -f`.
 */
class Updater
{
	const LOCK_FILENAME    = '.sim_central_updating.lock';
	const LOCK_TTL_SECONDS = 300;
	const STAGE_PREFIX     = '.sim_central_update_';
	const BACKUP_PREFIX    = 'nova_ext_sim_central.backup-';
	const ZIPBALL_BASE     = 'https://api.github.com/repos/reecesavage/sim-central-suite/zipball/';
	const DOWNLOAD_TIMEOUT = 60;
	const CONNECT_TIMEOUT  = 5;

	/** Verify the environment can run an update. Returns NULL on success or an error string. */
	public static function preflight()
	{
		if ( ! function_exists('curl_init')) {
			return 'PHP cURL extension is not loaded. Update manually instead.';
		}
		if ( ! class_exists('ZipArchive')) {
			return 'PHP ZipArchive extension is not loaded. Update manually instead.';
		}

		$extDir    = self::extensionDir();
		$parentDir = dirname($extDir);

		if ( ! is_dir($extDir)) {
			return 'Extension directory not found: '.$extDir;
		}
		if ( ! is_writable($extDir)) {
			return 'Extension directory is not writable by PHP. The web user needs write access to '.$extDir;
		}
		if ( ! is_writable($parentDir)) {
			return 'Extension parent directory is not writable by PHP. The web user needs write access to '.$parentDir;
		}

		$lockPath = $parentDir.'/'.self::LOCK_FILENAME;
		if (file_exists($lockPath)) {
			$age = time() - (int) @filemtime($lockPath);
			if ($age < self::LOCK_TTL_SECONDS) {
				return 'Another update is already in progress (lock '.basename($lockPath).' is '.$age.'s old). Wait or delete the lock file to retry.';
			}
			// Stale; we'll overwrite it on lock acquisition below.
		}

		return null;
	}

	/**
	 * Run the full update pipeline.
	 *
	 * @return array array($status, $message, $context)
	 *               $status = 'success' | 'error'
	 *               $context = array('version' => ..., 'backup' => ...) on success
	 */
	public static function update($version)
	{
		$version = ltrim((string) $version, 'vV');
		if ($version === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $version)) {
			return array('error', 'Invalid version string.', array());
		}

		$err = self::preflight();
		if ($err !== null) {
			return array('error', $err, array());
		}

		$extDir    = self::extensionDir();
		$parentDir = dirname($extDir);
		$lockPath  = $parentDir.'/'.self::LOCK_FILENAME;

		if (@file_put_contents($lockPath, (string) time()) === false) {
			return array('error', 'Could not create lock file at '.$lockPath, array());
		}

		// Best-effort cleanup of intermediate artifacts on every exit path.
		$stageRoot = $parentDir.'/'.self::STAGE_PREFIX.uniqid();
		$zipPath   = $stageRoot.'.zip';
		$cleanup   = function() use ($stageRoot, $zipPath, $lockPath) {
			if (file_exists($zipPath))     @unlink($zipPath);
			if (is_dir($stageRoot))        self::recursiveDelete($stageRoot, self::STAGE_PREFIX);
			if (file_exists($lockPath))    @unlink($lockPath);
		};

		// Bump timeouts a bit; default 30s may be tight for unzip + opcache work.
		@set_time_limit(120);

		// ---------- download ----------
		$downloadErr = self::download(self::ZIPBALL_BASE.'v'.$version, $zipPath);
		if ($downloadErr !== null) {
			$cleanup();
			return array('error', 'Download failed: '.$downloadErr, array());
		}

		// ---------- extract ----------
		if ( ! @mkdir($stageRoot, 0755, true)) {
			$cleanup();
			return array('error', 'Could not create staging directory '.$stageRoot, array());
		}
		$extractErr = self::extract($zipPath, $stageRoot);
		if ($extractErr !== null) {
			$cleanup();
			return array('error', 'Extraction failed: '.$extractErr, array());
		}

		// ---------- locate the real root inside the GitHub wrapper dir ----------
		$newRoot = self::findExtensionRoot($stageRoot);
		if ($newRoot === null) {
			$cleanup();
			return array('error', 'Archive does not look like the sim-central-suite source (missing init.php / config.json).', array());
		}

		// ---------- validate version in the new tree ----------
		$installedVersion = self::readVersionFrom($newRoot.'/config.json');
		if ($installedVersion === null) {
			$cleanup();
			return array('error', 'Downloaded archive has no readable version field.', array());
		}
		if ($installedVersion !== $version) {
			$cleanup();
			return array('error', 'Archive version mismatch: requested v'.$version.', archive declares v'.$installedVersion.'.', array());
		}

		// ---------- swap ----------
		$backupDir = $parentDir.'/'.self::BACKUP_PREFIX.date('Ymd-His');
		if ( ! @rename($extDir, $backupDir)) {
			$cleanup();
			return array('error', 'Failed to back up current installation (rename to '.basename($backupDir).' failed).', array());
		}
		if ( ! @rename($newRoot, $extDir)) {
			// Rollback - the live dir is currently missing.
			if ( ! @rename($backupDir, $extDir)) {
				$cleanup();
				return array('error', 'CRITICAL: swap failed and rollback also failed. Extension is missing. Restore from '.basename($backupDir).' manually.', array());
			}
			$cleanup();
			return array('error', 'Failed to move the new files into place. Original version restored.', array());
		}

		// ---------- opcache ----------
		self::recursiveInvalidateOpcache($extDir);

		// ---------- finish ----------
		$cleanup();
		return array(
			'success',
			'Updated to v'.$version.'. Previous version saved to '.basename($backupDir).' (delete it manually when ready).',
			array('version' => $version, 'backup' => basename($backupDir)),
		);
	}

	// ---------- internals ----------

	private static function extensionDir()
	{
		return rtrim(APPPATH.'extensions/nova_ext_sim_central', '/\\');
	}

	private static function download($url, $dest)
	{
		$fh = @fopen($dest, 'wb');
		if ($fh === false) {
			return 'cannot open '.$dest.' for writing';
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_FILE           => $fh,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT        => self::DOWNLOAD_TIMEOUT,
			CURLOPT_USERAGENT      => 'sim-central-suite/'.Config::version(),
			CURLOPT_HTTPHEADER     => array('Accept: application/vnd.github+json'),
		));
		$ok        = curl_exec($ch);
		$httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);
		fclose($fh);

		if ($ok === false) {
			@unlink($dest);
			return 'cURL: '.$curlError;
		}
		if ($httpCode !== 200) {
			@unlink($dest);
			return 'GitHub returned HTTP '.$httpCode.' (release tag may not exist)';
		}
		if (filesize($dest) < 100) {
			@unlink($dest);
			return 'downloaded archive is suspiciously small ('.filesize($dest).' bytes)';
		}
		return null;
	}

	private static function extract($zipPath, $destDir)
	{
		$zip = new \ZipArchive();
		$res = $zip->open($zipPath);
		if ($res !== true) {
			return 'ZipArchive open returned '.(int) $res;
		}
		if ( ! $zip->extractTo($destDir)) {
			$zip->close();
			return 'extractTo failed';
		}
		$zip->close();
		return null;
	}

	/**
	 * The GitHub zipball wraps everything in a `<owner>-<repo>-<sha>/`
	 * dir. Find it by looking for the directory that contains init.php
	 * and config.json. Returns the absolute path or NULL.
	 */
	private static function findExtensionRoot($stageRoot)
	{
		// Top level should have exactly one wrapper dir; sometimes the
		// expected files are directly there. Check both.
		$candidates = array($stageRoot);
		foreach (scandir($stageRoot) as $entry) {
			if ($entry === '.' || $entry === '..') continue;
			$path = $stageRoot.'/'.$entry;
			if (is_dir($path)) {
				$candidates[] = $path;
			}
		}
		foreach ($candidates as $candidate) {
			if (is_file($candidate.'/init.php') && is_file($candidate.'/config.json')) {
				return $candidate;
			}
		}
		return null;
	}

	private static function readVersionFrom($configPath)
	{
		if ( ! is_file($configPath)) {
			return null;
		}
		$json = json_decode(@file_get_contents($configPath), true);
		if ( ! is_array($json) || empty($json['version'])) {
			return null;
		}
		return (string) $json['version'];
	}

	/**
	 * Recursively invalidate the PHP opcache for every .php file in $dir.
	 * Without this, the freshly-swapped files would not be picked up by
	 * the next request until opcache's `revalidate_freq` window elapsed.
	 */
	private static function recursiveInvalidateOpcache($dir)
	{
		if ( ! function_exists('opcache_invalidate') || ! is_dir($dir)) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($it as $file) {
			if ($file->isFile() && substr($file->getPathname(), -4) === '.php') {
				@opcache_invalidate($file->getPathname(), true);
			}
		}
	}

	/**
	 * Recursively delete a directory. Refuses unless $path resolves
	 * under a known prefix - we never walk an arbitrary user-supplied
	 * path. Use this only for staging artifacts (NEVER for backups or
	 * the live extension).
	 */
	private static function recursiveDelete($path, $requiredPrefix)
	{
		if ( ! is_dir($path)) {
			return false;
		}
		$real = realpath($path);
		if ($real === false || strpos(basename($real), $requiredPrefix) !== 0) {
			// Bail - the path doesn't look like one of ours.
			return false;
		}

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($it as $entry) {
			if ($entry->isDir()) {
				@rmdir($entry->getPathname());
			} else {
				@unlink($entry->getPathname());
			}
		}
		@rmdir($real);
		return true;
	}
}
