<?php

$this->event->listen(['location', 'view', 'data', 'admin', 'characters_index'], function($event){



      $all = $this->char->get_all_characters('all');

		$depts = $this->dept->get_all_depts('asc', '');

		if ($depts->num_rows() > 0)
		{
			foreach ($depts->result() as $d)
			{
				$data['characters'][$d->dept_id]['dept'] = $d->dept_name;
			}
		}

		$data['count'] = array(
			'active' => 0,
			'inactive' => 0,
			'pending' => 0
		);

		if ($all->num_rows() > 0)
		{


			foreach ($all->result() as $a)
			{
				if ($a->crew_type != 'npc')
				{

					if(!empty($a->display_name))
					{
                         $name = array(
						($a->crew_type != 'pending') ? $this->ranks->get_rank($a->rank, 'rank_name') : '',
						$a->display_name

					);
					}else {
						$name = array(
						($a->crew_type != 'pending') ? $this->ranks->get_rank($a->rank, 'rank_name') : '',
						$a->first_name,
						$a->last_name,
						$a->suffix
					);
					}


					$pos = $this->pos->get_position($a->position_1);

					if ($pos !== false and array_key_exists($pos->pos_dept, $data['characters']) === false)
					{
						$cdept = $this->dept->get_dept($pos->pos_dept, 'dept_parent');
					}
					else
					{
						$cdept = ($pos !== false) ? $pos->pos_dept : '';
					}

					$p = $this->user->get_user($a->user, array('status', 'email'));

					$data['characters'][$cdept]['chars'][$a->crew_type][$a->charid] = array(
						'id' => $a->charid,
						'uid' => $a->user,
						'name' => parse_name($name),
						'position_1' => ($pos !== false) ? $pos->pos_name : '',
						'position_2' => $this->pos->get_position($a->position_2, 'pos_name'),
						'pstatus' => $p['status'],
						'email' => $p['email']
					);

					++$data['count'][$a->crew_type];
				}
			}
		}

		// sort the keys
		ksort($data['characters']);

     $event['data']['characters']=$data['characters'];

});
