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
		if (self::has($body, 'ordered_day')) {
			$_POST['nova_ext_ordered_post_day']            = (string) $body['ordered_day'];
			$_POST['post_chronological_mission_post_day']  = (string) $body['ordered_day'];
		}
		if (self::has($body, 'ordered_time')) {
			$_POST['nova_ext_ordered_post_time']           = (string) $body['ordered_time'];
			$_POST['post_chronological_mission_post_time'] = (string) $body['ordered_time'];
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
		$ci->lang->load('email');
		$ci->load->model('characters_model', 'char');
		$ci->load->model('users_model', 'user');
		$ci->load->model('posts_model', 'posts');

		$post = $ci->posts->get_post($postId);
		if ( ! $post) {
			return;
		}

		$missionName = '';
		if ( ! empty($post->post_mission)) {
			$m = $ci->db->select('mission_title')->get_where('missions', array('mission_id' => (int) $post->post_mission))->row();
			$missionName = $m ? $m->mission_title : '';
		}

		$authors  = lang('email_content_post_author')  .$ci->char->get_authors($post->post_authors, true);
		$mission  = lang('email_content_post_mission') .$missionName;
		$timeline = lang('email_content_post_timeline').$post->post_timeline;
		$location = lang('email_content_post_location').$post->post_location;
		$subject  = $missionName.' - '.$post->post_title.lang('email_subject_saved_post');
		$fromName = $ci->char->get_character_name((int) $actorCharId, true, true);

		$content = sprintf(
			lang('email_content_mission_post_saved'),
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
		$ci->lang->load('email');
		$ci->load->model('characters_model', 'char');
		$ci->load->model('users_model', 'user');
		$ci->load->model('posts_model', 'posts');

		$post = $ci->posts->get_post($postId);
		if ( ! $post) {
			return;
		}

		$missionName = '';
		if ( ! empty($post->post_mission)) {
			$m = $ci->db->select('mission_title')->get_where('missions', array('mission_id' => (int) $post->post_mission))->row();
			$missionName = $m ? $m->mission_title : '';
		}

		$authors  = lang('email_content_post_author')  .$ci->char->get_authors($post->post_authors, true);
		$mission  = lang('email_content_post_mission') .$missionName;
		$timeline = lang('email_content_post_timeline').$post->post_timeline;
		$location = lang('email_content_post_location').$post->post_location;
		$subject  = $missionName.' - '.$post->post_title;
		$fromName = $ci->char->get_character_name((int) $actorCharId, true, true);

		$content = sprintf(
			lang('email_content_mission_post'),
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
}
