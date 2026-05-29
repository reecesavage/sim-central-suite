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

      // Resolve the mission this form is bound to so we only show the box when
      // that mission has the summary enabled. Existing post -> its mission;
      // new post -> the single current mission if there's only one (otherwise
      // the dropdown's first option, which the JS below keeps in sync on change).
      $missionId = ($post && !empty($post->post_mission)) ? $post->post_mission : false;
      if (!$missionId) {
        $current = $this->mis->get_all_missions('current');
        if ($current->num_rows() > 0) {
          $missionId = $current->row()->mission_id;
        }
      }

      $summaryEnabled = false;
      if ($missionId) {
        $mq = $this->db->get_where('missions', array('mission_id' => $missionId));
        $summaryEnabled = ($mq->num_rows() > 0 && $mq->row()->mission_ext_mission_post_summary_enable == 1);
      }

      $event['data']['nova_ext_mission_post_summary_enabled'] = $summaryEnabled;
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

                // Toggle the box live when the mission dropdown changes (sims
                // with more than one current mission). Single-mission sims have
                // no dropdown; their initial server-rendered state stands. Reuses
                // the suite's ordered_mission lookup (returns the mission row).
                $ajaxUrl = site_url('extensions/nova_ext_sim_central/Ajax/ordered_mission');
                $event['output'] .= "<script>\n"
                  ."(function(\$){\n"
                  ."  if (typeof \$ === 'undefined') { return; }\n"
                  ."  function scSummaryToggle(mission){\n"
                  ."    if (!mission) { return; }\n"
                  ."    \$.get('".$ajaxUrl."', { mission: mission }, function(data){\n"
                  ."      var r; try { r = JSON.parse(data); } catch(e){ return; }\n"
                  ."      if (r.status !== 'OK' || !r.post) { return; }\n"
                  ."      \$('.nova_ext_mission_post_summary').css('display', r.post.mission_ext_mission_post_summary_enable == 1 ? 'block' : 'none');\n"
                  ."    });\n"
                  ."  }\n"
                  ."  \$(function(){\n"
                  ."    \$(document).on('change', '[name=\"mission\"]', function(){ scSummaryToggle(\$(this).val()); });\n"
                  ."  });\n"
                  ."})(window.jQuery);\n"
                  ."</script>";

 }

});
