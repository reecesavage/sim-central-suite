<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Helpers for authoring mission posts through the REST API (Api::posts).
 *
 * Keeps the controller lean and keeps the Nova-specific write/notify quirks in
 * one place. The actual DB writes still go through Nova's own model methods
 * (Posts_model::create_mission_entry / update_post) so that:
 *   - the suite's webhook shims fire (post.saved / post.posted), and
 *   - Nova's `db.*.prepare.posts` listeners (Ordered Mission Posts, Content
 *     Filter) populate their own columns from $this->input->post().
 *
 * To make that second point work for an API request (no real form POST), call
 * populateRequestInputs() to seed $_POST with the keys those listeners read
 * before invoking the model method.
 */
class PostWrite
{
	/**
	 * Decide the saving/posting character for a user on a post.
	 *
	 * Rule (mirrors Nova's "main_char if present, else fallback" pattern): the
	 * user's main character if it's one of the post's authors; otherwise the
	 * highest-ranked of the user's characters on the post (ranks.rank_order
	 * ascending = senior first; tiebreak: lowest charid). Only the user's own
	 * characters are considered, so unlinked NPCs are never chosen.
	 *
	 * @return int charid, or 0 if none of the authors belong to the user.
	 */
	public static function resolveActor($userId, array $charIds)
	{
		$ci =& get_instance();
		$charIds = array_values(array_unique(array_map('intval', $charIds)));
		if (empty($charIds)) {
			return 0;
		}

		$rows = $ci->db
			->select('characters.charid, ranks.rank_order')
			->from('characters')
			->join('ranks', 'ranks.rank_id = characters.rank', 'left')
			->where('characters.user', (int) $userId)
			->where_in('characters.charid', $charIds)
			->get()->result();
		if (empty($rows)) {
			return 0;
		}

		$user = $ci->db->select('main_char')->get_where('users', array('userid' => (int) $userId))->row();
		$main = $user ? (int) $user->main_char : 0;
		foreach ($rows as $r) {
			if ((int) $r->charid === $main) {
				return $main;
			}
		}

		usort($rows, function ($a, $b) {
			$ao = ($a->rank_order === null) ? PHP_INT_MAX : (int) $a->rank_order;
			$bo = ($b->rank_order === null) ? PHP_INT_MAX : (int) $b->rank_order;
			if ($ao !== $bo) {
				return $ao - $bo;
			}
			return (int) $a->charid - (int) $b->charid;
		});

		return (int) $rows[0]->charid;
	}

	/** Distinct CSV of the owning user ids for the given author charids. */
	public static function authorsUsersCsv(array $charIds)
	{
		$ci =& get_instance();
		$charIds = array_values(array_unique(array_map('intval', $charIds)));
		if (empty($charIds)) {
			return '';
		}
		$rows = $ci->db->select('user')->where_in('charid', $charIds)->get('characters')->result();
		$users = array();
		foreach ($rows as $r) {
			if ( ! empty($r->user)) {
				$users[(int) $r->user] = true;
			}
		}
		return implode(',', array_keys($users));
	}

	/** All charids belonging to a user (PCs and NPCs). */
	public static function userCharacterIds($userId)
	{
		$ci =& get_instance();
		$rows = $ci->db->select('charid')->where('user', (int) $userId)->get('characters')->result();
		$ids = array();
		foreach ($rows as $r) {
			$ids[] = (int) $r->charid;
		}
		return $ids;
	}

	/**
	 * Apply Nova's post-moderation rule: when activating, if any author's user
	 * has moderate_posts on, the post goes to 'pending' instead. Anything other
	 * than an activate request passes through unchanged.
	 */
	public static function moderatedStatus($requestedStatus, $authorsCsv)
	{
		if ($requestedStatus !== 'activated') {
			return $requestedStatus;
		}
		$ci =& get_instance();
		$ci->load->model('users_model', 'user');
		return $ci->user->checking_moderation('post', $authorsCsv);
	}

	/**
	 * Validate the request's ordered-timeline fields against the mission's
	 * configuration. Ordered Mission Posts supports three per-mission schemes -
	 * day_time, date_time, stardate - and only the matching field is meaningful;
	 * sending the wrong one would otherwise be silently stored and ignored.
	 *
	 * Returns an array of error strings (empty = OK). Enforced only when the
	 * Ordered Mission Posts feature is on and the mission has a known config.
	 * `ordered_time` is valid for every scheme; a missing timeline is allowed
	 * (drafts fill it in later, defaults apply) - we only reject a field that
	 * doesn't match the mission's scheme.
	 */
	public static function timelineErrors($missionId, array $body)
	{
		$features = Config::features();
		if (empty($features['ordered_mission_posts'])) {
			return array();
		}

		$ci =& get_instance();
		$m = $ci->db->select('mission_ext_ordered_config_setting')
			->get_where('missions', array('mission_id' => (int) $missionId))->row();
		$config = $m && isset($m->mission_ext_ordered_config_setting)
			? (string) $m->mission_ext_ordered_config_setting : '';

		$expected = array(
			'day_time'  => 'ordered_day',
			'date_time' => 'ordered_date',
			'stardate'  => 'ordered_stardate',
		);
		if ( ! isset($expected[$config])) {
			return array(); // mission not ordered-configured; nothing to enforce
		}
		$want = $expected[$config];

		$errors = array();
		foreach (array('ordered_day', 'ordered_date', 'ordered_stardate') as $field) {
			if (self::has($body, $field) && $field !== $want) {
				$errors[] = "This mission uses the '".$config."' timeline scheme; send '".$want."' (not '".$field."').";
			}
		}
		return $errors;
	}

	/**
	 * Seed $_POST with the keys Nova's `db.*.prepare.posts` listeners read, so
	 * Ordered Mission Posts and Content Filter columns are written by their own
	 * existing code instead of being duplicated here. Only sets keys the caller
	 * actually supplied.
	 *
	 * Accepts in $body: ordered_day, ordered_time, ordered_date,
	 * ordered_stardate, age_gated.
	 */
	public static function populateRequestInputs(array $body)
	{
		// Ordered day/time are read from either the modern or the legacy
		// (chronological) keys depending on the mission's config, so set both.
		$cfg        = Config::load();
		$legacyMode = ! empty($cfg['setting']['legacy_mode']);

		if (self::has($body, 'ordered_day')) {
			$_POST['nova_ext_ordered_post_day'] = (string) $body['ordered_day'];
			if ($legacyMode) {
				$_POST['post_chronological_mission_post_day'] = (string) $body['ordered_day'];
			}
		}
		if (self::has($body, 'ordered_time')) {
			$_POST['nova_ext_ordered_post_time'] = (string) $body['ordered_time'];
			if ($legacyMode) {
				$_POST['post_chronological_mission_post_time'] = (string) $body['ordered_time'];
			}
		}
		if (self::has($body, 'ordered_date')) {
			$_POST['nova_ext_ordered_post_date'] = (string) $body['ordered_date'];
		}
		if (self::has($body, 'ordered_stardate')) {
			$_POST['nova_ext_ordered_post_stardate'] = (string) $body['ordered_stardate'];
		}
		if (array_key_exists('age_gated', $body)) {
			$_POST['nova_ext_content_filter_age_gated'] = ! empty($body['age_gated']) ? '1' : '0';
		}
	}

	/**
	 * Post-activation side effects Nova's web flow performs but the bare model
	 * call doesn't: stamp last_post on the authoring characters + their users,
	 * and send the sim's "new mission post" crew email. Call only when the
	 * final status is 'activated'. Webhooks fire via the model shim already.
	 */
	public static function afterActivate($postId, array $charIds, $actorCharId)
	{
		$ci =& get_instance();
		$charIds = array_values(array_unique(array_map('intval', $charIds)));
		if ( ! empty($charIds)) {
			$stamp = now();
			$ci->db->where_in('charid', $charIds)->update('characters', array('last_post' => $stamp));

			$userRows = $ci->db->select('user')->where_in('charid', $charIds)->get('characters')->result();
			$userIds = array();
			foreach ($userRows as $r) {
				if ( ! empty($r->user)) {
					$userIds[(int) $r->user] = true;
				}
			}
			if ( ! empty($userIds)) {
				$ci->db->where_in('userid', array_keys($userIds))->update('users', array('last_post' => $stamp));
			}
		}

		// Best-effort: a mail failure must never break the API write.
		try {
			self::sendPostEmail((int) $postId, (int) $actorCharId);
		} catch (\Throwable $e) {
			// swallow - the post is already saved/activated and webhooks fired
		}
	}

	/**
	 * Side effect for a SAVED draft: send Nova's "post saved" email to the
	 * post's authors who opted into save notifications - the same email the
	 * web Save button sends. Best-effort; never breaks the API write.
	 */
	public static function afterSave($postId, $actorCharId)
	{
		try {
			self::sendSaveEmail((int) $postId, (int) $actorCharId);
		} catch (\Throwable $e) {
			// swallow - the draft is already saved and the webhook fired
		}
	}

	/**
	 * Faithful reuse of Nova's nova_write::_email('post_save') case: the
	 * write_missionpost_saved template, recipients = post authors who have the
	 * email_mission_posts_save preference on. Gated on the system_email setting.
	 */
	private static function sendSaveEmail($postId, $actorCharId)
	{
		$ci =& get_instance();

		if (self::setting('system_email') !== 'on') {
			return;
		}

		$ci->load->library('mail');
		$ci->load->library('parser');
		$ci->load->model('characters_model', 'char');
		$ci->load->model('users_model', 'user');
		$ci->load->model('posts_model', 'posts');
		$L = self::emailLang();

		$post = $ci->posts->get_post($postId);
		if ( ! $post) {
			return;
		}

		$missionName = '';
		if ( ! empty($post->post_mission)) {
			$m = $ci->db->select('mission_title')->get_where('missions', array('mission_id' => (int) $post->post_mission))->row();
			$missionName = $m ? $m->mission_title : '';
		}

		$authors  = self::ll($L, 'email_content_post_author')  .$ci->char->get_authors($post->post_authors, true);
		$mission  = self::ll($L, 'email_content_post_mission') .$missionName;
		$timeline = self::ll($L, 'email_content_post_timeline').$post->post_timeline;
		$location = self::ll($L, 'email_content_post_location').$post->post_location;
		$subject  = $missionName.' - '.$post->post_title.self::ll($L, 'email_subject_saved_post');
		$fromName = $ci->char->get_character_name((int) $actorCharId, true, true);

		$content = sprintf(
			self::ll($L, 'email_content_mission_post_saved'),
			$post->post_title,
			site_url('login/index'),
			$authors, $mission, $location, $timeline, $post->post_content
		);
		$emailData = array(
			'email_content' => ($ci->mail->mailtype == 'html') ? nl2br($content) : $content,
		);

		$emLoc   = \Location::email('write_missionpost_saved', $ci->mail->mailtype);
		$message = $ci->parser->parse_string($emLoc, $emailData, true);

		// Recipients: the post's authors who opted into save notifications.
		$emails = $ci->char->get_character_emails($post->post_authors);
		if ( ! is_array($emails)) {
			$emails = array();
		}
		foreach ($emails as $key => $value) {
			if ($ci->user->get_pref('email_mission_posts_save', $key) != 'y') {
				unset($emails[$key]);
			}
		}
		$to = implode(',', $emails);
		if ($to === '') {
			return;
		}

		$prefix = (string) self::setting('email_subject');
		$ci->mail->from(\Util::email_sender(), $fromName);
		$ci->mail->to($to);
		$ci->mail->subject(trim($prefix.' '.$subject));
		$ci->mail->message($message);
		$ci->mail->send();
	}

	/**
	 * Faithful reuse of Nova's nova_write::_email('post') case using the same
	 * primitives (Mail/Parser libraries, the write_missionpost email template,
	 * get_crew_emails('email_mission_posts')). Gated on the system_email
	 * setting, exactly like the web flow.
	 */
	private static function sendPostEmail($postId, $actorCharId)
	{
		$ci =& get_instance();

		if (self::setting('system_email') !== 'on') {
			return;
		}

		$ci->load->library('mail');
		$ci->load->library('parser');
		$ci->load->model('characters_model', 'char');
		$ci->load->model('users_model', 'user');
		$ci->load->model('posts_model', 'posts');
		$L = self::emailLang();

		$post = $ci->posts->get_post($postId);
		if ( ! $post) {
			return;
		}

		$missionName = '';
		if ( ! empty($post->post_mission)) {
			$m = $ci->db->select('mission_title')->get_where('missions', array('mission_id' => (int) $post->post_mission))->row();
			$missionName = $m ? $m->mission_title : '';
		}

		$authors  = self::ll($L, 'email_content_post_author')  .$ci->char->get_authors($post->post_authors, true);
		$mission  = self::ll($L, 'email_content_post_mission') .$missionName;
		$timeline = self::ll($L, 'email_content_post_timeline').$post->post_timeline;
		$location = self::ll($L, 'email_content_post_location').$post->post_location;
		$subject  = $missionName.' - '.$post->post_title;
		$fromName = $ci->char->get_character_name((int) $actorCharId, true, true);

		$content = sprintf(
			self::ll($L, 'email_content_mission_post'),
			$authors, $mission, $location, $timeline, $post->post_content
		);
		$emailData = array(
			'email_content' => ($ci->mail->mailtype == 'html') ? nl2br($content) : $content,
		);

		$emLoc   = \Location::email('write_missionpost', $ci->mail->mailtype);
		$message = $ci->parser->parse_string($emLoc, $emailData, true);

		$emails = $ci->user->get_crew_emails(true, 'email_mission_posts');
		$to     = implode(',', $emails);
		if ($to === '') {
			return;
		}

		$prefix = (string) self::setting('email_subject');
		$ci->mail->from(\Util::email_sender(), $fromName);
		$ci->mail->to($to);
		$ci->mail->subject(trim($prefix.' '.$subject));
		$ci->mail->message($message);
		$ci->mail->send();
	}

	// ---------- post list helpers ----------

	/**
	 * Return activated (or any status) posts where any of the given character ids
	 * appears in post_authors. Uses the same OR-LIKE pattern as get_saved_posts()
	 * so middle-of-CSV authors are not missed (unlike get_user_posts which matches
	 * post_authors_users and has a known middle-CSV bug in Nova core).
	 */
	public static function postsByChars(array $charIds, $status, $limit = 0)
	{
		$ci =& get_instance();
		$charIds = array_values(array_unique(array_map('intval', $charIds)));
		if (empty($charIds)) {
			return array();
		}
		$parts = array();
		foreach ($charIds as $id) {
			$parts[] = "(post_authors LIKE '%,$id' OR post_authors LIKE '$id,%'"
				. " OR post_authors LIKE '%,$id,%' OR post_authors = '$id')";
		}
		$ci->db->from('posts');
		if ($status !== '') {
			$ci->db->where('post_status', $status);
		}
		$ci->db->where('('.implode(' OR ', $parts).')', null);
		$ci->db->order_by('post_date', 'desc');
		if ($limit > 0) {
			$ci->db->limit($limit);
		}
		return $ci->db->get()->result();
	}

	// ---------- post locking helpers ----------

	const LOCK_STALE_SECS = 600; // 10 minutes — matches Nova's desktop write CP

	/**
	 * Returns array('state' => 'none'|'mine'|'held', 'owner' => string, 'age' => int minutes).
	 * 'none'  — no active lock (free to edit).
	 * 'mine'  — current user holds the lock.
	 * 'held'  — another user holds a fresh lock; editing is blocked.
	 */
	public static function lockState($post, $uid)
	{
		$lockUser = (int) $post->post_lock_user;
		$lockDate = (int) $post->post_lock_date;

		if ($lockUser === 0 || $lockDate === 0) {
			return array('state' => 'none', 'owner' => '', 'age' => 0);
		}

		$age = (int) floor((now() - $lockDate) / 60);

		if ((now() - $lockDate) >= self::LOCK_STALE_SECS) {
			return array('state' => 'none', 'owner' => '', 'age' => $age);
		}

		if ($lockUser === (int) $uid) {
			return array('state' => 'mine', 'owner' => '', 'age' => $age);
		}

		return array('state' => 'held', 'owner' => self::lockOwnerName($lockUser), 'age' => $age);
	}

	public static function acquireLock($postId, $uid)
	{
		$ci =& get_instance();
		$ci->load->model('posts_model', 'posts');
		$ci->posts->update_post_lock((int) $postId, (int) $uid, true);
	}

	public static function releaseLock($postId)
	{
		$ci =& get_instance();
		$ci->load->model('posts_model', 'posts');
		$ci->posts->update_post_lock((int) $postId, 0, false);
	}

	/**
	 * Build a serialisable lock-status structure for a post row.
	 * locked=false when free or stale; locked=true with detail when held.
	 */
	public static function lockProjection($post)
	{
		$lockUser = (int) $post->post_lock_user;
		$lockDate = (int) $post->post_lock_date;

		if ($lockUser === 0 || $lockDate === 0) {
			return array('locked' => false, 'post_id' => (int) $post->post_id);
		}

		$ageSecs = now() - $lockDate;
		if ($ageSecs >= self::LOCK_STALE_SECS) {
			return array('locked' => false, 'post_id' => (int) $post->post_id);
		}

		$ageMinutes    = (int) floor($ageSecs / 60);
		$expireMinutes = (int) ceil((self::LOCK_STALE_SECS - $ageSecs) / 60);

		return array(
			'locked'  => true,
			'post_id' => (int) $post->post_id,
			'lock'    => array(
				'user_id'            => $lockUser,
				'owner'              => self::lockOwnerName($lockUser),
				'age_minutes'        => $ageMinutes,
				'expires_in_minutes' => $expireMinutes,
			),
		);
	}

	public static function lockOwnerName($userId)
	{
		$ci =& get_instance();
		$user = $ci->db->select('main_char')->get_where('users', array('userid' => (int) $userId))->row();
		$mainChar = $user ? (int) $user->main_char : 0;

		// display_name only exists once the Display Name feature's database
		// setup has run - never select it unconditionally.
		$cols = 'first_name, last_name'
			.(Migrations::hasColumn('characters', 'display_name') ? ', display_name' : '');

		if ($mainChar > 0) {
			$char = $ci->db->select($cols)
				->get_where('characters', array('charid' => $mainChar))->row();
			if ($char) {
				return ! empty($char->display_name) ? $char->display_name
					: trim(($char->first_name ?? '').' '.($char->last_name ?? ''));
			}
		}

		$char = $ci->db->select($cols)
			->where('user', (int) $userId)->where('crew_type', 'active')
			->limit(1)->get('characters')->row();
		if ($char) {
			return ! empty($char->display_name) ? $char->display_name
				: trim(($char->first_name ?? '').' '.($char->last_name ?? ''));
		}

		return 'another user';
	}

	// ---------- content round-trip helpers ----------

	// Tag policy for the mobile editor round-trip. The stored format is plain
	// text with \n line-breaks plus a *safe structural* allowlist. Nova's display
	// pipeline (nl2br → HTMLPurifier) renders all of these, so preserving them
	// keeps mobile edits from destroying formatting authored elsewhere.
	private const EDITOR_INLINE_KEEP = array('strong', 'em', 'u');
	private const EDITOR_BLOCK_KEEP  = array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'ul', 'ol', 'li');
	private const EDITOR_SOFT_BREAK  = array('div', 'p', 'tr'); // → single \n
	private const EDITOR_DROP        = array('script', 'style', 'head', 'title', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg');

	/**
	 * Convert HTML from the mobile contenteditable editor to the format Nova
	 * stores in post_content: plain text with \n line-breaks, the inline tags
	 * <strong>/<em>/<u>, and the structural tags <h1>-<h6>, <hr>, <blockquote>,
	 * <ul>/<ol>/<li>. Everything else is unwrapped (kept as text) or, for
	 * script/style/etc., dropped with its content. All attributes are stripped.
	 *
	 * Rule: each visual line/block break → exactly one \n. A preserved block tag
	 * self-separates at display, so \n adjacent to one is dropped as redundant.
	 * Nova's display pipeline (nl2br → HTMLPurifier) expands each \n to one <br>.
	 */
	public static function editorHtmlToStored($html)
	{
		$html = self::protectBareLt((string) $html);

		// A <br> that merely fills the end of a block element (the trailing <br>
		// WebKit/Blink leave inside <div>line<br></div> when a contenteditable
		// line is re-serialized) is not a real break — the block boundary already
		// ends the line. Drop it so one visual line stores as one \n, not two.
		$html = preg_replace('#<br\s*/?>(\s*)(</(?:div|p|h[1-6]|li|tr|blockquote|ul|ol)>)#i', '$1$2', $html);

		// Without ext-dom, fall back to the regex normalizer (flattens block tags
		// to plain lines, but stays functional and safe).
		if ( ! class_exists('DOMDocument')) {
			return self::editorHtmlToStoredLegacy($html);
		}

		$doc  = new \DOMDocument('1.0', 'UTF-8');
		$prev = libxml_use_internal_errors(true);
		$doc->loadHTML(
			'<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
				.$html.'</body></html>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$body = $doc->getElementsByTagName('body')->item(0);
		$out  = $body ? self::domWalkToStored($body) : '';

		// A preserved block tag already forces a line break at display, so a \n
		// touching one is redundant — drop it to avoid an extra blank line.
		$out = preg_replace('#\n*(</?(?:h[1-6]|blockquote|ul|ol|li)>|<hr\s*/?>)\n*#i', '$1', $out);

		// Collapse 3+ newlines to 2 (preserve an intentional paragraph gap).
		$out = preg_replace('/\n{3,}/', "\n\n", $out);

		return trim($out);
	}

	/**
	 * Escape a '<' that does not begin a real HTML tag (HTML5 tokenizer rule:
	 * '<' is data unless followed by a letter, '/', '!', or '?'). Keeps
	 * in-character prose like "I <3 you" or "in <20 minutes" intact — DOM and
	 * strip_tags would otherwise treat "<3" as a tag and eat to the next '>'.
	 */
	private static function protectBareLt($html)
	{
		return preg_replace('#<(?![a-zA-Z/!?])#', '&lt;', (string) $html);
	}

	/**
	 * Recursively serialize a DOM subtree to the stored format. Emits no
	 * attributes; drops script/style/etc. with their content; unwraps any tag
	 * not on the allowlist (keeping its text). Text nodes carry through as their
	 * decoded value, matching Nova's stored convention.
	 */
	private static function domWalkToStored(\DOMNode $node)
	{
		$out = '';
		foreach ($node->childNodes as $child) {
			if ($child->nodeType === XML_TEXT_NODE) {
				$out .= $child->nodeValue;
				continue;
			}
			if ($child->nodeType !== XML_ELEMENT_NODE) {
				continue;
			}
			$tag = strtolower($child->nodeName);
			if ($tag === 'b') { $tag = 'strong'; }
			elseif ($tag === 'i') { $tag = 'em'; }

			if (in_array($tag, self::EDITOR_DROP, true)) {
				continue; // drop the element and everything inside it
			}
			if ($tag === 'br') { $out .= "\n"; continue; }
			if ($tag === 'hr') { $out .= '<hr>'; continue; }
			if (in_array($tag, self::EDITOR_INLINE_KEEP, true)
				|| in_array($tag, self::EDITOR_BLOCK_KEEP, true)) {
				$out .= '<'.$tag.'>'.self::domWalkToStored($child).'</'.$tag.'>';
				continue;
			}
			if (in_array($tag, self::EDITOR_SOFT_BREAK, true)) {
				$out .= "\n".self::domWalkToStored($child);
				continue;
			}
			// Anything else (span, a, font, table, td, …): unwrap, keep the text.
			$out .= self::domWalkToStored($child);
		}
		return $out;
	}

	/**
	 * Regex fallback used only when ext-dom is unavailable. This is the pre-DOM
	 * behaviour: it flattens block tags (h1-h6, blockquote, li) to plain \n lines
	 * rather than preserving them, but is safe and never doubles spacing. Input
	 * is assumed already run through protectBareLt().
	 */
	private static function editorHtmlToStoredLegacy($html)
	{
		$html = (string) $html;
		$html = str_ireplace(array('<b>', '</b>'), array('<strong>', '</strong>'), $html);
		$html = str_ireplace(array('<i>', '</i>'), array('<em>', '</em>'), $html);
		$html = preg_replace('/<(strong|em|u)\s[^>]*>/i', '<$1>', $html);
		$html = preg_replace('/<(div|p|h[1-6]|li|tr|blockquote)(\s[^>]*)?>/i', "\n", $html);
		$html = preg_replace('/<br\s*\/?>/i', "\n", $html);
		$html = strip_tags($html, '<strong><em><u>');
		$html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$html = preg_replace('/\n{3,}/', "\n\n", $html);
		return trim($html);
	}

	/**
	 * Convert stored post_content to HTML suitable for loading into the mobile
	 * contenteditable editor: \n → <br>, text segments HTML-escaped, and the
	 * allowlisted inline + structural tags passed through verbatim.
	 *
	 * Also normalises legacy content from the desktop editor (strips it to the
	 * same allowlist so the mobile editor doesn't inject links, images, or other
	 * complex HTML it can't round-trip).
	 */
	public static function storedToEditorHtml($content)
	{
		// Normalise first (handles legacy desktop-editor content: attributes,
		// links, tables, etc.) — output is clean text + \n + the allowlist tags.
		$stored = self::editorHtmlToStored((string) $content);

		// Split on the allowlisted tags so we can escape text but keep tags
		// verbatim (both inline and structural, so headings/lists/hr/blockquote
		// render in the contenteditable instead of showing as literal markup).
		$tagPattern = '</?(?:strong|em|u|h[1-6]|blockquote|ul|ol|li)>|<hr\s*/?>';
		$parts = preg_split('#('.$tagPattern.')#i', $stored, -1, PREG_SPLIT_DELIM_CAPTURE);
		$out = '';
		foreach ($parts as $seg) {
			if (preg_match('#^(?:'.$tagPattern.')$#i', $seg)) {
				$out .= $seg;
			} else {
				$out .= str_replace("\n", '<br>',
					htmlspecialchars($seg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
			}
		}
		return $out;
	}

	private static function setting($key)
	{
		$ci =& get_instance();
		$row = $ci->db->select('setting_value')->get_where('settings', array('setting_key' => $key))->row();
		return $row ? $row->setting_value : null;
	}

	private static function has(array $body, $key)
	{
		return array_key_exists($key, $body) && $body[$key] !== null && $body[$key] !== '';
	}

	/**
	 * Load Nova's email language lines. The keys (email_content_mission_post,
	 * email_content_mission_post_saved, ...) live in the CORE MODULE's
	 * email_lang.php - a plain lang('...') from an extension controller resolves
	 * to CodeIgniter's own email_lang.php instead, which lacks them (you'd get
	 * the raw key in the email). Loading with the module alt_path + return=TRUE
	 * pulls the right file and sidesteps the is_loaded cache.
	 */
	private static function emailLang()
	{
		$ci =& get_instance();
		$arr = $ci->lang->load('email', '', true, true, MODPATH.'core/');
		return is_array($arr) ? $arr : array();
	}

	/** Read an email lang line from the fetched array, '' if absent. */
	private static function ll(array $lang, $key)
	{
		return isset($lang[$key]) ? $lang[$key] : '';
	}
}
