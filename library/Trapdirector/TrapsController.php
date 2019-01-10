<?php

namespace Icinga\Module\Trapdirector;

use Icinga\Web\Controller;
use Icinga\Web\Url;

use Icinga\Data\Db;
use Icinga\Data\Paginatable;
use Icinga\Data\Db\DbConnection as IcingaDbConnection;

use Icinga\Application\Modules\Module;

use Exception;
use Icinga\Exception\ConfigurationError;
use Icinga\Exception\QueryException;
use Icinga\Exception\ProgrammingError;

use Icinga\Module\Trapdirector\Config\TrapModuleConfig;
use Icinga\Module\Trapdirector\Tables\TrapTableList;
use Icinga\Module\Trapdirector\Tables\HandlerTableList;
use Icinga\Module\Trapdirector\Config\MIBLoader;

use Zend_Db_Expr;
use Zend_Db_Select;

class TrapsController extends Controller
{
	protected $moduleConfig;  	//< TrapModuleConfig instance
	protected $trapTableList; 	//< TrapTableList 
	protected $handlerTableList; 	//< HandlerTableList instance
	protected $trapDB;			//< Trap database
	protected $icingaDB;		//< Icinga IDO database;
	protected $MIBData; 		//< MIBLoader class
		
	/** Get instance of TrapModuleConfig class
	*	@return TrapModuleConfig
	*/
	public function getModuleConfig() {
		if ($this->moduleConfig == Null) {
			$db_prefix=$this->Config()->get('config', 'database_prefix');
			if ($db_prefix === null) 
			{
				// TODO : send message and display
				$this->redirectNow('trapdirector/settings?message=No database prefix');
			}
			$this->moduleConfig = new TrapModuleConfig($db_prefix);
		}
		return $this->moduleConfig;
	}
	
	public function getTrapListTable() {
		if ($this->trapTableList == Null) {
			$this->trapTableList = new TrapTableList();
			$this->trapTableList->setConfig($this->getModuleConfig());
		}
		return $this->trapTableList;
	}
	
	public function getHandlerListTable() {
		if ($this->handlerTableList == Null) {
			$this->handlerTableList = new HandlerTableList();
			$this->handlerTableList->setConfig($this->getModuleConfig());
		}
		return $this->handlerTableList;
	}	
	
	/**	Get Database connexion
	*	@param $DBname string DB name in resource.ini_ge
	*	@param $test bool if set to true, returns error code and not database
	*	@param $test_version	bool if set to flase, does not test database version of trapDB
	*	@return IcingaDbConnection or int
	*/
	public function getDbByName($DBname,$test=false,$test_version=true)
	{
		try 
		{
			$dbconn = IcingaDbConnection::fromResourceName($DBname);
		} 
		catch (Exception $e)
		{
			if ($test) return array(2,$DBname);
			$this->redirectNow('trapdirector/settings?dberror=2');
			return null;
		}
		if ($test_version == true) {
			try 
			{
				$db=$dbconn->getConnection();
			}
			catch (Exception $e) 
			{
				if ($test) return array(3,$DBname,$e->getMessage());
				$this->redirectNow('trapdirector/settings?dberror=3');
				return null;
			}
			try
			{
				$query = $db->select()
					->from($this->getModuleConfig()->getDbConfigTableName(),'value')
					->where('name=\'db_version\'');
				$version=$db->fetchRow($query);
				if ( ($version == null) || ! property_exists($version,'value') )
				{
					if ($test) return array(4,$DBname);
					$this->redirectNow('trapdirector/settings?dberror=4');
					return null;
				}
				if ($version->value < $this->getModuleConfig()->getDbMinVersion()) 
				{
					if ($test) return array(5,$version->value,$this->getModuleConfig()->getDbMinVersion());
					$this->redirectNow('trapdirector/settings?dberror=5');
					return null;
				}
			}
			catch (Exception $e) 
			{
				if ($test) return array(3,$DBname,$e->getMessage());
				$this->redirectNow('trapdirector/settings?dberror=4');
				return null;
			}
		}
		if ($test) return array(0,'');
		return $dbconn;
	}

	public function getDb($test=false)
	{
		if ($this->trapDB != null && $test = false) return $this->trapDB;
		
		$dbresource=$this->Config()->get('config', 'database');
		
		if ( ! $dbresource )
		{	
			if ($test) return array(1,'');
			$this->redirectNow('trapdirector/settings?dberror=1');
			return null;
		}
		$retDB=$this->getDbByName($dbresource,$test,true);
		if ($test == true) return $retDB;
		$this->trapDB=$retDB;
		return $this->trapDB;
	}
	
	public function getIdoDb($test=false)
	{
		if ($this->icingaDB != null && $test = false) return $this->icingaDB;
		// TODO : get ido database by config or directly in icingaweb2 config
		$dbresource=$this->Config()->get('config', 'IDOdatabase');;

		$this->icingaDB=$this->getDbByName($dbresource,$test,false);
		if ($test == true) return 0;
		return $this->icingaDB;
	}
	
    protected function applyPaginationLimits(Paginatable $paginatable, $limit = 25, $offset = null)
    {
        $limit = $this->params->get('limit', $limit);
        $page = $this->params->get('page', $offset);

        $paginatable->limit($limit, $page > 0 ? ($page - 1) * $limit : 0);

        return $paginatable;
    }	
	
	public function displayExitError($source,$message)
	{	// TODO : check better ways to transmit data (with POST ?)
		$this->redirectNow('trapdirector/error?source='.$source.'&message='.$message);
	}
	
	protected function checkReadPermission()
	{
        if (! $this->Auth()->hasPermission('trapdirector/view')) {
            $this->displayExitError('Permissions','No permission fo view content');
        }		
	}

	protected function checkConfigPermission()
	{
        if (! $this->Auth()->hasPermission('trapdirector/config')) {
            $this->displayExitError('Permissions','No permission fo configure');
        }		
	}
	
	protected function checkModuleConfigPermission()
	{
        if (! $this->Auth()->hasPermission('trapdirector/module_config')) {
            $this->displayExitError('Permissions','No permission fo configure module');
        }		
	}

	/************************** MIB related **************************/
	
	/** Get MIBLoader class
	*	@return MIBLoader class
	*/
	protected function getMIB()
	{
		if ($this->MIBData == null)
		{
			//TODO : path in config module 
			$this->MIBData=new MIBLoader($this->Module()->getBaseDir().'/mibs/traplist.txt');
		}
		return $this->MIBData;
	}	
	
	/**************************  Database queries *******************/
	
	/** Get host(s) by IP (v4 or v6) or by name in IDO database
	*	does not catch exceptions
	*	@return array of objects ( name, id (object_id), display_name)
	*/
	protected function getHostByIP($ip) 
	{
		// select a.name1, b.display_name from icinga.icinga_objects AS a , icinga.icinga_hosts AS b WHERE (b.address = '192.168.56.101' OR b.address6= '123456') and b.host_object_id=a.object_id
		$db = $this->getIdoDb()->getConnection();
		// TODO : check for SQL injections
		$query=$db->select()
				->from(
					array('a' => 'icinga_objects'),
					array('name' => 'a.name1','id' => 'object_id'))
				->join(
					array('b' => 'icinga_hosts'),
					'b.host_object_id=a.object_id',
					array('display_name' => 'b.display_name'))
				->where("(b.address LIKE '%".$ip."%' OR b.address6 LIKE '%".$ip."%' OR a.name1 LIKE '%".$ip."%' OR b.display_name LIKE '%".$ip."%') and a.is_active = 1");
		return $db->fetchAll($query);
	}

	/** Get host IP (v4 and v6) by name in IDO database
	*	does not catch exceptions
	*	@return array ( name, display_name, ip4, ip6)
	*/
	protected function getHostInfoByID($id) 
	{
		if (!preg_match('/^[0-9]+$/',$id)) { throw new Exception('Invalid id');  }
		$db = $this->getIdoDb()->getConnection();
		$query=$db->select()
				->from(
					array('a' => 'icinga_objects'),
					array('name' => 'a.name1'))
				->join(
					array('b' => 'icinga_hosts'),
					'b.host_object_id=a.object_id',
					array('ip4' => 'b.address', 'ip6' => 'b.address6', 'display_name' => 'b.display_name'))
				->where("a.object_id = '".$id."'");
		return $db->fetchRow($query);
	}

	
	/** Get host by objectid  in IDO database
	*	does not catch exceptions
	*	@return array of objects ( id, name, display_name, ip, ip6,  )
	*/
	protected function getHostByObjectID($id) 
	{
		if (!preg_match('/^[0-9]+$/',$id)) { throw new Exception('Invalid id');  }
		$db = $this->getIdoDb()->getConnection();
		$query=$db->select()
				->from(
					array('a' => 'icinga_objects'),
					array('name' => 'a.name1','id' => 'a.object_id'))
				->join(
					array('b' => 'icinga_hosts'),
					'b.host_object_id=a.object_id',
					array('display_name' => 'b.display_name' , 'ip' => 'b.address', 'ip6' => 'b.address6'))
				->where('a.object_id = ?',$id);
		return $db->fetchRow($query);
	}	
	
	/** Get services from object ( host_object_id) in IDO database
	*	does not catch exceptions
	*	@param $id	int object_id
	*	@return array display_name (of service), service_object_id
	*/
	protected function getServicesByHostid($id) 
	{
		// select a.name1, b.display_name from icinga.icinga_objects AS a , icinga.icinga_hosts AS b WHERE (b.address = '192.168.56.101' OR b.address6= '123456') and b.host_object_id=a.object_id
		if (!preg_match('/^[0-9]+$/',$id)) { throw new Exception('Invalid id');  }
		$db = $this->getIdoDb()->getConnection();
		if ($id != null)
		{
			$query=$db->select()
					->from(
						array('s' => 'icinga_services'),
						array('name' => 's.display_name','id' => 's.service_object_id'))
					->join(
						array('a' => 'icinga_objects'),
						's.service_object_id=a.object_id',
						'is_active')
					->where('s.host_object_id='.$id.' AND a.is_active = 1');
		}

		return $db->fetchAll($query);
	}	

	/** Get services object id by name in IDO database
	*	does not catch exceptions
	*	@param $name service name
	*	@return int  service id
	*/
	protected function getServiceIDByName($name) 
	{
		$db = $this->getIdoDb()->getConnection();
		if ($name != null)
		{
			$query=$db->select()
					->from(
						array('s' => 'icinga_services'),
						array('name' => 's.display_name','id' => 's.service_object_id'))
					->join(
						array('a' => 'icinga_objects'),
						's.service_object_id=a.object_id',
						'is_active')
					->where('a.name2=\''.$name.'\' AND a.is_active = 1');
		}
		return $db->fetchAll($query);
	}
	
	/** Get object name from object_id  in IDO database
	*	does not catch exceptions
	*	@param $id	int object_id (default to null, used first if not null)
	*	@return array name1 (host) name2 (service)
	*/
	protected function getObjectNameByid($id) 
	{
		// select a.name1, b.display_name from icinga.icinga_objects AS a , icinga.icinga_hosts AS b WHERE (b.address = '192.168.56.101' OR b.address6= '123456') and b.host_object_id=a.object_id
		if (!preg_match('/^[0-9]+$/',$id)) { throw new Exception('Invalid id');  }
		$db = $this->getIdoDb()->getConnection();
		$query=$db->select()
				->from(
					array('a' => 'icinga_objects'),
					array('name1' => 'a.name1','name2' => 'a.name2'))
				->where('a.object_id='.$id.' AND a.is_active = 1');

		return $db->fetchRow($query);
	}		

	/** Add handler rule in traps DB
	*	@param array(<db item>=><value>)
	*	@return int inserted id
	*/
	protected function addHandlerRule($params)
	{
		// TODO Check for rule consistency and get user name
		$db = $this->getDb()->getConnection();
		// Add last modified date = creation date and username
		$params['created'] = new Zend_Db_Expr('CURRENT_TIMESTAMP()');
		$params['modified'] = new 	Zend_Db_Expr('CURRENT_TIMESTAMP()');
		$params['modifier'] ='me' ;
		
		$query=$db->insert(
			$this->getModuleConfig()->getTrapRuleName(),
			$params
		);
		return $db->lastInsertId();
	}	

	/** Update handler rule in traps DB
	*	@param array(<db item>=><value>)
	*	@return affected rows
	*/
	protected function updateHandlerRule($params,$ruleID)
	{
		// TODO Check for rule consistency and get user name
		$db = $this->getDb()->getConnection();
		// Add last modified date = creation date and username
		$params['modified'] = new 	Zend_Db_Expr('CURRENT_TIMESTAMP()');
		$params['modifier'] ='me' ;
		
		$numRows=$db->update(
			$this->getModuleConfig()->getTrapRuleName(),
			$params,
			'id='.$ruleID
		);
		return $numRows;
	}	
	/** Delete rule by id
	*	@param int rule id
	*/
	protected function deleteRule($ruleID)
	{
		if (!preg_match('/^[0-9]+$/',$ruleID)) { throw new Exception('Invalid id');  }
		$db = $this->getDb()->getConnection();
		
		$query=$db->delete(
			$this->getModuleConfig()->getTrapRuleName(),
			'id='.$ruleID
		);
		return $query;		
	}
}

		/*
		//$query = $db->select()
            //->distinct()
            //->from('traps_received', array('date_received,source_ip,trap_oid'))
            //->where('varname = ?', 'location')
            //->order('date_received');
        //print_r($db->fetchCol($query));
		//print_r($db->fetchAll($query));
		//print_r($db->fetchRow($query));
		
		*/