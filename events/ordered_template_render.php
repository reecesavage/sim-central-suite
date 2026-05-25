<?php

// Injects the jQuery UI datepicker assets + the dynamic form-behaviour script
// on every template render so the ordered mission/post forms (which can appear
// in admin and main) wire up correctly. Mirrors the standalone extension's
// bootstrap listener so behaviour is unchanged after migration.
$this->event->listen(['template', 'render', 'data'], function($event){
	$modFolder = base_url().MODFOLDER;
	$event['data']['javascript'] .=
		'<link rel="stylesheet" href="'.$modFolder.'/assets/js/css/jquery.ui.datepicker.css">'
		.'<script type="text/javascript" src="'.$modFolder.'/assets/js/jquery.ui.datepicker.min.js"></script>'
		.$this->extension['nova_ext_sim_central']->inline_js('ordered_custom', 'admin');
});
