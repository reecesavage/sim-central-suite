<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Event webhooks - fire HTTP POST notifications when content changes state.
 *
 * Three content types, each with model-shim entry points:
 *
 *   Mission posts -> onPostChanged()  -> post.saved / post.posted
 *   Personal logs -> onLogChanged()   -> log.posted
 *   News items    -> onNewsChanged()  -> news.posted
 *
 * Each entry point normalises its row into a flat "item" array (see
 * loadPost / loadLog / loadNews) tagged with a 'type', then hands it to
 * dispatch(). From there the path is uniform: find enabled webhooks
 * subscribed to the event, build the per-format payload (Discord embed or
 * generic JSON), fire-and-forget POST with a short cURL timeout, log the
 * result on the row.
 *
 * Only post.* has a saved event; logs and news fire on activation only
 * (a transition to status 'activated', not every edit of an already-live
 * item). news.posted additionally honours each webhook's public/private
 * type filter.
 *
 * Delivery is at-most-once: no retries, no queue, never blocks the save.
 */
class Webhooks
{
	const EVENT_POST_SAVED  = 'post.saved';
	const EVENT_POST_POSTED = 'post.posted';
	const EVENT_LOG_POSTED  = 'log.posted';
	const EVENT_NEWS_POSTED = 'news.posted';

	const CURL_TIMEOUT_SEC = 2;
	const CURL_CONNECT_TIMEOUT_SEC = 2;

	const BODY_MAX_CHARS = 800;

	const COLORS = array(
		0x3498DB, 0x2ECC71, 0xE67E22, 0x9B59B6,
		0x1ABC9C, 0xE74C3C, 0xF39C12, 0x16A085,
		0x8E44AD, 0xD35400, 0x27AE60, 0x2980B9,
	);

	const DEFAULT_TITLE_TEMPLATE = '{sim_name} Post | {post_title}';
	const DEFAULT_DESCRIPTION_TEMPLATE = "*A mission post by {authors}*\n\n**Mission** - {mission}\n**Location** - {location}\n**Timeline** - {timeline}\n\n{body}\n\n[Read the full post]({url})";

	const DEFAULT_LOG_TITLE_TEMPLATE = '{sim_name} Log | {title}';
	const DEFAULT_LOG_DESCRIPTION_TEMPLATE = "*A personal log by {authors}*\n\n{body}\n\n[Read the full log]({url})";

	const DEFAULT_NEWS_TITLE_TEMPLATE = '{sim_name} News | {title}';
	const DEFAULT_NEWS_DESCRIPTION_TEMPLATE = "*{meta}*\n\n{body}\n\n[Read the full item]({url})";

	// ---------- config registries + validation (shared by ACP + REST API) ----------

	/**
	 * Canonical event registry. Single source of truth for both the ACP
	 * webhook form (Manage) and the REST API webhook endpoints (Api), so the
	 * two never drift on which events exist or what they mean.
	 */
	public static function availableEvents()
	{
		return array(
			self::EVENT_POST_SAVED  => 'Fires when a draft mission post is created or saved (status = saved). For nudging co-authors that a draft needs another look. Discord pings the authors.',
			self::EVENT_POST_POSTED => 'Fires when a mission post transitions to activated (i.e. publicly posted). The main announcement event.',
			self::EVENT_LOG_POSTED  => 'Fires when a personal log is activated. Author, title, content.',
			self::EVENT_NEWS_POSTED => 'Fires when a news item is activated. Author, title, category, type, content. Honours the public/private filter.',
		);
	}

	public static function availableFormats()
	{
		return array(
			'discord' => 'Discord webhook URL. Fires a formatted embed (with @mentions of authors who have linked Discord).',
			'generic' => 'Generic JSON. Sends a structured payload suitable for n8n, custom scripts, or any tool that consumes raw JSON.',
		);
	}

	public static function availableNewsTypes()
	{
		return array(
			'public'  => 'Public news items only (default)',
			'private' => 'Private news items only',
			'both'    => 'Both public and private',
		);
	}

	/**
	 * Validate and normalise a complete set of webhook fields into a DB-ready
	 * data array. Used by both the ACP save path and the REST API create/update
	 * path so validation rules live in exactly one place.
	 *
	 * $in is a flat associative array with these keys (all optional in the
	 * array sense; missing keys are treated as empty/defaults):
	 *   label, url, format, events[], enabled, news_types, mention_role_id,
	 *   mention_role_events[], template_title, template_description,
	 *   template_log_title, template_log_description,
	 *   template_news_title, template_news_description
	 *
	 * For partial updates, callers should merge the existing row's values into
	 * $in first, then pass the merged set here (validation always sees a
	 * complete record).
	 *
	 * @return array { errors: string[], data: array|null }
	 *         When errors is non-empty, data is null. Otherwise data is ready
	 *         for $db->insert/update (no created_at/created_by - caller adds).
	 */
	public static function validateWebhookInput(array $in)
	{
		$errors = array();

		$label  = isset($in['label'])  ? trim((string) $in['label'])  : '';
		$url    = isset($in['url'])    ? trim((string) $in['url'])     : '';
		$format = isset($in['format']) ? (string) $in['format']        : '';
		$events = isset($in['events']) && is_array($in['events']) ? $in['events'] : array();
		$enabled = ! empty($in['enabled']) ? 1 : 0;

		$tplTitle     = isset($in['template_title']) ? trim((string) $in['template_title']) : '';
		$tplDesc      = isset($in['template_description']) ? trim((string) $in['template_description']) : '';
		$tplLogTitle  = isset($in['template_log_title']) ? trim((string) $in['template_log_title']) : '';
		$tplLogDesc   = isset($in['template_log_description']) ? trim((string) $in['template_log_description']) : '';
		$tplNewsTitle = isset($in['template_news_title']) ? trim((string) $in['template_news_title']) : '';
		$tplNewsDesc  = isset($in['template_news_description']) ? trim((string) $in['template_news_description']) : '';

		$roleId = isset($in['mention_role_id']) ? trim((string) $in['mention_role_id']) : '';
		if ($roleId !== '' && ! ctype_digit($roleId)) {
			$errors[] = 'mention_role_id must be a numeric Discord role ID (or omitted).';
		}
		$roleEventsRaw = isset($in['mention_role_events']) && is_array($in['mention_role_events'])
			? $in['mention_role_events'] : array();

		$newsTypes = isset($in['news_types']) ? (string) $in['news_types'] : 'public';
		if ( ! isset(self::availableNewsTypes()[$newsTypes])) {
			$newsTypes = 'public';
		}

		if ($label === '') { $errors[] = 'label is required.'; }
		if (strlen($label) > 120) { $errors[] = 'label is too long (max 120 chars).'; }
		if ( ! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
			$errors[] = 'url must be a valid http(s) URL.';
		}
		if ( ! isset(self::availableFormats()[$format])) {
			$errors[] = 'format must be one of: '.implode(', ', array_keys(self::availableFormats())).'.';
		}

		$availableEvents = self::availableEvents();
		$cleanEvents = array();
		foreach ($events as $ev) {
			if (isset($availableEvents[$ev])) { $cleanEvents[] = $ev; }
		}
		if (empty($cleanEvents)) {
			$errors[] = 'events must contain at least one of: '.implode(', ', array_keys($availableEvents)).'.';
		}

		// Role-ping events: keep only events the webhook actually subscribes to.
		$cleanRoleEvents = array();
		foreach ($roleEventsRaw as $ev) {
			if (in_array($ev, $cleanEvents, true)) { $cleanRoleEvents[] = $ev; }
		}
		// A role can only ping if one is configured.
		if ($roleId === '') { $cleanRoleEvents = array(); }

		if ( ! empty($errors)) {
			return array('errors' => $errors, 'data' => null);
		}

		$data = array(
			'label'                => $label,
			'url'                  => $url,
			'format'               => $format,
			'events'               => json_encode(array_values($cleanEvents)),
			'enabled'              => $enabled,
			'news_types'           => $newsTypes,
			'mention_role_id'      => $roleId !== '' ? $roleId : null,
			'mention_role_events'  => ! empty($cleanRoleEvents) ? json_encode(array_values($cleanRoleEvents)) : null,
			'template_title'       => $tplTitle !== '' ? $tplTitle : null,
			'template_description' => $tplDesc  !== '' ? $tplDesc  : null,
			'template_log_title'        => $tplLogTitle  !== '' ? $tplLogTitle  : null,
			'template_log_description'  => $tplLogDesc   !== '' ? $tplLogDesc   : null,
			'template_news_title'       => $tplNewsTitle !== '' ? $tplNewsTitle : null,
			'template_news_description' => $tplNewsDesc  !== '' ? $tplNewsDesc  : null,
		);

		return array('errors' => array(), 'data' => $data);
	}

	// ---------- entry points (called by the model shims) ----------

	public static function onPostChanged($postId, $newData, $previousStatus)
	{
		$newStatus = isset($newData['post_status']) ? (string) $newData['post_status'] : '';
		if ($newStatus === '') {
			return;
		}

		$events = array();
		if ($newStatus === 'saved') {
			$events[] = self::EVENT_POST_SAVED;
		} elseif ($newStatus === 'activated' && $previousStatus !== 'activated') {
			$events[] = self::EVENT_POST_POSTED;
		}
		if (empty($events)) {
			return;
		}

		$item = self::loadPost($postId);
		if ( ! $item) {
			return;
		}
		foreach ($events as $eventName) {
			self::dispatch($eventName, $item);
		}
	}

	public static function onLogChanged($logId, $newData, $previousStatus)
	{
		$newStatus = isset($newData['log_status']) ? (string) $newData['log_status'] : '';
		// Logs fire on activation only (no saved event).
		if ($newStatus !== 'activated' || $previousStatus === 'activated') {
			return;
		}
		$item = self::loadLog($logId);
		if ( ! $item) {
			return;
		}
		self::dispatch(self::EVENT_LOG_POSTED, $item);
	}

	public static function onNewsChanged($newsId, $newData, $previousStatus)
	{
		$newStatus = isset($newData['news_status']) ? (string) $newData['news_status'] : '';
		if ($newStatus !== 'activated' || $previousStatus === 'activated') {
			return;
		}
		$item = self::loadNews($newsId);
		if ( ! $item) {
			return;
		}
		self::dispatch(self::EVENT_NEWS_POSTED, $item);
	}

	// ---------- dispatch ----------

	private static function dispatch($eventName, $item)
	{
		$ci =& get_instance();

		$prefix = $ci->db->dbprefix;
		if ( ! $ci->db->table_exists($prefix.'sim_central_webhooks')
			&& ! $ci->db->table_exists('sim_central_webhooks')) {
			return;
		}

		$rows = $ci->db->where('enabled', 1)->get('sim_central_webhooks')->result();

		foreach ($rows as $row) {
			$events = json_decode($row->events, true);
			if ( ! is_array($events) || ! in_array($eventName, $events, true)) {
				continue;
			}

			// News public/private filter. The webhook stores 'public',
			// 'private', or 'both' (default 'public'). Skip items that don't
			// match.
			if ($eventName === self::EVENT_NEWS_POSTED) {
				$want = isset($row->news_types) && $row->news_types !== '' ? $row->news_types : 'public';
				$isPrivate = ! empty($item['is_private']);
				if ($want === 'public' && $isPrivate)   { continue; }
				if ($want === 'private' && ! $isPrivate) { continue; }
				// 'both' falls through.
			}

			$payload = self::buildPayload($row, $eventName, $item);
			if ($payload === null) {
				continue;
			}
			self::deliver($row, $payload);
		}
	}

	// ---------- payload builders ----------

	private static function buildPayload($webhook, $eventName, $item)
	{
		switch (strtolower((string) $webhook->format)) {
			case 'discord':
				return self::buildDiscord($webhook, $eventName, $item);
			case 'generic':
				return self::buildGeneric($eventName, $item);
		}
		return null;
	}

	private static function buildDiscord($webhook, $eventName, $item)
	{
		switch ($eventName) {
			case self::EVENT_POST_POSTED:
				return self::discordPostPosted($webhook, $item);
			case self::EVENT_POST_SAVED:
				return self::discordPostSaved($webhook, $item);
			case self::EVENT_LOG_POSTED:
				return self::discordLogPosted($webhook, $item);
			case self::EVENT_NEWS_POSTED:
				return self::discordNewsPosted($webhook, $item);
		}
		return null;
	}

	/**
	 * post.posted - templated, public announcement, no ping.
	 */
	private static function discordPostPosted($webhook, $item)
	{
		$titleTpl = trim((string) $webhook->template_title) !== ''
			? $webhook->template_title : self::DEFAULT_TITLE_TEMPLATE;
		$descTpl = trim((string) $webhook->template_description) !== ''
			? $webhook->template_description : self::DEFAULT_DESCRIPTION_TEMPLATE;

		$vars = self::templateVars($item);
		return array(
			'content' => self::roleMentionForEvent($webhook, self::EVENT_POST_POSTED),
			'embeds'  => array(array(
				'title'       => self::renderTemplate($titleTpl, $vars),
				'description' => self::renderTemplate($descTpl, $vars),
				'url'         => $item['url_public'],
				'color'       => self::COLORS[array_rand(self::COLORS)],
				'timestamp'   => date('c', (int) $item['date_ts']),
			)),
		);
	}

	/**
	 * post.saved - lightweight, DOES ping co-authors (but never the actor who
	 * saved) and optionally a configured role.
	 */
	private static function discordPostSaved($webhook, $item)
	{
		$mentions = self::authorMentions($item, true);
		$role     = self::roleMentionForEvent($webhook, self::EVENT_POST_SAVED);
		if ($role !== '') {
			array_unshift($mentions, $role);
		}
		$contentLine = empty($mentions) ? '' : implode(' ', $mentions);

		$desc = "**Title** - ".self::nz($item['title'])."\n"
			. "**Mission** - ".self::nz($item['mission_title'], '(no mission)')."\n"
			. "**Saved by** - ".self::nz($item['actor']['name'])."\n\n"
			. "[View the post](".$item['url_admin'].")";

		return array(
			'content' => $contentLine,
			'embeds'  => array(array(
				'title'       => 'A saved mission post has been updated',
				'description' => $desc,
				'url'         => $item['url_admin'],
				'color'       => 0x95A5A6,
				'timestamp'   => date('c'),
			)),
		);
	}

	/**
	 * log.posted - personal log announcement. Templated, public, no ping.
	 */
	private static function discordLogPosted($webhook, $item)
	{
		$titleVal = isset($webhook->template_log_title) ? (string) $webhook->template_log_title : '';
		$descVal  = isset($webhook->template_log_description) ? (string) $webhook->template_log_description : '';
		$titleTpl = trim($titleVal) !== '' ? $titleVal : self::DEFAULT_LOG_TITLE_TEMPLATE;
		$descTpl  = trim($descVal) !== '' ? $descVal : self::DEFAULT_LOG_DESCRIPTION_TEMPLATE;

		$vars = self::templateVars($item);
		return array(
			'content' => self::roleMentionForEvent($webhook, self::EVENT_LOG_POSTED),
			'embeds'  => array(array(
				'title'       => self::renderTemplate($titleTpl, $vars),
				'description' => self::renderTemplate($descTpl, $vars),
				'url'         => $item['url_public'],
				'color'       => self::COLORS[array_rand(self::COLORS)],
				'timestamp'   => date('c', (int) $item['date_ts']),
			)),
		);
	}

	/**
	 * news.posted - news item announcement. Templated, public, no ping.
	 */
	private static function discordNewsPosted($webhook, $item)
	{
		$titleVal = isset($webhook->template_news_title) ? (string) $webhook->template_news_title : '';
		$descVal  = isset($webhook->template_news_description) ? (string) $webhook->template_news_description : '';
		$titleTpl = trim($titleVal) !== '' ? $titleVal : self::DEFAULT_NEWS_TITLE_TEMPLATE;
		$descTpl  = trim($descVal) !== '' ? $descVal : self::DEFAULT_NEWS_DESCRIPTION_TEMPLATE;

		$vars = self::templateVars($item);
		return array(
			'content' => self::roleMentionForEvent($webhook, self::EVENT_NEWS_POSTED),
			'embeds'  => array(array(
				'title'       => self::renderTemplate($titleTpl, $vars),
				'description' => self::renderTemplate($descTpl, $vars),
				'url'         => $item['url_public'],
				'color'       => self::COLORS[array_rand(self::COLORS)],
				'timestamp'   => date('c', (int) $item['date_ts']),
			)),
		);
	}

	private static function buildGeneric($eventName, $item)
	{
		$base = array(
			'event'    => $eventName,
			'fired_at' => date('c'),
			'authors'  => $item['authors'],
			'actor'    => $item['actor'],
			'sim'      => array('name' => $item['sim_name']),
		);

		if ($item['type'] === 'post') {
			$base['post'] = array(
				'id'         => (int) $item['id'],
				'title'      => $item['title'],
				'content'    => $item['content'],
				'status'     => $item['status'],
				'mission_id' => (int) $item['mission_id'],
				'mission'    => $item['mission_title'],
				'location'   => $item['post_location'],
				'timeline'   => $item['timeline'],
				'date'       => date('c', (int) $item['date_ts']),
				'url_public' => $item['url_public'],
				'url_admin'  => $item['url_admin'],
			);
		} elseif ($item['type'] === 'log') {
			$base['log'] = array(
				'id'         => (int) $item['id'],
				'title'      => $item['title'],
				'content'    => $item['content'],
				'status'     => $item['status'],
				'date'       => date('c', (int) $item['date_ts']),
				'url_public' => $item['url_public'],
				'url_admin'  => $item['url_admin'],
			);
		} elseif ($item['type'] === 'news') {
			$base['news'] = array(
				'id'         => (int) $item['id'],
				'title'      => $item['title'],
				'content'    => $item['content'],
				'category'   => $item['category'],
				'type'       => ! empty($item['is_private']) ? 'private' : 'public',
				'status'     => $item['status'],
				'date'       => date('c', (int) $item['date_ts']),
				'url_public' => $item['url_public'],
				'url_admin'  => $item['url_admin'],
			);
		}

		return $base;
	}

	// ---------- item loaders ----------

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

		return array(
			'type'          => 'post',
			'id'            => $row->post_id,
			'title'         => $row->post_title,
			'content'       => $row->post_content,
			'status'        => $row->post_status,
			'date_ts'       => $row->post_date,
			'mission_id'    => $row->post_mission,
			'mission_title' => $mission ? $mission->mission_title : '',
			'post_location' => isset($row->post_location) ? $row->post_location : '',
			'timeline'      => self::formatTimeline($row, $mission),
			'authors'       => self::charactersFromCsv($row->post_authors),
			'actor'         => self::loadActor( ! empty($row->post_saved) ? $row->post_saved : self::firstCsv($row->post_authors)),
			'sim_name'      => self::simName(),
			'url_public'    => $siteUrl.'/sim/viewpost/'.$row->post_id,
			'url_admin'     => $siteUrl.'/write/missionpost/'.$row->post_id.'/view',
		);
	}

	private static function loadLog($logId)
	{
		$ci =& get_instance();
		$row = $ci->db->get_where('personallogs', array('log_id' => $logId))->row();
		if ( ! $row) {
			return null;
		}
		$siteUrl = rtrim(site_url('/'), '/');

		return array(
			'type'       => 'log',
			'id'         => $row->log_id,
			'title'      => $row->log_title,
			'content'    => $row->log_content,
			'status'     => $row->log_status,
			'date_ts'    => $row->log_date,
			'authors'    => self::charactersFromCsv($row->log_author_character),
			'actor'      => self::loadActor($row->log_author_character),
			'sim_name'   => self::simName(),
			'url_public' => $siteUrl.'/sim/viewlog/'.$row->log_id,
			'url_admin'  => $siteUrl.'/write/personallog/'.$row->log_id.'/view',
		);
	}

	private static function loadNews($newsId)
	{
		$ci =& get_instance();
		$row = $ci->db->get_where('news', array('news_id' => $newsId))->row();
		if ( ! $row) {
			return null;
		}

		$category = '';
		if ( ! empty($row->news_cat)) {
			$cat = $ci->db->get_where('news_categories', array('newscat_id' => $row->news_cat))->row();
			$category = $cat ? $cat->newscat_name : '';
		}
		$siteUrl = rtrim(site_url('/'), '/');

		return array(
			'type'       => 'news',
			'id'         => $row->news_id,
			'title'      => $row->news_title,
			'content'    => $row->news_content,
			'status'     => $row->news_status,
			'date_ts'    => $row->news_date,
			'category'   => $category,
			'is_private' => (isset($row->news_private) && $row->news_private === 'y'),
			'authors'    => self::charactersFromCsv($row->news_author_character),
			'actor'      => self::loadActor($row->news_author_character),
			'sim_name'   => self::simName(),
			'url_public' => $siteUrl.'/main/viewnews/'.$row->news_id,
			'url_admin'  => $siteUrl.'/write/news/'.$row->news_id.'/view',
		);
	}

	// ---------- character / author loading ----------

	private static function firstCsv($csv)
	{
		$parts = explode(',', (string) $csv);
		return isset($parts[0]) ? trim($parts[0]) : '';
	}

	/**
	 * Enrich a CSV (or single) charid list into an author array, preserving
	 * the original order: [{id, name, rank, rank_name, discord_id, user_id}, ...]
	 */
	private static function charactersFromCsv($csv)
	{
		$ci =& get_instance();
		$result = array();
		if (empty($csv)) {
			return $result;
		}
		$ids = array_filter(array_map('intval', explode(',', (string) $csv)));
		if (empty($ids)) {
			return $result;
		}

		$ci->db->select('characters.charid AS id, characters.first_name, characters.last_name, characters.suffix, characters.user, characters.display_name, ranks.rank_name, users.nova_ext_discord_auth_id AS discord_id');
		$ci->db->from('characters');
		$ci->db->join('ranks', 'ranks.rank_id = characters.rank', 'left');
		$ci->db->join('users', 'users.userid = characters.user', 'left');
		$ci->db->where_in('characters.charid', $ids);
		$rows = $ci->db->get()->result();

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

	private static function loadActor($charId)
	{
		$charId = (int) $charId;
		if ($charId <= 0) {
			return array('id' => null, 'name' => '(unknown)', 'rank' => null, 'rank_name' => null, 'discord_id' => null, 'user_id' => null);
		}
		$list = self::charactersFromCsv((string) $charId);
		return ! empty($list) ? $list[0]
			: array('id' => $charId, 'name' => '(unknown)', 'rank' => null, 'rank_name' => null, 'discord_id' => null, 'user_id' => null);
	}

	// ---------- template + formatting helpers ----------

	private static function templateVars($item)
	{
		$authorsPlain    = self::renderAuthorsPlain($item['authors']);
		$authorsMentions = self::renderAuthorsWithMentions($item['authors']);

		$vars = array(
			'{sim_name}'         => $item['sim_name'],
			'{title}'            => self::nz($item['title']),
			'{post_title}'       => self::nz($item['title']),
			'{authors}'          => $authorsPlain,
			'{authors_plain}'    => $authorsPlain,
			'{authors_mentions}' => $authorsMentions,
			'{body}'             => self::smartTruncate(self::htmlToMarkdown($item['content']), self::BODY_MAX_CHARS),
			'{url}'              => $item['url_public'],
			'{url_admin}'        => $item['url_admin'],
			'{actor}'            => $item['actor']['name'],
		);

		if ($item['type'] === 'post') {
			$vars['{post_type}'] = 'Mission Post';
			$vars['{mission}']   = self::nz(isset($item['mission_title']) ? $item['mission_title'] : '', '(no mission)');
			$vars['{location}']  = self::nz(isset($item['post_location']) ? $item['post_location'] : '', '(unspecified)');
			$vars['{timeline}']  = self::nz(isset($item['timeline']) ? $item['timeline'] : '', '(no timeline)');
		} elseif ($item['type'] === 'news') {
			$type = ! empty($item['is_private']) ? 'Private' : 'Public';
			$vars['{category}'] = self::nz(isset($item['category']) ? $item['category'] : '');
			$vars['{type}']     = $type;
			$meta = array();
			if (self::nz(isset($item['category']) ? $item['category'] : '', '') !== '') { $meta[] = $item['category']; }
			$meta[] = $type;
			$meta[] = 'by '.$authorsPlain;
			$vars['{meta}'] = implode(' · ', $meta);
		}
		return $vars;
	}

	private static function renderTemplate($tpl, $vars)
	{
		return strtr($tpl, $vars);
	}

	private static function renderAuthorsWithMentions($authors)
	{
		if (empty($authors)) {
			return '(no authors)';
		}
		$parts = array();
		foreach ($authors as $a) {
			$parts[] = ! empty($a['discord_id']) ? '<@'.$a['discord_id'].'>' : self::renderAuthorPlain($a);
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

	private static function authorMentions($item, $excludeActor = false)
	{
		$actorDiscord = isset($item['actor']['discord_id']) ? (string) $item['actor']['discord_id'] : '';
		$actorId      = isset($item['actor']['id']) ? (int) $item['actor']['id'] : 0;

		$out = array();
		foreach ($item['authors'] as $a) {
			if (empty($a['discord_id'])) {
				continue;
			}
			if ($excludeActor) {
				// Skip the author who triggered the save - match on the linked
				// Discord account first, then fall back to the character id.
				if ($actorDiscord !== '' && (string) $a['discord_id'] === $actorDiscord) {
					continue;
				}
				if ($actorId > 0 && (int) $a['id'] === $actorId) {
					continue;
				}
			}
			$out[(int) $a['discord_id']] = '<@'.$a['discord_id'].'>';
		}
		return array_values($out);
	}

	/**
	 * Returns a Discord role mention (<@&id>) to put in the content line for
	 * the given event, or '' if no role is configured or this event isn't
	 * opted in. Role pings only fire from message content, so each builder
	 * places the result in the payload's `content`.
	 */
	private static function roleMentionForEvent($webhook, $eventName)
	{
		$roleId = isset($webhook->mention_role_id) ? trim((string) $webhook->mention_role_id) : '';
		if ($roleId === '' || ! ctype_digit($roleId)) {
			return '';
		}
		$events = isset($webhook->mention_role_events)
			? json_decode((string) $webhook->mention_role_events, true) : null;
		if ( ! is_array($events) || ! in_array($eventName, $events, true)) {
			return '';
		}
		return '<@&'.$roleId.'>';
	}

	private static function formatTimeline($postRow, $missionRow)
	{
		$features = Config::features();
		if (empty($features['ordered_mission_posts']) || ! class_exists('nova_ext_sim_central\\TimelineFormat')) {
			return isset($postRow->post_timeline) ? (string) $postRow->post_timeline : '';
		}
		$config = ($missionRow && isset($missionRow->mission_ext_ordered_config_setting))
			? (string) $missionRow->mission_ext_ordered_config_setting
			: 'nova_ext_ordered_post_day';
		$dayColumn = $config ?: 'nova_ext_ordered_post_day';
		$dayValue  = property_exists($postRow, $dayColumn) ? (string) $postRow->$dayColumn : '';
		$timeValue = property_exists($postRow, 'nova_ext_ordered_post_time') ? (string) $postRow->nova_ext_ordered_post_time : '';
		if ($dayValue === '' && $timeValue === '') {
			return isset($postRow->post_timeline) ? (string) $postRow->post_timeline : '';
		}
		$dayLabel  = ($dayColumn === 'nova_ext_ordered_post_day') ? 'Day' : ucfirst(str_replace('nova_ext_ordered_post_', '', $dayColumn));
		$timeLabel = $timeValue !== '' ? ' at '.$timeValue : '';
		return trim($dayLabel.' '.$dayValue.$timeLabel);
	}

	private static function simName()
	{
		$ci =& get_instance();
		$row = $ci->db->get_where('settings', array('setting_key' => 'sim_name'))->row();
		return $row ? (string) $row->setting_value : '';
	}

	/** Null/empty coalesce to a fallback string. */
	private static function nz($value, $fallback = '')
	{
		$value = (string) $value;
		return $value !== '' ? $value : $fallback;
	}

	public static function htmlToMarkdown($html)
	{
		$s = (string) $html;
		$s = preg_replace('/<(b|strong)\b[^>]*>(.*?)<\/\1>/is', '**$2**', $s);
		$s = preg_replace('/<(i|em)\b[^>]*>(.*?)<\/\1>/is', '*$2*', $s);
		$s = preg_replace_callback('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', function($m) {
			return '['.trim(strip_tags($m[2])).']('.$m[1].')';
		}, $s);
		$s = preg_replace('/<\/p>\s*<p\b[^>]*>/i', "\n\n", $s);
		$s = preg_replace('/<br\s*\/?>(\s*<br\s*\/?>)+/i', "\n\n", $s);
		$s = preg_replace('/<br\s*\/?>/i', "\n", $s);
		$s = strip_tags($s);
		$s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$s = preg_replace('/\r\n/', "\n", $s);
		$s = preg_replace('/\n{3,}/', "\n\n", $s);
		return trim($s);
	}

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
			CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'User-Agent: SimCentralSuite-Webhook/1.0'),
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
	 * Admin "Test" button. Fires a synthetic event of the chosen type against
	 * one webhook, returns a (status, message) tuple for the ACP flash.
	 */
	public static function testWebhook($webhookId, $eventName = self::EVENT_POST_POSTED)
	{
		$ci =& get_instance();
		$webhook = $ci->db->get_where('sim_central_webhooks', array('id' => $webhookId))->row();
		if ( ! $webhook) {
			return array('error', 'Webhook not found.');
		}

		$item = self::stubItem($eventName);
		$payload = self::buildPayload($webhook, $eventName, $item);
		if ($payload === null) {
			return array('error', 'Unknown webhook format: '.$webhook->format);
		}
		self::deliver($webhook, $payload);

		$webhook = $ci->db->get_where('sim_central_webhooks', array('id' => $webhookId))->row();
		if ($webhook->last_status >= 200 && $webhook->last_status < 300) {
			return array('success', 'Test delivered. HTTP '.$webhook->last_status.'.');
		}
		if ((int) $webhook->last_status === 0) {
			return array('error', 'Network error: '.$webhook->last_error);
		}
		return array('error', 'HTTP '.$webhook->last_status.': '.$webhook->last_error);
	}

	private static function stubItem($eventName)
	{
		$site = rtrim(site_url('/'), '/');
		$common = array(
			'authors'  => array(),
			'actor'    => array('id' => null, 'name' => 'Webhook Test', 'rank' => null, 'rank_name' => null, 'discord_id' => null, 'user_id' => null),
			'sim_name' => self::simName(),
			'date_ts'  => time(),
			'status'   => 'activated',
		);

		switch ($eventName) {
			case self::EVENT_LOG_POSTED:
				return array_merge($common, array(
					'type' => 'log', 'id' => 0,
					'title' => 'Test Log',
					'content' => '<p>This is a <b>test</b> personal-log webhook delivery from the Sim Central Suite.</p>',
					'url_public' => $site, 'url_admin' => $site,
				));
			case self::EVENT_NEWS_POSTED:
				return array_merge($common, array(
					'type' => 'news', 'id' => 0,
					'title' => 'Test News Item',
					'content' => '<p>This is a <b>test</b> news webhook delivery from the Sim Central Suite.</p>',
					'category' => 'Announcements', 'is_private' => false,
					'url_public' => $site, 'url_admin' => $site,
				));
			default: // post.saved / post.posted
				return array_merge($common, array(
					'type' => 'post', 'id' => 0,
					'title' => 'Test Post',
					'content' => '<p>This is a <b>test</b> mission-post webhook delivery from the Sim Central Suite.</p>',
					'mission_id' => 0, 'mission_title' => 'Test Mission',
					'post_location' => 'Test Location', 'timeline' => 'Day 1 at 1200',
					'url_public' => $site, 'url_admin' => $site,
				));
		}
	}
}
