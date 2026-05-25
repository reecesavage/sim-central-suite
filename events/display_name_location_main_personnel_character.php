<?php

$this->event->listen(['location', 'view', 'data', 'main', 'personnel_character'], function($event){

     if(isset($event['data']['character']['id']))
     {
     	$main_char = $this->char->get_character_name($event['data']['character']['id'], true);
        $event['data']['header']= $main_char;

     }

	});
