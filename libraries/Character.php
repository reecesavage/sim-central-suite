<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Display Name feature - logic behind the Characters_model::get_character_name
 * shim. Returns a character's display_name when set, otherwise the default
 * first-name / last-name / suffix combination - optionally prefixed with the
 * rank and wrapped in a bio anchor.
 */
class Character
{
	public static function name($character = '', $showRank = false, $showShortRank = false, $showBioLink = false)
	{
		$ci =& get_instance();

		$ci->db->from('characters');
		if ($showRank === true) {
			$ci->db->join('ranks', 'ranks.rank_id = characters.rank');
		}
		$ci->db->where('charid', $character);

		$query = $ci->db->get();
		if ($query->num_rows() === 0) {
			return false;
		}

		$item = $query->row();

		$array = array();
		$array['rank'] = ($showRank === true) ? $item->rank_name : false;
		$array['rank'] = ($showShortRank === true) ? $item->rank_short_name : $array['rank'];

		if ( ! empty($item->display_name)) {
			$array['display_name'] = $item->display_name;
		} else {
			$array['first_name'] = $item->first_name;
			$array['last_name']  = $item->last_name;
			$array['suffix']     = $item->suffix;
		}

		foreach ($array as $key => $value) {
			if (empty($value)) {
				unset($array[$key]);
			}
		}

		$string = implode(' ', $array);

		if ($showBioLink === true) {
			return anchor('personnel/character/'.$item->charid, $string);
		}

		return $string;
	}
}
