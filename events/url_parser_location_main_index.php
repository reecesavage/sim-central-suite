<?php 

$this->event->listen(['location', 'view', 'data', 'main', 'main_index'], function($event){
    $manager =  new \nova_ext_sim_central\UrlParser();
     
     if(isset($event['data']['msg_welcome']))
     {
       $event['data']['msg_welcome']=$manager->urlparser($event['data']['msg_welcome']);
     }
      
      
     if(isset($event['data']['lists']['news']))
     {
        foreach($event['data']['lists']['news'] as $key =>$value)
        {
          $event['data']['lists']['news'][$key]['content'] = $manager->urlparser($value['content']);
        }
     }

     if(isset($event['data']['lists']['logs']))
     {
        foreach($event['data']['lists']['logs'] as $key =>$value)
        {
          $event['data']['lists']['logs'][$key]['content'] = $manager->urlparser($value['content']);
        }
     }


     if(isset($event['data']['lists']['posts']))
     {
        foreach($event['data']['lists']['posts'] as $key =>$value)
        {
          $event['data']['lists']['posts'][$key]['content'] = $manager->urlparser($value['content']);
        }
     }

});
