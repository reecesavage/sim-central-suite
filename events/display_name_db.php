<?php

//check
$this->event->listen(['db', 'insert', 'prepare', 'characters'], function($event){
if(($display_name = $this->input->post('display_name', true)) !== null)
    $event['data']['display_name'] = $display_name;
});
