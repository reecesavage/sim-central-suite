<?php

$this->event->listen(['location', 'view', 'data', 'admin', 'manage_missions_action'], function($event){




   $id = isset($event['data']['id'])?$event['data']['id']:'';

   if(!empty($id))
   {
    $query = $this->db->get_where('missions', array('mission_id' => $id));
    $post = ($query->num_rows() > 0) ? $query->row() : false;
   }




   $json = \nova_ext_sim_central\Config::load();





  $editSummaryEnableLabel = isset($json['nova_ext_mission_post_summary']['mission_ext_mission_post_summary_enable'])
                        ? $json['nova_ext_mission_post_summary']['mission_ext_mission_post_summary_enable']['value']
                        : 'Summary Field Enable';

  switch($this->uri->segment(4)){



    default:


      $event['data']['label']['mission_ext_mission_post_summary_enable'] = $editSummaryEnableLabel;
         $event['data']['inputs']['mission_ext_mission_post_summary_enable'] = 'mission_ext_mission_post_summary_enable';
         $event['data']['value']['mission_ext_mission_post_summary_enable'] = '1';
       $event['data']['checked']['mission_ext_mission_post_summary_enable'] = $post ? $post->mission_ext_mission_post_summary_enable : '0';



  }

});

$this->event->listen(['location', 'view', 'output', 'admin', 'manage_missions_action'], function($event){
  switch($this->uri->segment(4)){
    case 'view':
      break;
    default:
    $this->config->load('extensions');
                $event['output'] .= \nova_ext_sim_central\Generator::select('[name="mission_order"]')->closest('p')
                      ->before(
                        $this->extension['nova_ext_sim_central']
                             ->view('summary_mission_form', $this->skin, 'admin', $event['data'])
                      );

 }
});
