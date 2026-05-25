<?php

$this->event->listen(['db', 'insert', 'prepare', 'posts'], function($event){
if(($summary = $this->input->post('nova_ext_mission_post_summary', true)) !== null)
    $event['data']['nova_ext_mission_post_summary'] = $summary;
});
$this->event->listen(['db', 'update', 'prepare', 'posts'], function($event){
if(($summary = $this->input->post('nova_ext_mission_post_summary', true)) !== null)
    $event['data']['nova_ext_mission_post_summary'] = $summary;
});




$this->event->listen(['db', 'insert', 'prepare', 'missions'], function($event){
if(($summaryEnable = $this->input->post('mission_ext_mission_post_summary_enable', true)) !== null)
    $event['data']['mission_ext_mission_post_summary_enable'] = $summaryEnable;
});
$this->event->listen(['db', 'update', 'prepare', 'missions'], function($event){

if(($summaryEnable = $this->input->post('mission_ext_mission_post_summary_enable', true)) !== null)
    {
      $event['data']['mission_ext_mission_post_summary_enable'] = $summaryEnable;
    }else {
       $event['data']['mission_ext_mission_post_summary_enable'] = 0;
    }
});
