<?php

$this->event->listen(['location', 'view', 'data', 'admin', 'write_missionpost'], function($event){



  $id = (is_numeric($this->uri->segment(3))) ? $this->uri->segment(3) : false;
  $post = $id ? $this->posts->get_post($id) : null;

     $json = \nova_ext_sim_central\Config::load();


  $summaryLabel = isset($json['nova_ext_mission_post_summary']['nova_ext_mission_post_summary'])
                        ? $json['nova_ext_mission_post_summary']['nova_ext_mission_post_summary']['value']
                        : 'Summary';


  switch($this->uri->segment(4)){

       case 'view':


 if(!empty($post->post_mission))
   {
   $query = $this->db->get_where('missions', array('mission_id' => $post->post_mission));
   $model = ($query->num_rows() > 0) ? $query->row() : false;
   if(!empty($model) && $model->mission_ext_mission_post_summary_enable==1)
   {


        $event['data']['inputs']['location']['value']= "$post->post_location </p><p><kbd>$summaryLabel </kbd> $post->nova_ext_mission_post_summary";

   }
   }


       break;
    default:

      $event['data']['label']['nova_ext_mission_post_summary'] = $summaryLabel;
      $event['data']['inputs']['nova_ext_mission_post_summary'] = array(
        'name' => 'nova_ext_mission_post_summary',
        'id' => 'nova_ext_mission_post_summary',
        'rows'=>isset($json['setting']['rows'])
                        ? $json['setting']['rows']
                        : '5',
        'value' => $post ? $post->nova_ext_mission_post_summary : ''
      );


  }

});
$this->event->listen(['location', 'view', 'output', 'admin', 'write_missionpost'], function($event){
  switch($this->uri->segment(4)){
    case 'view':
      break;
    default:
                $event['output'] .= \nova_ext_sim_central\Generator::select('#content-textarea')->closest('p')
                      ->before(
                        $this->extension['nova_ext_sim_central']
                             ->view('summary_form', $this->skin, 'admin', $event['data'])
                      );

 }

});
