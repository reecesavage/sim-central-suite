<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Anti Spam Questions feature - logic behind the Main controller shims.
 *
 * The shims (contact / join in application/controllers/Main.php) delegate
 * here so behaviour can change without re-editing Main.php.
 */
class AntiSpam
{
	/**
	 * Whether the current request should be blocked because the security
	 * answer is missing or wrong. Returns false when the user isn't actually
	 * submitting a form, or when no questions are configured.
	 */
	public static function shouldBlock()
	{
		if ( ! isset($_POST['submit'])) {
			return false;
		}
		if ( ! self::hasQuestions()) {
			return false;
		}

		$settingId = isset($_POST['nova_ext_anti_spam_questions_setting_id'])
			? $_POST['nova_ext_anti_spam_questions_setting_id']
			: 0;
		$answer = isset($_POST['nova_ext_anti_spam_questions_answer'])
			? $_POST['nova_ext_anti_spam_questions_answer']
			: '';

		if (empty($settingId) || $answer === '') {
			return true;
		}

		return ! self::checkAnswer($answer, $settingId);
	}

	public static function hasQuestions()
	{
		$ci =& get_instance();
		$ci->db->where('setting_key', 'question');
		return $ci->db->count_all_results('settings') > 0;
	}

	public static function checkAnswer($answer, $settingId)
	{
		$ci =& get_instance();
		$query = $ci->db->get_where('settings', array('setting_id' => $settingId));
		if ($query->num_rows() === 0) {
			return false;
		}

		$setting = $query->row();
		$json = json_decode($setting->setting_value, true);
		if (empty($json['answer']) || ! is_array($json['answer'])) {
			return false;
		}

		$needle = strtoupper((string) $answer);
		foreach ($json['answer'] as $candidate) {
			if (strtoupper((string) $candidate) === $needle) {
				return true;
			}
		}
		return false;
	}

	public static function flashError()
	{
		return array(
			'status'  => 'error',
			'message' => text_output('Security answer did not match or was blank. Please try again.'),
		);
	}
}
