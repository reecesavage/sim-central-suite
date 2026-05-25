<?php 

$this->event->listen(['location', 'view', 'data', 'main', 'sim_missions_one'], function($event){
  
 $manager =  new \nova_ext_sim_central\UrlParser();
   if(isset($event['data']['basic']['desc']))
     {
       $event['data']['basic']['desc']=$manager->urlparser($event['data']['basic']['desc']);
     }

        if(isset($event['data']['summary']['content']))
     {
       $event['data']['summary']['content']=$manager->urlparser($event['data']['summary']['content']);
     }
});