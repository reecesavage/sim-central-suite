<?php 


$this->event->listen(['location', 'view', 'data', 'main', 'sim_missions_all'], function($event){
    
       $manager =  new \nova_ext_sim_central\UrlParser();
   
      
      
     if(isset($event['data']['missions']['current']))
     {
        foreach($event['data']['missions']['current'] as $key =>$value)
        {
          $event['data']['missions']['current'][$key]['desc'] = $manager->urlparser($value['desc']);
        }
     }

      
     if(isset($event['data']['missions']['completed']))
     {
        foreach($event['data']['missions']['completed'] as $key =>$value)
        {
          $event['data']['missions']['completed'][$key]['desc'] = $manager->urlparser($value['desc']);
        }
     }


      
     if(isset($event['data']['missions']['upcoming']))
     {
        foreach($event['data']['missions']['upcoming'] as $key =>$value)
        {
          $event['data']['missions']['upcoming'][$key]['desc'] = $manager->urlparser($value['desc']);
        }
     }

      
    
     


});

