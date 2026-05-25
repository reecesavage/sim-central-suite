<?php 

$this->event->listen(['location', 'view', 'data', 'main', 'main_credits'], function($event){
    $manager =  new \nova_ext_sim_central\UrlParser();
     
    
     if(isset($event['data']['msg_credits']))
     {
          $event['data']['msg_credits'] = $manager->urlparser($event['data']['msg_credits']);
     }

});
