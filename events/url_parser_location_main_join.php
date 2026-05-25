<?php 


$this->event->listen(['location', 'view', 'data', 'main', 'main_join_1'], function($event){

      
      $manager =  new \nova_ext_sim_central\UrlParser();
     
    
     if(isset($event['data']['msg']))
     {
          $event['data']['msg'] = $manager->urlparser($event['data']['msg']);
     }


});

