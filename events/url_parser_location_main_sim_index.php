<?php 


$this->event->listen(['location', 'view', 'data', 'main', 'sim_index'], function($event){

      $manager =  new \nova_ext_sim_central\UrlParser();
      
    
     if(isset($event['data']['msg_sim']))
     {
          $event['data']['msg_sim'] = $manager->urlparser($event['data']['msg_sim']);
     }


});

