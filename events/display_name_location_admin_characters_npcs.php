<?php

$this->event->listen(['location', 'view', 'data', 'admin', 'characters_npcs'], function($event){

     $level = Auth::get_access_level();
     switch ($level)
		{
			case 1:
				// get the user's main character information
				$me = $this->char->get_character($this->session->userdata('main_char'));

				// grab the department their primary position is in
				$dept = $this->pos->get_position($me->position_1, 'pos_dept');

				// get an array of department positions
				$positions = $this->pos->get_dept_positions($dept, 'y', 'array');

				// get the department info
				$depts = $this->dept->get_dept($dept);

				// build the array of departments
				$data['characters'][$depts->dept_id]['dept'] = $depts->dept_name;

				// get all the NPCs
				$all = $this->char->get_all_characters('npc');
			break;

			case 2:
				// get the user's main character information
				$me = $this->char->get_character($this->session->userdata('main_char'));

				// grab the department their primary position is in
				$dept[] = $this->pos->get_position($me->position_1, 'pos_dept');

				if ( ! empty($me->position_2))
				{
					$dept[] = $this->pos->get_position($me->position_2, 'pos_dept');
				}

				// set up an empty positions array
				$positions = array();

				foreach ($dept as $d)
				{
					// pull the positions
					$array = $this->pos->get_dept_positions($d, 'y', 'array');

					// merge the array onto what's already there
					$positions = array_merge($positions, $array);

					// get the department info
					$depts = $this->dept->get_dept($d);

					// build the array of departments
					$data['characters'][$d]['dept'] = $depts->dept_name;
				}

				// get all the NPCs
				$all = $this->char->get_all_characters('npc');
			break;

			case 3:
				// get all the departments
				$depts = $this->dept->get_all_depts('asc', '');

				// put the departments into an array
				if ($depts->num_rows() > 0)
				{
					foreach ($depts->result() as $d)
					{
						$data['characters'][$d->dept_id]['dept'] = $d->dept_name;
					}
				}

				// get all the NPCs
				$all = $this->char->get_all_characters('npc');
			break;
		}

		$data['count'] = 0;

		if ($all->num_rows() > 0)
		{
			foreach ($all->result() as $a)
			{
				// build an array of their name
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

				if ($level == 1)
				{
					$cdept = $dept;
				}
				else
				{
					if ($pos !== false and array_key_exists($pos->pos_dept, $data['characters']) === false)
					{
						$cdept = $this->dept->get_dept($pos->pos_dept, 'dept_parent');
					}
					else
					{
						$cdept = ($pos !== false) ? $pos->pos_dept : '';
					}
				}

				// get the user info
				$p = $this->user->get_user($a->user, array('status', 'email'));

				if (
					(($level == 1 or $level == 2) and (in_array($a->position_1, $positions) or in_array($a->position_2, $positions))) or
					($level == 3)
				)
				{
					$data['characters'][$cdept]['chars'][$a->charid] = array(
						'id' => $a->charid,
						'uid' => $a->user,
						'name' => parse_name($name),
						'position_1' => ($pos !== false) ? $pos->pos_name : '',
						'position_2' => ( ! empty($a->position_2)) ? $this->pos->get_position($a->position_2, 'pos_name') : '',
						'pstatus' => $p['status'],
						'email' => $p['email']
					);
				}

				++$data['count'];
			}
		}

		ksort($data['characters']);

		 $event['data']['characters']=$data['characters'];

	});
