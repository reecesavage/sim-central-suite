<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Discord Sign-In - GM join-application email.
 *
 * The Main.php `join_email` shim overrides nova_main::_email() and hands
 * the `join_gm` type here. This is a faithful reproduction of the core
 * case (nova_main::_email, case 'join_gm') with ONE addition: a Discord
 * row in the applicant's Basic Info block, so GMs can check the applicant
 * against their Discord server straight from the email.
 *
 * Living in a suite library (rather than baked into the shim block) means
 * fixes ship with normal suite updates - the shim itself stays a thin,
 * stable wrapper.
 *
 * send() returns the mail result (bool) when it handled the email, or
 * NULL to decline - the shim then falls back to parent::_email(), so a
 * problem here can never cost a sim its GM join notifications.
 */
class JoinEmail
{
	public static function send(array $data)
	{
		try {
			return self::sendJoinGm($data);
		} catch (\Throwable $e) {
			log_message('error', 'nova_ext_sim_central JoinEmail fell back to core: '.$e->getMessage());
			return null;
		}
	}

	private static function sendJoinGm(array $data)
	{
		$ci =& get_instance();
		$ci->load->library('mail');
		$ci->load->library('parser');
		$ci->load->model('positions_model', 'pos');
		$ci->load->model('users_model', 'user');
		$ci->load->model('characters_model', 'char');

		$content = lang('email_content_join_gm');

		// compile the information for the email
		$emailData = array(
			'email_subject' => lang('email_subject_join_gm'),
			'email_from'    => ucfirst(lang('time_from')).': '.$data['name'].' - '.$data['email'],
			'email_content' => nl2br($content),
			'basic_title'   => ucwords(lang('labels_basic').' '.lang('labels_info')),
		);

		// build the user data array
		$p_data = $ci->user->get_user($data['user']);
		$emailData['user'] = array(
			array(
				'label' => ucfirst(lang('labels_name')),
				'data'  => $data['name']),
			array(
				'label' => ucwords(lang('labels_email_address')),
				'data'  => $data['email']),
			array(
				'label' => ucwords(lang('labels_ipaddr')),
				'data'  => $data['ipaddr']),
			array(
				'label' => lang('labels_dob'),
				'data'  => $p_data->date_of_birth),
		);

		// The suite's addition: the applicant's linked public Discord ID.
		// When the sim requires linking to join, an application that
		// somehow arrives WITHOUT one is worth flagging too.
		if ( ! empty($p_data->nova_ext_discord_auth_id)) {
			$emailData['user'][] = array(
				'label' => 'Discord',
				'data'  => 'ID '.$p_data->nova_ext_discord_auth_id,
			);
		} elseif (class_exists('nova_ext_sim_central\\DiscordAuth') && DiscordAuth::requiredOnJoin()) {
			$emailData['user'][] = array(
				'label' => 'Discord',
				'data'  => 'NOT LINKED (this sim requires Discord linking to join)',
			);
		}

		// build the character data array
		$c_data = $ci->char->get_character($data['id']);
		$emailData['character'] = array(
			array(
				'label' => ucwords(lang('global_character').' '.lang('labels_name')),
				'data'  => $ci->char->get_character_name($data['id'])),
			array(
				'label' => ucfirst(lang('global_position')),
				'data'  => $ci->pos->get_position($c_data->position_1, 'pos_name')),
		);

		// get the sections
		$sections = $ci->char->get_bio_sections();

		if ($sections->num_rows() > 0) {
			foreach ($sections->result() as $sec) {
				$emailData['sections'][$sec->section_id]['title'] = $sec->section_name;

				$fields = $ci->char->get_bio_fields($sec->section_id);

				if ($fields->num_rows() > 0) {
					foreach ($fields->result() as $field) {
						$bio_data = $ci->char->get_field_data($field->field_id, $data['id']);

						if ($bio_data->num_rows() > 0) {
							foreach ($bio_data->result() as $item) {
								$emailData['sections'][$sec->section_id]['fields'][] = array(
									'field' => $field->field_label_page,
									'data'  => text_output($item->data_value, ''),
								);
							}
						}
					}
				}
			}
		}

		$emailData['sample_post_label'] = ucwords(lang('labels_sample_post'));
		$emailData['sample_post'] = ($ci->mail->mailtype == 'html') ? nl2br($data['sample_post']) : $data['sample_post'];

		// where should the email be coming from
		$loc = \Location::email('main_join_gm', $ci->mail->mailtype);

		// parse the message
		$message = $ci->parser->parse_string($loc, $emailData, true);

		// set the TO variable
		$to = implode(',', $ci->user->get_emails_with_access('characters/index'));

		// set the parameters for sending the email
		$ci->mail->from(\Util::email_sender(), $data['name']);
		$ci->mail->bcc($to);
		$ci->mail->subject($ci->options['email_subject'].' '.$emailData['email_subject']);
		$ci->mail->message($message, $content);

		return $ci->mail->send();
	}
}
