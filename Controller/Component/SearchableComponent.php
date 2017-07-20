<?php
App::uses('Component', 'Controller');

class SearchableComponent extends Component 
{

	public $components = array('Session', 'Search.Prg', 'RequestHandler');
	public $Controller = null;
	
	// defaults
	public $config = array(
	);

	public function initialize(Controller $Controller) 
	{
		$this->Controller = & $Controller;
		
		// set the search to multisearch if we are coming from 
		$referer = $this->Controller->request->referer();
	}
	
	public function getInfo($path = array())
	{
		$modelName = $this->Controller->modelClass;
		
		$request_params = $this->Controller->request->params;
		
		$default_path = array(
			'admin' => (isset($request_params['admin'])?$request_params['admin']:false),
			'plugin' => (isset($request_params['plugin'])?$request_params['plugin']:false),
			'controller' => $request_params['controller'],
			'action' => false,
		);
		
		$path = array_merge($default_path, $path);
		if(!$path['action'])
		{
			return $this->Controller->redirect($this->Controller->request->referer());
		}
		
		return $this->Controller->{$modelName}->Search_getInfo($path);
	}
	
	public function render()
	{
		if(isset($this->Controller->viewVars['info']['path']))
		{
			$parts = Router::parse($this->Controller->viewVars['info']['path']);
			$named = (isset($parts['named'])?$parts['named']:array());
			$this->Prg->presetForm(false, $named);
		}
		return $this->Controller->render('Search.Elements/search');
	}
}
