<?php

/*
    +-----------------------------------------------------------------------------+
    | ILIAS open source                                                           |
   	+-----------------------------------------------------------------------------+
    | Copyright (c) 1998-2001 ILIAS open source, University of Cologne            |
    |                                                                             |
    | This program is free software; you can redistribute it and/or               |
    | modify it under the terms of the GNU General Public License                 |
    | as published by the Free Software Foundation; either version 2              |
    | of the License, or (at your option) any later version.                      |
    |                                                                             |
    | This program is distributed in the hope that it will be useful,             |
    | but WITHOUT ANY WARRANTY; without even the implied warranty of              |
    | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the               |
    | GNU General Public License for more details.                                |
    |                                                                             |
    | You should have received a copy of the GNU General Public License           |
    | along with this program; if not, write to the Free Software                 |
    | Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA. |
    +-----------------------------------------------------------------------------+
*/

/**
* Handles conditions for accesses to different ILIAS objects
*
* A condition consists of four elements:
* - a trigger object, e.g. a test or a survey question
* - an operator, e.g. "=", "<", "passed"
* - an (optional) value, e.g. "5"
* - a target object, e.g. a learning module
*
* If a condition is fulfilled for a certain user, (s)he may access
* the target object. This first implementation handles only one access
* type per object, which is usually "read" access. A possible
* future extension may implement different access types.
*
* The condition data is stored in the database table "condition"
* (Note: This table must not be accessed directly from other classes.
* The data should be accessed via the interface of class ilCondition.)
*   cond_id					INT			condition id
*   trigger_obj_type		VARCHAR(10)	"crs" | "tst" | "qst", ...
*   trigger_id				INT			obj id of trigger object
*   operator				varchar(10  "=", "<", ">", ">=", "<=", "passed", "contains", ...
*   value					VARCHAR(10) optional value
*   target_obj_type			VARCHAR(10)	"lm" | "frm" | "st" | "pg", ...
*   target_id				object or reference id of target object
*
* Trigger objects are always stored with their object id (if a test has been
* passed by a user, he doesn't need to repeat it in other contexts. But
* target objects are usually stored with their reference id if available,
* otherwise, if they are non-referenced objects (e.g. (survey) questions)
* they are stored with their object id.
*
* Stefan Meyer 10-08-2004
* In addition we store the ref_id of the trigger object to allow the target object to link to the triggered object.
* But it's not possible to assign two or more linked (same obj_id) triggered objects to a target object
*
* Examples:
*
* Learning module 5 may only be accessed, if test 6 has been passed:
*   trigger_obj_type		"tst"
*   trigger_id				6 (object id)
*   trigger_ref_id			117
*   operator				"passed"
*   value
*   target_obj_type			"lm"
*   target_id				5 (reference id)
*
* Survey question 10 should only be presented, if survey question 8
* is answered with a value greater than 4.
*   trigger_obj_type		"qst"
*   trigger_id				8 (question (instance) object id)
*   trigger_ref_id			117
*   operator				">"
*   value					"4"
*   target_obj_type			"lm"
*   target_id				10 (question (instance) object id)
*
*
*
* @author Alex Killing <alex.killing@gmx.de>
* @version $Id$
*/
class ilConditionHandler
{
	var $db;
	var $lng;
	

	var $error_message;

	var $target_id;
	var $target_type;
	var $trigger_id;
	var $trigger_type;
	var $operator;
	var $value;

	var $conditions;


	/**
	* constructor
	* @access	public
	*/
	function ilConditionHandler()
	{
		global $ilDB,$lng;

		$this->db =& $ilDB;
		$this->lng =& $lng;
	}

	// SET GET
	function setErrorMessage($a_msg)
	{
		$this->error_message = $a_msg;
	}
	function getErrorMessage()
	{
		return $this->error_message;
	}

	function setTargetRefId($a_target_ref_id)
	{
		return $this->target_id = $a_target_ref_id;
	}
	function getTargetRefId()
	{
		return $this->target_id;
	}
	function setTriggerRefId($a_trigger_ref_id)
	{
		return $this->trigger_id = $a_trigger_ref_id;
	}
	function getTriggerRefId()
	{
		return $this->trigger_id;
	}
	function setOperator($a_operator)
	{
		return $this->operator = $a_operator;
	}
	function getOperator()
	{
		return $this->operator;
	}
	function setValue($a_value)
	{
		return $this->value = $a_value;
	}
	function getValue()
	{
		return $this->value;
	}


	/**
	* get all possible trigger types
	* NOT STATIC
	* @access	public
	*/
	function getTriggerTypes()
	{
		return array('crs','exc','frm');
	}

	/**
	* store new condition in database
	* NOT STATIC
	* @access	public
	*/
	function storeCondition()
	{
		if(!$tmp_target =& ilObjectFactory::getInstanceByRefId($this->getTargetRefId(),false))
		{
			echo 'ilConditionHandler: Object does not exist';
		}
		if(!$tmp_trigger =& ilObjectFactory::getInstanceByRefId($this->getTriggerRefId(),false))
		{
			echo 'ilConditionHandler: Object does not exist';
		}

		// first insert, then validate: it's easier to check for circles if the new condition is in the db table
		$query = 'INSERT INTO conditions '.
			"VALUES('0','".$this->getTargetRefId()."','".$tmp_target->getId()."','".$tmp_target->getType()."','".
			$this->getTriggerRefId()."','".$tmp_trigger->getId()."','".$tmp_trigger->getType()."','".
			$this->getOperator()."','".$this->getValue()."')";

		$res = $this->db->query($query);

		$query = "SELECT LAST_INSERT_ID() AS last FROM conditions";
		$res = $this->db->query($query);
		while($row = $res->fetchRow(DB_FETCHMODE_OBJECT))
		{
			$last_id = $row->last;
		}
		
		if(!$this->validate())
		{
			$this->deleteCondition($last_id);
			return false;
		}
		return true;
	}

	/**
	* update condition
	*/
	function updateCondition($a_id)
	{
		$query = "UPDATE conditions SET ".
			"operator = '".$this->getOperator()."', ".
			"value = '".$this->getValue()."' ".
			"WHERE id = '".$a_id."'";

		$res = $this->db->query($query);

		return true;
	}

	/**
	* delete condition
	*/
	function deleteCondition($a_id)
	{
		global $ilDB;

		$query = "DELETE FROM conditions ".
			"WHERE id = '".$a_id."'";

		$res = $ilDB->query($query);

		return true;
	}

	/**
	* get all conditions of trigger object
	* @static
	*/
	function _getConditionsOfTrigger($a_trigger_obj_type, $a_trigger_id)
	{
		// TODO

		return $conditions ? $conditions : array();
	}

	/**
	* get all conditions of target object
	* @static
	*/
	function _getConditionsOfTarget($a_target_obj_id)
	{
		global $ilDB;

		$query = "SELECT * FROM conditions ".
			"WHERE target_obj_id = '".$a_target_obj_id."'";

		$res = $ilDB->query($query);
		while($row = $res->fetchRow(DB_FETCHMODE_OBJECT))
		{
			$tmp_array['id']			= $row->id;
			$tmp_array['target_ref_id'] = $row->target_ref_id;
			$tmp_array['target_obj_id'] = $row->target_obj_id;
			$tmp_array['target_type']	= $row->target_type;
			$tmp_array['trigger_ref_id'] = $row->trigger_ref_id;
			$tmp_array['trigger_obj_id'] = $row->trigger_obj_id;
			$tmp_array['trigger_type']	= $row->trigger_type;
			$tmp_array['operator']		= $row->operator;
			$tmp_array['value']			= $row->value;

			$conditions[] = $tmp_array;
			unset($tmp_array);
		}
		
		return $conditions ? $conditions : array();
	}

	function _getCondition($a_id)
	{
		global $ilDB;

		$query = "SELECT * FROM conditions ".
			"WHERE id = '".$a_id."'";

		$res = $ilDB->query($query);
		while($row = $res->fetchRow(DB_FETCHMODE_OBJECT))
		{
			$tmp_array['id']			= $row->id;
			$tmp_array['target_ref_id'] = $row->target_ref_id;
			$tmp_array['target_obj_id'] = $row->target_obj_id;
			$tmp_array['target_type']	= $row->target_type;
			$tmp_array['trigger_ref_id'] = $row->trigger_ref_id;
			$tmp_array['trigger_obj_id'] = $row->trigger_obj_id;
			$tmp_array['trigger_type']	= $row->trigger_type;
			$tmp_array['operator']		= $row->operator;
			$tmp_array['value']			= $row->value;

			return $tmp_array;
		}
		return false;
	}



	/**
	* checks wether a single condition is fulfilled
	* every trigger object type must implement a static method
	* _checkCondition($a_operator, $a_value)
	*/
	function _checkCondition($a_id)
	{
		switch ($a_trigger_type)
		{
			case "tst":
				return ilObjTest::_checkCondition($a_operator, $a_value);
				break;

			case "qst":
				return ilObjCourse::_checkCondition($a_operator, $a_value);

			case "crs":
				return ilObjCourse::_checkCondition($a_operator, $a_value);

		}

	}

	/**
	* checks wether all conditions of a target object are fulfilled
	*/
	function _checkAllConditionsOfTarget($a_target_obj_type, $a_target_id)
	{
		return true;
	}

	// PRIVATE
	function validate()
	{
		// check if obj_id is already assigned
		$trigger_obj =& ilObjectFactory::getInstanceByRefId($this->getTriggerRefId());
		$target_obj =& ilObjectFactory::getInstanceByRefId($this->getTargetRefId());
		

		$query = "SELECT * FROM conditions WHERE ".
			"trigger_obj_id = '".$trigger_obj->getId()."' ".
			"AND target_obj_id = '".$target_obj->getId()."'";

		$res = $this->db->query($query);
		if($res->numRows() > 1)
		{
			$this->setErrorMessage($this->lng->txt('condition_already_assigned'));

			unset($trigger_obj);
			unset($target_obj);
			return false;
		}

		// check for circle
		$this->target_obj_id = $target_obj->getId();
		if($this->checkCircle($target_obj->getId()))
		{
			$this->setErrorMessage($this->lng->txt('condition_circle_created'));
			
			unset($trigger_obj);
			unset($target_obj);
			return false;
		}			
		return true;
	}

	function checkCircle($a_obj_id)
	{
		foreach(ilConditionHandler::_getConditionsOfTarget($a_obj_id) as $condition)
		{
			if($condition['trigger_obj_id'] == $this->target_obj_id)
			{
				$this->circle = true;
				break;
			}
			else
			{
				$this->checkCircle($condition['trigger_obj_id']);
			}
		}
		return $this->circle;
	}
}

?>
