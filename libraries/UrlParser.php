<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * URL Parser feature - expands tag shortcodes (like [docs|getting-started]
 * or [missions|3|Read more]) into HTML anchors using the rows stored in the
 * `tag` table. The event listeners call this from across the site.
 *
 * The interface (constructor + urlparser($description) method) matches the
 * standalone extension's `\nova_ext_url_parser\Installer` so the migrated
 * event files only need the class name swapped to point here.
 */
class UrlParser
{
	public $ci;

	public function __construct()
	{
		$this->ci =& get_instance();
	}

	public function urlparser($description = null)
	{
		if (empty($description)) {
			return $description;
		}

		$content      = $description;
		$contentArray = array();
		$i            = 0;

		while ($i == 0) {
			$text = substr($content, strpos($content, '[') + 1);
			if ( ! empty($text)) {
				$finalText = explode(']', $text, 2);
				if (isset($finalText[0])) {
					$contentArray[] = $finalText[0];
					$content = isset($finalText[1]) ? $finalText[1] : '';
				} else {
					$i = 1;
				}
			} else {
				$i = 1;
			}
		}

		$finalArray = array();
		if ( ! empty($contentArray)) {
			foreach ($contentArray as $value) {
				$explode = explode('|', $value);
				$search  = isset($explode[0]) ? $explode[0] : '';
				$title   = isset($explode[1]) ? $explode[1] : '';
				$display = isset($explode[2]) ? $explode[2] : $title;

				$query = $this->ci->db->get_where('tag', array('title' => $search));
				$model = ($query->num_rows() > 0) ? $query->row() : false;
				if ( ! empty($model)) {
					if (empty($model->post_url)) {
						$url = $model->url.$title;
					} else {
						$url = $model->url.$title.'/'.$model->post_url;
					}

					if (empty($model->is_new_tab)) {
						$finalArray["[$value]"] = "<a href=$url>$display</a>";
					} else {
						$finalArray["[$value]"] = "<a target='_blank' href=$url>$display</a>";
					}
				}
			}
		}

		if ( ! empty($finalArray)) {
			foreach ($finalArray as $key => $value) {
				$content     = str_replace($key, $value, $description);
				$description = $content;
			}
			return $content;
		}

		return $description;
	}
}
