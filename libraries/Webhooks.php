<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Event webhooks - fire HTTP POST notifications when posts change state.
 *
 * Entry point is onPostChanged($postId, $newData, $previousStatus). The
 * Posts_model shim calls it after a successful create_mission_entry or
 * update_post. From there we:
 *
 *   1. Decide which event(s) to fire (post.saved / post.posted) based on
 *      the new + previous post_status.
 *   2. Look up every enabled webhook subscribed to that event.
 *   3. Build the per-format payload (Discord embed or generic JSON).
 *   4. Fire-and-forget POST with a short cURL timeout (2s). Log the result
 *      back to the webhook row (last_fired_at, last_status, last_error).
 *
 * Delivery is at-most-once. We don't retry, we don't queue, we don't block
 * the save - Discord webhooks are a best-effort notification channel, not a
 * transaction log. If a webhook is consistently broken the admin can see
 * "last_status: 0 (network error)" on the manage page and fix the URL.
 */
class Webhooks
{
	const EVENT_SAVED  = 'post.saved';
	const EVENT_POSTED = 'post.posted';

	const CURL_TIMEOUT_SEC = 2;
	const CURL_CONNECT_TIMEOUT_SEC = 2;

	const BODY_MAX_CHARS = 800; // Discord description sweet spot for readability.

	/** Discord-friendly embed colour palette. Mirrors the n8n recipe users were doing
	 *  externally so the in-suite output looks the same. */
	const COLORS = array(
		0x3498DB, 0x2ECC71, 0xE67E22, 0x9B59B6,
		0x1ABC9C, 0xE74C3C, 0xF39C12, 0x16A085,
		0x8E44AD, 0xD35400, 0x27AE60, 0x2980B9,
	);

	const DEFAULT_TITLE_TEMPLATE = '{sim_name} Post | {post_title}';
	const DEFAULT_DESCRIPTION_TEMPLATE = "*A mission post by {authors}*\n\n**Mission** - {mission}\n**Location** - {location}\n**Timeline** - {timeline}\n\n{body}\n\n[Read the full post]({url})";

	/**
	 * Called by the Posts_model shim after every successful save / update.
	 *
	 * @param int|string $postId
	 * @param array      $newData         The fields just written to the row.
	 * @param string|null $previousStatus The post_status that was there before
	 *                                    the write (null on insert).
	 */
	public static function onPostChanged($postId, $newData, $previousStatus)
	{
		$newStatus = isset($newData['post_status']) ? (string) $newData['post_status'] : '';
		if ($newStatus === '') {
			return;
		}

		$events = self::eventsFor($newStatus, $previousStatus);
		if (empty($events)) {
			return;
		}

		$post = self::loadPost($postId);
		if ( ! $post) {
			return;
		}

		foreach ($events as $eventName) {
			self::fireEvent($eventName, $post);
		}
	}

	/**
	 * Map a (new, previous) status pair to the event names that should fire.
	 *
	 *   New 'saved'                            -> post.saved      (new draft OR edit of draft)
	 *   New 'activated' AND prev != activated  -> post.posted     (transition to public)
	 *   New 'activated' AND prev == activated  -> nothing         (edit of already-posted post)
	 *   Anything else                          -> nothing
	 */
	private static function eventsFor($newStatus, $previousStatus)
	{
		$out = array();

		if ($newStatus === 'saved') {
			$out[] = self::EVENT_SAVED;
		} elseif ($newStatus === 'activated' && $previousStatus !== 'activated') {
			$out[] = self::EVENT_POSTED;
		}

		return $out;
	}

	/**
	 * Fetch every enabled webhook subscribed to this event, build the payload
	 * per format, dispatch. Failure on one webhook doesn't affect the others.
	 */
	private static function fireEvent($eventName, $post)
	{
		$ci =& get_instance();

		$prefix = $ci->db->dbprefix;
		if ( ! $ci->db->table_exists($prefix.'sim_central_webhooks')
			&& ! $ci->db->table_exists('sim_central_webhooks')) {
			return; // feature on but Setup database not yet run
		}

		$rows = $ci->db->where('enabled', 1)->get('sim_central_webhooks')->result();

		foreach ($rows as $row) {
			$events = json_decode($row->events, true);
			if ( ! is_array($events) || ! in_array($eventName, $events, true)) {
				continue;
			}

			$payload = self::buildPayload($row, $eventName, $post);
			if ($payload === null) {
				continue;
			}

			self::deliver($row, $payload);
		}
	}

	// ---------- payload builders ----------

	private static function buildPayload($webhook, $eventName, $post)
	{
		$format = strtolower((string) $webhook->format);
		switch ($format) {
			case 'discord':
				return self::buildDiscord($webhook, $eventName, $post);
			case 'generic':
				return self::buildGeneric($eventName, $post);
		}
		return null;
	}

	/**
	 * Discord webhook payload - one embed plus a content line for @mentions.
	 *
	 * Two shapes depending on event:
	 *
	 *   post.posted - the rich announcement (matches the layout users had been
	 *                 generating in n8n: title, byline, mission/location/timeline,
	 *                 body excerpt, link, random color). Templates are admin-
	 *                 customisable via webhook.template_title / template_description.
	 *
	 *   post.saved  - lightweight "your draft was updated" ping. Always tags
	 *                 the linked authors so every co-author sees there's a new
	 *                 revision to look at. Not templateable - the format is
	 *                 fixed because the content is fixed.
	 */
	private static function buildDiscord($webhook, $eventName, $post)
	{
		if ($eventName === self::EVENT_POSTED) {
			$titleTpl = trim((string) $webhook->template_title) !== ''
				? $webhook->template_title
				: self::DEFAULT_TITLE_TEMPLATE;
			$descTpl = trim((string) $webhook->template_description) !== ''
				? $webhook->template_description
				: self::DEFAULT_DESCRIPTION_TEMPLATE;

			$vars = self::templateVars($post, true /* include body */);
			$embed = array(
				'title'       => self::renderTemplate($titleTpl, $vars),
				'description' => self::renderTemplate($descTpl, $vars),
				'url'         => $post['url_public'],
				'color'       => self::COLORS[array_rand(self::COLORS)],
				'timestamp'   => date('c', (int) $post['post_date']),
			);

			// post.posted does NOT ping. It's a public announcement - the
			// byline shows plain "Rank Name" via {authors}; no @mentions in
			// content means no notification spam to the authors every time a
			// post they're on goes live. (Pinging is reserved for post.saved,
			// where the point IS to alert co-authors a draft changed.)
			return array(
				'content' => '',
				'embeds'  => array($embed),
			);
		}

		// post.saved - this one DOES ping. The mentions go in `content` so
		// every linked author actually gets a notification that the draft
		// they're on has a new revision to look at.
		$mentions    = self::authorMentions($post);
		$contentLine = empty($mentions) ? '' : implode(' ', $mentions);

		$vars  = self::templateVars($post, false);
		$title = 'A saved mission post has been updated';
		$desc  = "**Title** - {$vars['{post_title}']}\n"
			.   "**Mission** - {$vars['{mission}']}\n"
			.   "**Saved by** - {$vars['{actor}']}\n\n"
			.   "[View the post]({$vars['{url_admin}']})";

		return array(
			'content' => $contentLine,
			'embeds'  => array(array(
				'title'       => $title,
				'description' => $desc,
				'url'         => $post['url_admin'],
				'color'       => 0x95A5A6, // muted grey - this isn't a public announcement
				'timestamp'   => date('c'),
			)),
		);
	}

	/**
	 * Generic JSON payload - structured for n8n / scripts. Same shape for
	 * both events; the `event` key tells the consumer which one fired.
	 */
	private static function buildGeneric($eventName, $post)
	{
		return array(
			'event'  => $eventName,
			'fired_at' => date('c'),
			'post'   => array(
				'id'         => (int) $post['post_id'],
				'title'      => $post['post_title'],
				'content'    => $post['post_content'],
				'status'     => $post['post_status'],
				'mission_id' => (int) $post['post_mission'],
				'mission'    => $post['mission_title'],
				'location'   => $post['post_location'],
				'timeline'   => $post['timeline_formatted'],
				'date'       => date('c', (int) $post['post_date']),
				'url_public' => $post['url_public'],
				'url_admin'  => $post['url_admin'],
			),
			'authors' => $post['authors'],     // [{id, name, rank, discord_id, user_id}, ...]
			'actor'   => $post['actor'],        // {id, name, ...} of whoever triggered the save
			'sim'     => array(
				'name' => $post['sim_name'],
			),
		);
	}

	// ---------- post / author / actor loading ----------

	/**
	 * Load a post row plus all the satellite data the payload builders need.
	 * Returns a flat array. We do the joins ourselves rather than going
	 * through nova_posts_model because we want fields from missions and
	 * characters too, and we want to gracefully handle missing rows.
	 */
	private static function loadPost($postId)
	{
		$ci =& get_instance();

		$row = $ci->db->get_where('posts', array('post_id' => $postId))->row();
		if ( ! $row) {
			return null;
		}

		$mission = null;
		if ( ! empty($row->post_mission)) {
			$mission = $ci->db->get_where('missions', array('mission_id' => $row->post_mission))->row();
		}

		$siteUrl = rtrim(site_url('/'), '/');

		$post = array(
			'post_id'        => $row->post_id,
			'post_title'     => $row->post_title,
			'post_content'   => $row->post_content,
			'post_status'    => $row->post_status,
			'post_mission'   => $row->post_mission,
			'post_location'  => isset($row->post_location) ? $row->post_location : '',
			'post_date'      => $row->post_date,
			'mission_title'  => $mission ? $mission->mission_title : '',
			'authors'        => self::loadAuthors($row),
			'actor'          => self::loadActor($row),
			'timeline_formatted' => self::formatTimeline($row, $mission),
			'sim_name'       => self::simName(),
			'url_public'     => $siteUrl.'/sim/viewpost/'.$row->post_id,
			'url_admin'      => $siteUrl.'/write/missionpost/'.$row->post_id.'/view',
		);

		return $post;
	}

	/**
	 * Walk post.post_authors (CSV of charids), join to characters and users,
	 * return an enriched author list: [{id, first_name, last_name, suffix,
	 * rank_name, display_name, discord_id, user_id}, ...]
	 */
	private static function loadAuthors($row)
	{
		$ci =& get_instance();
		$result = array();

		if (empty($row->post_authors)) {
			return $result;
		}

		$ids = array_filter(array_map('intval', explode(',', (string) $row->post_authors)));
		if (empty($ids)) {
			return $result;
		}

		$ci->db->select('characters.charid AS id, characters.first_name, characters.last_name, characters.suffix, characters.user, characters.display_name, ranks.rank_name, users.nova_ext_discord_auth_id AS discord_id');
		$ci->db->from('characters');
		$ci->db->join('ranks', 'ranks.rank_id = characters.rank', 'left');
		$ci->db->join('users', 'users.userid = characters.user', 'left');
		$ci->db->where_in('characters.charid', $ids);
		$rows = $ci->db->get()->result();

		// Re-sort to match the original CSV order so authors render in the
		// order the writer chose.
		$byId = array();
		foreach ($rows as $r) {
			$byId[(int) $r->id] = $r;
		}
		foreach ($ids as $id) {
			if (isset($byId[$id])) {
				$result[] = self::authorArrayShape($byId[$id]);
			}
		}
		return $result;
	}

	private static function authorArrayShape($r)
	{
		$displayName = ! empty($r->display_name)
			? $r->display_name
			: trim(($r->first_name ?? '').' '.($r->last_name ?? '').(empty($r->suffix) ? '' : ' '.$r->suffix));

		return array(
			'id'         => (int) $r->id,
			'name'       => $displayName,
			'rank'       => $r->rank_name ?: null,
			'rank_name'  => $r->rank_name ?: null,
			'discord_id' => $r->discord_id ?: null,
			'user_id'    => $r->user ? (int) $r->user : null,
		);
	}

	/**
	 * "Who saved this." Best-effort - falls back to the post's first author
	 * if post_saved isn't set, since older saves didn't always populate it.
	 */
	private static function loadActor($row)
	{
		$ci =& get_instance();

		$actorCharId = ! empty($row->post_saved)
			? (int) $row->post_saved
			: (int) (explode(',', (string) $row->post_authors)[0] ?? 0);

		if ($actorCharId <= 0) {
			return array('id' => null, 'name' => '(unknown)', 'rank' => null, 'discord_id' => null, 'user_id' => null);
		}

		$ci->db->select('characters.charid AS id, characters.first_name, characters.last_name, characters.suffix, characters.user, characters.display_name, ranks.rank_name, users.nova_ext_discord_auth_id AS discord_id');
		$ci->db->from('characters');
		$ci->db->join('ranks', 'ranks.rank_id = characters.rank', 'left');
		$ci->db->join('users', 'users.userid = characters.user', 'left');
		$ci->db->where('characters.charid', $actorCharId);
		$r = $ci->db->get()->row();

		if ( ! $r) {
			return array('id' => $actorCharId, 'name' => '(unknown)', 'rank' => null, 'discord_id' => null, 'user_id' => null);
		}

		return self::authorArrayShape($r);
	}

	// ---------- template + formatting helpers ----------

	private static function templateVars($post, $includeBody)
	{
		// {authors} renders PLAIN "Rank Name" by default - that's what the
		// post.posted byline wants (a public announcement, no pings). Anyone
		// who specifically wants clickable mentions in a custom template can
		// use {authors_mentions}; note that mentions only *ping* if they're
		// also in the payload's `content` field, which post.posted leaves
		// empty - so {authors_mentions} renders clickable-but-silent.
		$authorsPlain    = self::renderAuthorsPlain($post['authors']);
		$authorsMentions = self::renderAuthorsWithMentions($post['authors']);

		$vars = array(
			'{sim_name}'         => $post['sim_name'],
			'{post_title}'       => $post['post_title'],
			'{post_type}'        => 'Mission Post',
			'{authors}'          => $authorsPlain,
			'{authors_plain}'    => $authorsPlain,
			'{authors_mentions}' => $authorsMentions,
			'{mission}'          => $post['mission_title'] !== '' ? $post['mission_title'] : '(no mission)',
			'{location}'         => $post['post_location'] !== '' ? $post['post_location'] : '(unspecified)',
			'{timeline}'         => $post['timeline_formatted'] !== '' ? $post['timeline_formatted'] : '(no timeline)',
			'{url}'              => $post['url_public'],
			'{url_admin}'        => $post['url_admin'],
			'{actor}'            => $post['actor']['name'],
		);

		if ($includeBody) {
			$markdown = self::htmlToMarkdown($post['post_content']);
			// Truncate only - the read-more link is owned by the template
			// (the default description ends with "[Read the full post]({url})").
			// Appending it here too produced a duplicate link.
			$vars['{body}'] = self::smartTruncate($markdown, self::BODY_MAX_CHARS);
		}

		return $vars;
	}

	private static function renderTemplate($tpl, $vars)
	{
		return strtr($tpl, $vars);
	}

	/**
	 * Author byline with Discord @mentions where the linked user has a
	 * stored discord_id, else plain "Rank First Last" text. Used in the
	 * embed *description* (where Discord renders <@id> as a clickable
	 * mention but doesn't ping unless the id is also in the `content`
	 * field of the webhook payload).
	 */
	private static function renderAuthorsWithMentions($authors)
	{
		if (empty($authors)) {
			return '(no authors)';
		}
		$parts = array();
		foreach ($authors as $a) {
			if ( ! empty($a['discord_id'])) {
				$parts[] = '<@'.$a['discord_id'].'>';
				continue;
			}
			$parts[] = self::renderAuthorPlain($a);
		}
		return self::joinHumanList($parts);
	}

	private static function renderAuthorsPlain($authors)
	{
		if (empty($authors)) {
			return '(no authors)';
		}
		$parts = array();
		foreach ($authors as $a) {
			$parts[] = self::renderAuthorPlain($a);
		}
		return self::joinHumanList($parts);
	}

	private static function renderAuthorPlain($a)
	{
		$bits = array();
		if ( ! empty($a['rank'])) { $bits[] = $a['rank']; }
		if ( ! empty($a['name'])) { $bits[] = $a['name']; }
		return implode(' ', $bits) ?: '(unknown)';
	}

	private static function joinHumanList($parts)
	{
		$n = count($parts);
		if ($n === 0) { return ''; }
		if ($n === 1) { return $parts[0]; }
		if ($n === 2) { return $parts[0].' & '.$parts[1]; }
		$last = array_pop($parts);
		return implode(', ', $parts).', & '.$last;
	}

	/**
	 * Discord mentions to include in `content` so the listed authors
	 * actually get a notification ping (mentions in description-only
	 * are clickable but don't ping). Returns an array of <@id> strings,
	 * deduped.
	 */
	private static function authorMentions($post)
	{
		$out = array();
		foreach ($post['authors'] as $a) {
			if ( ! empty($a['discord_id'])) {
				$out[(int) $a['discord_id']] = '<@'.$a['discord_id'].'>';
			}
		}
		return array_values($out);
	}

	/**
	 * Build the "Timeline" line. Pulls from the ordered_mission_posts feature
	 * columns when present (so admins see "Day 4 at 1900" etc. exactly as
	 * Nova renders it elsewhere). Falls back to the raw post_timeline if the
	 * ordered feature isn't installed.
	 */
	private static function formatTimeline($postRow, $missionRow)
	{
		$features = Config::features();
		if (empty($features['ordered_mission_posts']) || ! class_exists('nova_ext_sim_central\\TimelineFormat')) {
			return isset($postRow->post_timeline) ? (string) $postRow->post_timeline : '';
		}

		// We need the per-mission config setting to know which column to use
		// (day / date / stardate). Default to day-based.
		$config = ($missionRow && isset($missionRow->mission_ext_ordered_config_setting))
			? (string) $missionRow->mission_ext_ordered_config_setting
			: 'nova_ext_ordered_post_day';

		$dayColumn = $config ?: 'nova_ext_ordered_post_day';
		$dayValue = property_exists($postRow, $dayColumn) ? (string) $postRow->$dayColumn : '';
		$timeValue = property_exists($postRow, 'nova_ext_ordered_post_time') ? (string) $postRow->nova_ext_ordered_post_time : '';

		if ($dayValue === '' && $timeValue === '') {
			return isset($postRow->post_timeline) ? (string) $postRow->post_timeline : '';
		}

		// We don't load the per-mission label customisations here - that would
		// require loading the ordered_mission_posts config. Default "Day X at
		// HHMM" works for the vast majority of sims.
		$dayLabel = ($dayColumn === 'nova_ext_ordered_post_day') ? 'Day' : ucfirst(str_replace('nova_ext_ordered_post_', '', $dayColumn));
		$timeLabel = $timeValue !== '' ? ' at '.$timeValue : '';
		return trim($dayLabel.' '.$dayValue.$timeLabel);
	}

	private static function simName()
	{
		$ci =& get_instance();
		$row = $ci->db->get_where('settings', array('setting_key' => 'sim_name'))->row();
		return $row ? (string) $row->setting_value : '';
	}

	/**
	 * HTML -> Discord-flavoured Markdown. Port of the user's n8n recipe with
	 * a couple of additions (italic, lists collapsed to bullets). Discord
	 * doesn't support most HTML, so we strip everything we don't translate.
	 */
	public static function htmlToMarkdown($html)
	{
		$s = (string) $html;
		// Bold + italic
		$s = preg_replace('/<(b|strong)\b[^>]*>(.*?)<\/\1>/is', '**$2**', $s);
		$s = preg_replace('/<(i|em)\b[^>]*>(.*?)<\/\1>/is', '*$2*', $s);
		// Anchors -> "[text](href)"
		$s = preg_replace_callback('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function($m) {
			$text = trim(strip_tags($m[2]));
			return '['.$text.']('.$m[1].')';
		}, $s);
		// Block separators - paragraphs and double-br get blank lines
		$s = preg_replace('/<\/p>\s*<p\b[^>]*>/i', "\n\n", $s);
		$s = preg_replace('/<br\s*\/?>(\s*<br\s*\/?>)+/i', "\n\n", $s);
		$s = preg_replace('/<br\s*\/?>/i', "\n", $s);
		// Strip the rest
		$s = strip_tags($s);
		// Decode entities (&nbsp;, &amp; etc.)
		$s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		// Normalize whitespace
		$s = preg_replace('/\r\n/', "\n", $s);
		$s = preg_replace('/\n{3,}/', "\n\n", $s);
		return trim($s);
	}

	/**
	 * Truncate at a sensible boundary (paragraph -> sentence -> word),
	 * appending an ellipsis marker when the text was actually cut. Does NOT
	 * append a "Read the full post" link - that's owned by the template's
	 * {url} line, so adding it here too would duplicate it.
	 */
	public static function smartTruncate($text, $max)
	{
		if (mb_strlen($text) <= $max) {
			return $text;
		}
		$chunk = mb_substr($text, 0, $max);

		$paraBreak = mb_strrpos($chunk, "\n\n");
		if ($paraBreak !== false && $paraBreak > $max * 0.5) {
			return mb_substr($chunk, 0, $paraBreak)."\n\n...";
		}

		if (preg_match('/^([\s\S]*[.!?])\s/u', $chunk, $m) && mb_strlen($m[1]) > $max * 0.5) {
			return $m[1]."\n\n...";
		}

		$wordBreak = mb_strrpos($chunk, ' ');
		if ($wordBreak === false) {
			return $chunk."...";
		}
		return mb_substr($chunk, 0, $wordBreak)."\n\n...";
	}

	// ---------- delivery ----------

	/**
	 * Fire-and-forget POST. Records the result (HTTP status or 0 for network
	 * error) on the webhook row. Never throws - delivery is best-effort.
	 */
	private static function deliver($webhook, $payload)
	{
		$body = json_encode($payload);
		if ($body === false) {
			self::recordResult($webhook->id, 0, 'json_encode failed');
			return;
		}

		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL            => $webhook->url,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'User-Agent: SimCentralSuite-Webhook/1.0',
			),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::CURL_TIMEOUT_SEC,
			CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT_SEC,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		));

		$responseBody = curl_exec($ch);
		$status       = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err          = curl_error($ch);
		curl_close($ch);

		if ($status === 0 && $err !== '') {
			self::recordResult($webhook->id, 0, substr($err, 0, 500));
			return;
		}
		// Anything outside 200-299 we count as a failure for visibility, but
		// don't retry - the admin can re-fire from the manage page.
		if ($status < 200 || $status >= 300) {
			self::recordResult($webhook->id, $status, substr((string) $responseBody, 0, 500));
			return;
		}

		self::recordResult($webhook->id, $status, null);
	}

	private static function recordResult($webhookId, $status, $error)
	{
		$ci =& get_instance();
		$ci->db->where('id', $webhookId)->update('sim_central_webhooks', array(
			'last_fired_at' => date('Y-m-d H:i:s'),
			'last_status'   => $status,
			'last_error'    => $error,
		));
	}

	/**
	 * Admin "Test" button entry point. Fires a synthetic event with realistic
	 * dummy data against a single webhook id, returns the result tuple
	 * (status, error) for the ACP flash.
	 */
	public static function testWebhook($webhookId, $eventName = self::EVENT_POSTED)
	{
		$ci =& get_instance();
		$webhook = $ci->db->get_where('sim_central_webhooks', array('id' => $webhookId))->row();
		if ( ! $webhook) {
			return array('error', 'Webhook not found.');
		}

		$post = self::stubPost();
		$payload = self::buildPayload($webhook, $eventName, $post);
		if ($payload === null) {
			return array('error', 'Unknown webhook format: '.$webhook->format);
		}

		self::deliver($webhook, $payload);

		// Re-read the row for the freshly-updated status fields.
		$webhook = $ci->db->get_where('sim_central_webhooks', array('id' => $webhookId))->row();
		if ($webhook->last_status >= 200 && $webhook->last_status < 300) {
			return array('success', 'Test delivered. HTTP '.$webhook->last_status.'.');
		}
		if ($webhook->last_status === 0) {
			return array('error', 'Network error: '.$webhook->last_error);
		}
		return array('error', 'HTTP '.$webhook->last_status.': '.$webhook->last_error);
	}

	private static function stubPost()
	{
		return array(
			'post_id'        => 0,
			'post_title'     => 'Test Post',
			'post_content'   => '<p>This is a <b>test</b> webhook delivery from the Sim Central Suite.</p><p>If you can see this in your channel, the webhook is wired up correctly.</p>',
			'post_status'    => 'activated',
			'post_mission'   => 0,
			'post_location'  => 'Test Location',
			'post_date'      => time(),
			'mission_title'  => 'Test Mission',
			'authors'        => array(),
			'actor'          => array('id' => null, 'name' => 'Webhook Test', 'rank' => null, 'discord_id' => null, 'user_id' => null),
			'timeline_formatted' => 'Day 1 at 1200',
			'sim_name'       => self::simName(),
			'url_public'     => rtrim(site_url('/'), '/'),
			'url_admin'      => rtrim(site_url('/'), '/'),
		);
	}
}
