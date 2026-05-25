<?php
namespace nova_ext_sim_central;

class Installer
{
	function __construct()
	{
		$this->ci =& get_instance();
	}

	public function install()
	{
		$this->ci->load->model('menu_model');

		$expectedLink = 'extensions/nova_ext_sim_central/Manage/index';
		$cat = $this->ci->menu_model->get_menu_category('manageext');

		if ($cat === false) {
			$this->ci->menu_model->add_menu_category(array(
				'menucat_menu_cat' => 'manageext',
				'menucat_name'     => 'Manage Extensions',
				'menucat_type'     => 'adminsub',
				'menucat_order'    => 7,
			));
		}

		$query = $this->ci->db->get_where('menu_items', array('menu_name' => 'Sim Central Suite'));
		$item = ($query->num_rows() > 0) ? $query->row() : false;

		if ($item === false) {
			$this->ci->menu_model->add_menu_item(array(
				'menu_name'         => 'Sim Central Suite',
				'menu_group'        => 0,
				'menu_order'        => 0,
				'menu_sim_type'     => 1,
				'menu_link'         => $expectedLink,
				'menu_link_type'    => 'onsite',
				'menu_need_login'   => 'none',
				'menu_use_access'   => 'y',
				'menu_access'       => 'site/settings',
				'menu_access_level' => 0,
				'menu_display'      => 'y',
				'menu_type'         => 'adminsub',
				'menu_cat'          => 'manageext',
			));
		}
	}
}
