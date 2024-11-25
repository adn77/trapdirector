<?php

namespace Icinga\Module\TrapDirector\Controllers;

use Icinga\Date\DateFormatter;

use Icinga\Web\Url;

use Exception;


use Icinga\Module\Trapdirector\TrapsController;

/** 
*/
class ReceivedController extends TrapsController
{
	/**
	 * List traps by date
	 */
	public function indexAction()
	{	
		$this->checkReadPermission();
		$this->prepareTabs()->activate('traps');

		$dbConn = $this->getUIDatabase()->getDb();
		if ($dbConn === null) throw new \ErrorException('uncatched db error');
		$this->getTrapListTable()->setConnection($dbConn);
		
		// Apply pagination limits
		$this->view->table=$this->applyPaginationLimits($this->getTrapListTable(),$this->getModuleConfig()->itemListDisplay());		
		
		// Set Filter
		//$postData=$this->getRequest()->getPost();
		$filter=array();
		$filter['q']=$this->params->get('q');//(isset($postData['q']))?$postData['q']:'';
		$filter['done']=$this->params->get('done');
		$this->view->filter=$filter;
		$this->view->table->updateFilter(Url::fromRequest(),$filter);
		
		//$this->view->filterEditor = $this->getTrapListTable()->getFilterEditor($this->getRequest());

	}

	/** 
	*	Trap detail page
	*/	
	public function trapdetailAction() 
	{
		
		$this->checkReadPermission();
		// set up tab
		$this->getTabs()->add('get',array(
			'active'	=> true,
			'label'		=> $this->translate('Detailed status'),
			'url'		=> Url::fromRequest()
		));
		// get id
		$trapid=$this->params->get('id');
		$this->view->trapid=$trapid;
		$queryArray=$this->getModuleConfig()->trapDetailQuery();
		
		$dbConn = $this->getUIDatabase()->getDbConn();
		if ($dbConn === null) throw new \ErrorException('uncatched db error');
		
		// URL to add a handler
		$this->view->addHandlerUrl=Url::fromPath(
			$this->getModuleConfig()->urlPath() . '/handler/add',
			array('fromid' => $trapid));
		// ***************  Get main data
		// extract columns and titles;
		$elmts=NULL;
		foreach ($queryArray as $key => $val) {
			$elmts[$key]=$val[1];
		}
		
		// Do DB query for trap. 
		try
		{
		    $query = $dbConn->select()
				->from($this->moduleConfig->getTrapTableName(),$elmts)
				->where('id=?',$trapid);
				$trapDetail=$dbConn->fetchRow($query);
			if ( $trapDetail == null) throw new Exception('No traps was found with id = '.$trapid);
		}
		catch (Exception $e)
		{
			$this->displayExitError('Trap detail',$e->getMessage());
			return;
		}

		// Store result in array (with Titles).
		foreach ($queryArray as $key => $val) {
			if ($key == 'timestamp') {
				$cval=DateFormatter::formatDateTime($trapDetail->$key);
			} else {
				$cval=$trapDetail->$key;
			}
			array_push($queryArray[$key],$cval);
		}
		$this->view->rowset = $queryArray;

		// **************   Check for additionnal data
		
		// extract columns and titles;
		$queryArrayData=$this->getModuleConfig()->trapDataDetailQuery();
		$data_elmts=NULL;
		foreach ($queryArrayData as $key => $val) {
			$data_elmts[$key]=$val[1];
		}
		try
		{		
		    $query = $dbConn->select()
				->from($this->moduleConfig->getTrapDataTableName(),$data_elmts)
				->where('trap_id=?',$trapid);
			$trapDetail=$dbConn->fetchAll($query);
		}
		catch (Exception $e)
		{
			$this->displayExitError('Trap detail',$e->getMessage());
		}
		// TODO : code this in a better & simpler way
		if ($trapDetail == null ) 
		{
			$this->view->data=false;
		}
		else
		{
			$this->view->data=true;
			// Store result in array.
			$trapval=array();
			foreach ($trapDetail as $key => $val) 
			{	
				$trapval[$key]=array();
				foreach (array_keys($queryArrayData) as $vkey ) 
				{
					array_push($trapval[$key],$val->$vkey);
				}
			}
			$this->view->data_val=$trapval;
			$this->view->data_title=$queryArrayData;
		}
	}

	/**
	 * List traps by hosts
	 */
	public function hostsAction()
	{
	    $this->checkReadPermission();
	    $this->prepareTabs()->activate('hosts');
	    
	    $dbConn = $this->getUIDatabase()->getDb();
	    if ($dbConn === null) throw new \ErrorException('uncatched db error');
	    
	    $this->getTrapHostListTable()->setConnection($dbConn);
	    
	    // Apply pagination limits
	    $this->view->table=$this->applyPaginationLimits($this->getTrapHostListTable(),$this->getModuleConfig()->itemListDisplay());
	    
	    // Set Filter
	    //$postData=$this->getRequest()->getPost();
	    $filter=array();
	    $filter['q']=$this->params->get('q');//(isset($postData['q']))?$postData['q']:'';
	    $filter['done']=$this->params->get('done');
	    $this->view->filter=$filter;
	    $this->view->table->updateFilter(Url::fromRequest(),$filter);
	}
	
	public function deleteAction()
	{
		$this->checkConfigPermission();
		$this->prepareTabs()->activate('delete');
				
		return;
	}
	
	/**
	 * Create tabs for /received
	 * @return object tabs
	 */
	protected function prepareTabs()
	{
		return $this->getTabs()->add('traps', array(
			'label'	=> $this->translate('Traps'),
			'url'   => $this->getModuleConfig()->urlPath() . '/received')
		    )
		    ->add('hosts', array(
		        'label' => $this->translate('Hosts'),
		        'url'   => $this->getModuleConfig()->urlPath() . '/received/hosts')
		    )
		    ->add('delete', array(
			'label' => $this->translate('Delete'),
			'url'   => $this->getModuleConfig()->urlPath() . '/received/delete')
		  );
	} 

	/**
	 * Helper to count / delete lines
	 * POST params : 
	 * - OID : partial oid of traps (if empty not used in filter)
	 * - IP : IP or partial IP of host (if empty not used in filter)
	 * - action : 
	 *     count : return JSON : status:OK, count : number of lines selected by filter
	 *     delete : delete traps selected by filter
	 */
	public function deletelinesAction()
	{
		$postData=$this->getRequest()->getPost();
		if (isset($postData['IP']) && isset($postData['OID']) && isset($postData['action']))
		{
			$ip=$postData['IP'];
			$oid=$postData['OID'];
			$action=$postData['action'];
		}
		else
		{
			$this->_helper->json(array('status'=>'Missing variables'));
			return;
		}
		if ($action =="count")
		{
			$this->_helper->json(array('status'=>'OK','count'=>$this->getUIDatabase()->countTrap($ip,$oid)));
			return;
		}
		if ($action =="delete")
		{
		    $this->_helper->json(array('status'=>'OK','count'=>$this->getUIDatabase()->deleteTrap($ip,$oid)));
			return;
		}		
		$this->_helper->json(array('status'=>'unknown action'));
	}
	
}
