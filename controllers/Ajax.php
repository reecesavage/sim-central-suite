<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Sim Central Suite - Ajax controller.
 *
 * Hosts the small AJAX endpoints the suite's admin pages call into. Each
 * route maps to a feature's facebox/confirm dialog or similar widget.
 */
class __extensions__nova_ext_sim_central__Ajax extends CI_Controller
{
	protected $_regions = array();

	public function __construct()
	{
		parent::__construct();

		$this->load->database();
		$this->load->library('session');
		$this->load->model('system_model', 'sys');

		Auth::is_logged_in();
		$this->lang->load('app', $this->session->userdata('language'));

		Template::$file = '_base/template_ajax';
		Template::$data['module'] = 'core';

		$this->_regions['content'] = false;
		$this->_regions['controls'] = false;
	}

	/**
	 * Confirm-delete facebox dialog for anti_spam questions.
	 */
	public function anti_spam_del()
	{
		$head = sprintf(
			lang('fbx_head'),
			ucwords(lang('actions_delete')),
			'Question'
		);

		$data = array();
		$data['header'] = $head;
		$data['id']     = $this->uri->segment(5, 0, true);
		$data['text']   = sprintf(
			lang('fbx_content_del_entry'),
			'Question',
			''
		);
		$data['inputs'] = array(
			'submit' => array(
				'type'    => 'submit',
				'class'   => 'hud_button',
				'name'    => 'submit',
				'value'   => 'submit',
				'content' => ucwords(lang('actions_submit')),
			),
		);

		$this->_regions['content']  = Location::ajax('/../extensions/nova_ext_sim_central/views/admin/pages/anti_spam_del', null, null, $data);
		$this->_regions['controls'] = form_button($data['inputs']['submit']).form_close();

		Template::assign($this->_regions);
		Template::render();
	}

	/**
	 * Mission lookup used by the ordered_mission_posts post form. Returns
	 * the mission row as JSON so the JS can decide which timeline fields to
	 * show and seed default date/stardate values.
	 */
	public function ordered_mission()
	{
		$data = array('status' => 'NOK');
		$id   = $this->input->get('mission', true);
		if ( ! empty($id)) {
			$query = $this->db->get_where('missions', array('mission_id' => $id));
			$post  = ($query->num_rows() > 0) ? $query->row() : false;
			$data['status'] = 'OK';
			$data['post']   = $post;
		}
		echo json_encode($data);
		exit;
	}

	/**
	 * Confirm-delete facebox dialog for url_parser tags.
	 */
	public function url_parser_del()
	{
		$head = sprintf(
			lang('fbx_head'),
			ucwords(lang('actions_delete')),
			'Tag'
		);

		$data = array();
		$data['header'] = $head;
		$data['id']     = $this->uri->segment(5, 0, true);
		$data['text']   = sprintf(
			lang('fbx_content_del_entry'),
			'Tag',
			''
		);
		$data['inputs'] = array(
			'submit' => array(
				'type'    => 'submit',
				'class'   => 'hud_button',
				'name'    => 'submit',
				'value'   => 'submit',
				'content' => ucwords(lang('actions_submit')),
			),
		);

		$this->_regions['content']  = Location::ajax('/../extensions/nova_ext_sim_central/views/admin/pages/url_parser_del', null, null, $data);
		$this->_regions['controls'] = form_button($data['inputs']['submit']).form_close();

		Template::assign($this->_regions);
		Template::render();
	}
}
