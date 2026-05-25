<?php 

$this->event->listen(['location', 'view', 'data', 'main', 'main_news'], function($event){
    $manager =  new \nova_ext_sim_central\UrlParser();
     
    
      
      
     if(isset($event['data']['news']))
     {
        foreach($event['data']['news'] as $key =>$value)
        {
          $event['data']['news'][$key]['content'] = $manager->urlparser($value['content']);
        }
     }

    

});
