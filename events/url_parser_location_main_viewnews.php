<?php 


$this->event->listen(['location', 'view', 'data', 'main', 'main_viewnews'], function($event){
      

     
       $manager =  new \nova_ext_sim_central\UrlParser();
   
     
     if(isset($event['data']['content']))
     {
          $event['data']['content'] = $manager->urlparser($event['data']['content']);
     }
    
});

