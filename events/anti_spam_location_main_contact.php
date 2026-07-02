<?php

$this->event->listen(['location', 'view', 'data', 'main', 'main_contact'], function($event){

         $this->db->order_by('setting_id', 'RANDOM');
         $this->db->limit(1);
         $this->db->where('setting_key','question');
         $query=   $this->db->get('settings');
         $model = ($query->num_rows() > 0) ? $query->row() : false;
         if(!empty($model))
         {
            $jsonDecode=json_decode($model->setting_value,true);


            $event['data']['label']['nova_ext_anti_spam_questions_question']=$jsonDecode['question'];
             $event['data']['inputs']['nova_ext_anti_spam_questions_setting_id']=$model->setting_id;


      // No HTML `required` attribute - see the join event for why (hidden
      // required controls silently block submits on tabbed skins). The
      // shared anti_spam_form view carries a JS submit guard, and
      // AntiSpam::shouldBlock() enforces server-side.
      $event['data']['inputs']['nova_ext_anti_spam_questions_answer'] = array(
        'name' => 'nova_ext_anti_spam_questions_answer',
        'id' => 'nova_ext_anti_spam_questions_answer',
        'aria-required' => 'true',
      );


         }


});
$this->event->listen(['location', 'view', 'output', 'main', 'main_contact'], function($event){



  switch($this->uri->segment(4)){
    case 'view':
      break;
    default:

                $event['output'] .= \nova_ext_sim_central\Generator::select('[name="message"]')->closest('p')
                      ->before(
                        $this->extension['nova_ext_sim_central']
                             ->view('anti_spam_form', $this->skin, 'main', $event['data'])
                      );

 }
});
