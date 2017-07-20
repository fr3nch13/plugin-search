<?php
/**
 * Copyright 2009-2010, Cake Development Corporation (http://cakedc.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2009-2010, Cake Development Corporation (http://cakedc.com)
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

App::uses('Router', 'Core');
App::uses('CakeSession', 'Model/Datasource');
App::uses('CakeRequest', 'Core');


/**
 * Searchable behavior
 *
 * @package		plugins.search
 * @subpackage	plugins.search.models.behaviors
 */
class SearchableBehavior extends ModelBehavior {

/**
 * settings indexed by model name.
 *
 * @var array
 */
	public $settings = array();

/**
 * Default settings
 *
 * @var string
 */
	protected $_defaults = array();
	
/**
 *
 * Field options
 * The allowed options for a search field
 *
 */

	protected $field_templates = array(
		'both' => '%__term__%',
		'left' => '%__term__',
		'right' => '__term__%',
		'exact' => '__term__',
	);
	
	
	///// Between //~~~ and //~~~// these are defaults if they're not set in the Model
	// don't reference these, reference the ones in the Model
	
	// define the default search criteria
	public $filterArgs = array(
			array('name' => 'q', 'type' => 'query', 'method' => 'orConditions'),
	);
	
	// the fields that can be searched
	// fill this out in the actual model
	public $searchFields = array();
	
	//~~~//

/**
 * Configuration of model
 *
 * @param AppModel $Model
 * @param array $config
 */
	public function setup(Model $Model, $config = array()) 
	{
		$this->settings[$Model->alias] = array_merge($this->_defaults, $config);
		
		$this->_checkVariable($Model, 'searchFields');
		$this->_checkVariable($Model, 'filterArgs');
	}
	
	public function _checkVariable(Model $Model, $variable_name)
	{
		if(!$variable_name) return false;
		
		if(!isset($Model->{$variable_name}))
		{
			$Model->{$variable_name} = $this->{$variable_name};
		}
	}

/**
 * parseCriteria
 * parses the GET data and returns the conditions for the find('all')/paginate
 * we are just going to test if the params are legit
 *
 * @param array $data Criteria of key->value pairs from post/named parameters
 * @return array Array of conditions that express the conditions needed for the search.
 */
	public function parseCriteria(Model $Model, $data) {
		$conditions = array();
		foreach ($Model->filterArgs as $field) {
			if (in_array($field['type'], array('string', 'like'))) {
				$this->_addCondLike($Model, $conditions, $data, $field);
			} elseif (in_array($field['type'], array('int', 'value'))) {
				$this->_addCondValue($Model, $conditions, $data, $field);
			} elseif ($field['type'] == 'expression') {
				$this->_addCondExpression($Model, $conditions, $data, $field);
			} elseif ($field['type'] == 'query') {
				$this->_addCondQuery($Model, $conditions, $data, $field);
			} elseif ($field['type'] == 'subquery') {
				$this->_addCondSubquery($Model, $conditions, $data, $field);
			}
		}
		return $conditions;
	}

/**
 * Validate search
 *
 * @param object Model
 * @return boolean always true
 */
	public function validateSearch(Model $Model, $data = null) 
	{
		if(isset($Model->data[$Model->alias]))
		{
			$keys = array_keys($Model->data[$Model->alias]);
			foreach ($keys as $key) 
			{
				if (empty($Model->data[$Model->alias][$key])) 
				{
					unset($Model->data[$Model->alias][$key]);
				}
			}
			$Model->set($data);
		}
		return true;
	}

/**
 * filter retrieving variables only that present in  Model::filterArgs
 *
 * @param object Model
 * @param array $vars
 * @return array, filtered args
 */
	public function passedArgs(Model $Model, $vars) {
		$result = array();
		foreach ($vars as $var => $val) {
			if (in_array($var, Set::extract($Model->filterArgs, '{n}.name'))) {
				$result[$var] = trim($val);
			}
		}
		return $result;
	}

/**
 * Generates a query string using the same API Model::find() uses, calling the beforeFind process for the model
 *
 * 
 * @param string $type Type of find operation (all / first / count / neighbors / list / threaded)
 * @param array $query Option fields (conditions / fields / joins / limit / offset / order / page / group / callbacks)
 * @return array Array of records
 * @link http://book.cakephp.org/view/1018/find
 */
	public function getQuery(Model $Model, $type = 'first', $query = array()) {
		$Model->findQueryType = $type;
		$Model->id = $Model->getID();
		$query = $Model->buildQuery($type, $query);
		$this->findQueryType = null;
		return $this->__queryGet($Model, $query);
	}

/**
 * Clear all associations
 *
 * @param AppModel $Model
 * @param bool $reset
 */
	public function unbindAllModels(Model $Model, $reset = false) {
		$assocs = array('belongsTo', 'hasOne', 'hasMany', 'hasAndBelongsToMany');
		$unbind = array();
		foreach ($assocs as $assoc) {
		  $unbind[$assoc] = array_keys($Model->{$assoc});
		}
		$Model->unbindModel($unbind, $reset);
	}
	
/////////////////////
	
	public function conditions(Model $Model, $conditions = array(), $passedArgs = array())
	{
	/*
	 * Adds an additional layer incase we need it
	 */
	 
	 	if(!$conditions) $conditions = array();
	 	
	 	// store the original conditions with the Searchable Behavior for use later in an advanced search
	 	$this->Search_setInfo($Model, $conditions, $Model->searchFields);
	 	
		return array_merge($conditions, $this->parseCriteria($Model, $passedArgs));
	}
	
	public function mergeConditions(Model $Model, $conditions = array(), $new_conditions = array())
	{
	/*
	 * Adds an additional layer incase we need it
	 */
		
		return array_merge($conditions, $new_conditions);
	}
	
	
	public function orConditions(Model $Model, $data = array()) 
	{
	/*
	 * Construct the sql for the search query
	 */
		if(!isset($data['q'])) return false;
		
		$filter = trim($data['q']);
		if(!$filter) return false;
		
		$old_searchFields = false;
		if(isset($data['f']))
		{
			if(is_string($data['f']) and trim($data['f'])) 
			{
				if(stripos($data['f'], '|') !== false)
				{
					$data['f'] = explode('|', $data['f']);
				}
				else
				{
					$data['f'] = array(trim($data['f']));
				}
			}
			if($data['f']) 
			{
				$old_searchFields = $Model->searchFields;
				$Model->searchFields = $data['f'];
			}
		}
		
		$exclude = false;
		$like = ' LIKE';
		$padding = '';
		// allow the ability to exclude, instead of include the search criteria
		if(isset($data['ex']) and $data['ex'])
		{
			$exclude = true;
			$like = ' NOT LIKE';
		}
		// allow the ability to exclude, instead of include the search criteria
		if(isset($data['padding']) and $data['padding'])
		{
			$padding = str_repeat(' ', $data['padding']);
			unset($data['padding']);
		}
		
		$cond = array();
		
		// search only the primary IDS
		if(isset($data['p']) and $data['p'])
		{
			$mainCondKey = '   '.$Model->alias.'.'.$Model->primaryKey;
			$condKey = $mainCondKey;
			
			if($exclude)
			{
				$condKey = $mainCondKey.' !=';
			}
			
			$_filter = $filter;
//			if(stripos($_filter, "\n"))
			if(preg_match('/(\n|\t)/', $_filter))
			{
				if($exclude)
				{
					$condKey = $mainCondKey.' NOT IN';
				}
//				$_filter = explode("\r\n", $_filter);
				$_filter = preg_split('/(\n|\t)/', $_filter);
			}
			
			$cond[$condKey] = $_filter;
		}
		elseif(
			(is_array($Model->searchFields) and !empty($Model->searchFields)) 
			or (isset($data['searchFields']) and is_array($data['searchFields']) and !empty($data['searchFields']))
		)
		{
			$alias = $Model->alias;
			$primaryKeys = array();
			$primaryKeys[$alias] = $Model->primaryKey;
			$foreignKeys = array();
			
			// determine which searchFields to use
			$_searchFields = array();
			if(isset($data['searchFields']) and !empty($data['searchFields']))
			{
				$_searchFields = $data['searchFields'];
				unset($data['searchFields']);
			}
			elseif($Model->searchFields)
			{
				$_searchFields = $Model->searchFields;
			}
			
			$or = array();
			$and = array();
			
			// see if we need to turn recursion to 0
			$alias = $Model->alias;
			$contain = false;
			
			// rearrange the searchfield to work with options
			$searchFields = array();
			foreach($_searchFields as $i => $searchField)
			{
				if(is_string($searchField))
				{
					$searchFields[$searchField] = true;
				}
				elseif(is_array($searchField))
				{
					$searchFields[$i] = $searchField;
				}
			}
			
			foreach($searchFields as $searchField => $searchFieldOptions)
			{
				if(stripos($searchField, '.'))
				{
					list($alias, $searchField) = pluginSplit($searchField);
					$className = $alias;
					if(isset($searchFieldOptions['class']))
					{
						$className = $searchFieldOptions['class'];
						unset($searchFieldOptions['class']);
					}
					if($alias != $Model->alias)
					{
						$contain[$alias] = $alias;
						// make sure it's loaded
						if(!is_object($Model->{$alias})) 
						{
							App::uses($className, 'Model');
							$Model->{$alias} = new $className();
						}
						$primaryKeys[$alias] = $Model->{$alias}->primaryKey;
						if(isset($Model->belongsTo[$alias]['foreignKey']))
							$foreignKeys[$alias] = $Model->belongsTo[$alias]['foreignKey'];
					}
				}
			}
			
			if(!empty($contain) and $Model->recursive === -1) $Model->recursive = 0;
			
			// filter out the search fields that can't be used in this instance (like if contain doesn't have this model listed)
			// or if the related model has a different data source
			$otherSourceFields = array();
			$otherSourceConditions = array();
			$habtmFields = [];
			$habtmConditions = [];
			foreach($searchFields as $searchField => $searchFieldOptions)
			{
				$remove = false;
				$direction = (isset($searchFieldOptions['direction'])?$searchFieldOptions['direction']:'both');
				$alias = $Model->alias;
				// see if we need to add the model to the field name
				if(stripos($searchField, '.'))
				{
					list($alias, $searchField) = pluginSplit($searchField);
					
					// different model
					if($alias != $Model->alias)
					{
						// check the recursive level to make sure we can search this field
						if($Model->recursive === -1) $remove = true;
						// check if this is in contain
						if(!empty($contain))
						{
							if(!isset($contain[$alias])) $remove = true;
						}
						// check to make sure the associated model is available
						if(!is_object($Model->{$alias})) $remove = true;
						// check that they're in the same data source
						elseif ($Model->useDbConfig !== $Model->{$alias}->useDbConfig)
						{
							$otherSourceFields[$alias][$alias.'.'.$searchField] = true;
						}
						// check to see if they're a mant-to-many relationship
						if(isset($Model->hasAndBelongsToMany[$alias]))
						{
							$habtmFields[$alias][$alias.'.'.$searchField] = $searchFieldOptions;
						}
					}
				}
				
				if($remove)
				{
					if(isset($searchFields[$searchField]))
						unset($searchFields[$searchField]);
					if(isset($searchFields[$alias.'.'.$searchField]))
						unset($searchFields[$alias.'.'.$searchField]);
				}
			}
			
			foreach($searchFields as $searchField => $searchFieldOptions)
			{
				$direction = (isset($searchFieldOptions['direction'])?$searchFieldOptions['direction']:'both');
				$alias = $Model->alias;
				
				if(stripos($searchField, '.'))
				{
					list($alias, $searchField) = pluginSplit($searchField);
				}
				
				$sql_key = false;
				$sql_value = false;
				if(preg_match('/(\n|\t)/', $filter))
				{
					$filters = preg_split('/(\n|\t)/', $filter);
					foreach($filters as $i => $mfilter)
					{
						$mfilter = trim($mfilter);
						if($direction == 'exact')
						{
							if($exclude)
							{
								$sql_key = " IF((". $alias. ".". $primaryKeys[$alias]. " > '0' AND `". $alias. '`.`' . $searchField.'` IS NOT NULL), `'. $alias. '`.`' . $searchField.'`'." = '".trim($mfilter)."', 1) = ";
								$sql_value = 1;
							}
							else
							{
								$sql_key = $alias.'.'.$searchField;
								$sql_value = trim($mfilter);
							}
						}
						elseif(isset($this->field_templates[$direction]))
						{
							$template = $this->field_templates[$direction];
							$term = str_replace('__term__', trim($mfilter), $this->field_templates[$direction]);
							
							if($exclude)
							{
								$sql_key = " IF((". $alias. ".". $primaryKeys[$alias]. " > '0' AND `". $alias. '`.`' . $searchField.'` IS NOT NULL), `'. $alias. '`.`' . $searchField.'`'. $like. "  '". $term. "', 1) = ";
								$sql_value = 1;
							}
							else
							{
								$sql_key = $alias.'.'.$searchField. $like;
								$sql_value = $term;
							}
						}
						else
						{
							if($exclude)
							{
								$sql_key = " IF((". $alias. ".". $primaryKeys[$alias]. " > '0' AND `". $alias. '`.`' . $searchField.'` IS NOT NULL), `'. $alias. '`.`' . $searchField.'`'. $like. "  '%". trim($mfilter). "%', 1) = ";
								$sql_value = 1;
							}
							else
							{
								$sql_key = $alias.'.'.$searchField. $like;
								$sql_value = '%'. trim($mfilter). '%';
							}
						}
					
						// track the sql for the models with other sources
						if(isset($otherSourceFields[$alias][$alias.'.'.$searchField]))
						{
							$otherSourceConditions[$alias]['or'][][$alias.'.'.$searchField. ' LIKE'] = '%'. trim($mfilter). '%';
						}
						elseif(isset($habtmFields[$alias][$alias.'.'.$searchField]))
						{
							// get a list of primary keys for this habtm based on their searchfields
							$habtmConditions[$alias]['or'][][$alias.'.'.$searchField. ' LIKE'] = '%'. trim($mfilter). '%';
							// get a list if primary keys from the xref for this table
						}
						else
						{
							$or[] = array($sql_key => $sql_value);
						}
					}
				}
				else
				{
					if($direction == 'exact')
					{
						if($exclude)
						{
							$sql_key = " IF((". $alias. ".". $primaryKeys[$alias]. " > '0' AND `". $alias. '`.`' . $searchField.'` IS NOT NULL), `'. $alias. '`.`' . $searchField.'`'." = '".trim($filter)."', 1) = ";
							$sql_value = 1;
						}
						else
						{
							$sql_key = $alias.'.'.$searchField;
							$sql_value = trim($filter);
						}
					}
					elseif(isset($this->field_templates[$direction]))
					{
						$template = $this->field_templates[$direction];
						$term = str_replace('__term__', trim($filter), $this->field_templates[$direction]);
							
						if($exclude)
						{
							$sql_key = " IF((". $alias. ".". $primaryKeys[$alias]. " > '0' AND `". $alias. '`.`' . $searchField.'` IS NOT NULL), `'. $alias. '`.`' . $searchField.'`'. $like. "  '". $term. "', 1) = ";
							$sql_value = 1;
						}
						else
						{
							$sql_key = $alias.'.'.$searchField. $like;
							$sql_value = $term;
						}
					}
					else
					{
						if($exclude)
						{
							$sql_key = " IF((". $alias. ".". $primaryKeys[$alias]. " > '0' AND `". $alias. '`.`' . $searchField.'` IS NOT NULL), `'. $alias. '`.`' . $searchField.'`'. $like. "  '%". trim($filter). "%', 1) = ";
							$sql_value = 1;
						}
						else
						{
							$sql_key = $alias.'.'.$searchField. $like;
							$sql_value = '%'. trim($filter). '%';
						}
					}
					
					// track the sql for the models with other sources
					if(isset($otherSourceFields[$alias][$alias.'.'.$searchField]))
					{
						$otherSourceConditions[$alias]['or'][$alias.'.'.$searchField. ' LIKE'] = '%'. trim($filter). '%';
					}
					elseif(isset($habtmFields[$alias][$alias.'.'.$searchField]))
					{
						$habtmConditions[$alias]['or'][][$alias.'.'.$searchField. ' LIKE'] = '%'. trim($filter). '%';
					}
					else
					{
						$or[$sql_key] = $sql_value;
					}
				}
			}
			
			$relatedConditions = array();
			foreach($otherSourceConditions as $alias => $aliasConditions)
			{
				if(is_object($Model->{$alias}))
				{
					if($ids = $Model->{$alias}->find('list', array(
						'fields' => array($alias.'.'.$primaryKeys[$alias], $alias.'.'.$primaryKeys[$alias]),
						'conditions' => $aliasConditions,
					)))
					{
						$foreignKey = $foreignKeys[$alias];
						if($exclude)
						{
							$sql_key = " IF((". $Model->alias.".".$foreignKey. " > '0' AND `". $Model->alias. "`.`". $foreignKey. "` IS NOT NULL), `". $Model->alias. "`.`". $foreignKey. "` NOT IN (".implode(',', $ids)."), 1) = ";
							$or[$sql_key] = 1;
						}
						else
							$or[$Model->alias.'.'.$foreignKey. ' IN'] = $ids;
					}
				}
			}
			
			foreach($habtmConditions as $alias => $aliasConditions)
			{
				// find the xref model name
				$xref_modelName = (isset($Model->hasAndBelongsToMany[$alias]['with'])?$Model->hasAndBelongsToMany[$alias]['with']:false);
				// assume cakephp standard name
				if(!$xref_modelName)
				{
					$_tmpModel = [$Model->{$alias}->name, $Model->name];
					sort($_tmpModel);
					$xref_modelName = implode('', $_tmpModel);
					unset($_tmpModel);
				}
				$xref_associationForeignKey = $Model->hasAndBelongsToMany[$alias]['associationForeignKey'];
				$xref_foreignKey = $Model->hasAndBelongsToMany[$alias]['foreignKey'];
						
				if(is_object($Model->{$alias}) and is_object($Model->{$xref_modelName}))
				{
					if($ids = $Model->{$alias}->find('list', array(
						'fields' => array($alias.'.'.$primaryKeys[$alias], $alias.'.'.$primaryKeys[$alias]),
						'conditions' => $aliasConditions,
					)))
					{
						$xref_conditions = [
							'recursive' => -1,
							'conditions' => [$xref_modelName.'.'.$xref_associationForeignKey => $ids],
							'fields' => [$xref_modelName.'.'.$xref_foreignKey, $xref_modelName.'.'.$xref_foreignKey],
						];
						if($thisIds = $Model->{$xref_modelName}->find('list', $xref_conditions))
						{
							if($exclude)
							{
								$sql_key = " IF(`". $Model->alias. "`.`". $Model->primaryKey. "` NOT IN (".implode(',', $thisIds)."), 1) = ";
								$or[$sql_key] = 1;
							}
							else
							{
								$or['      '.$Model->alias.'.'.$Model->primaryKey. ' IN'] = $thisIds;
							}
						}
					}
				}
			}
			
			if($exclude)
			{
				$and = $or;
				$or = false;
			}
			
			if($or)
			{
				$cond[$padding. '    OR'] = $or;
			}
			if($and)
			{
				$cond[$padding. '    AND'] = $and;
			}
		}
		
		if($old_searchFields)
		{
			$Model->searchFields = $old_searchFields;
		}
		return $cond;
	}
	
	
	public function Search_setInfo(Model $Model, $conditions = array(), $fields = array(), $key = array())
	{
		// try to figure out where the searching is going to happen
		$key = $this->Search_Key($Model, $key);
		
		CakeSession::write('SearchInfo.'.$key, array(
			'conditions' => $conditions, 
			'fields' => $fields,
			'path' => $this->Search_Path($Model),
		));
	}
	
	public function Search_getInfo(Model $Model, $key = false)
	{
		$key = $this->Search_Key($Model, $key);
		
		return CakeSession::read('SearchInfo.'.$key);
	}
	
	public function Search_Key(Model $Model, $url = array())
	{
		$caller = false;
		$key = array('admin' => false, 'plugin' => false, 'controller' => false, 'action' => false);
		
		if($url)
		{
			ksort($url);
			$key = array_merge($key, $url);
		}
		else
		{
		
			$debug_info = debug_backtrace();
			$caller = false;
				
			foreach($debug_info as $k => $v)
			{
				if(isset($v['class']) and stripos($v['class'], 'Controller') !== false)
				{
					$caller = $v;
					break;
				}
			}
			
			if(isset($caller))
			{
				if(isset($caller['object']->request->params))
				{
					$caller = $caller['object']->request->params;
					foreach($caller as $k => $v)
					{
						if(!$v) $v = false;
						if(isset($key[$k])) $key[$k] = $v;
					}
				}
			}	
		}
		ksort($key);
		$key = sha1(json_encode($key));
		unset($debug_info, $caller);
		return $key;
	}
	
	public function Search_Path(Model $Model)
	{
		$debug_info = debug_backtrace();
		$caller = false;
			
		foreach($debug_info as $k => $v)
		{
			if(isset($v['class']) and stripos($v['class'], 'Controller') !== false)
			{
				$caller = $v;
				break;
			}
		}
		
		if(isset($caller) and isset($caller['object']))
		{
			if($caller['object']->request->url)
			{
				return '/'. $caller['object']->request->url;
			}
		}	
	}
	
	public function Search_validateSearch(Model $Model)
	{
		return true;
	}
	
/**
 * Add Conditions based on fuzzy comparison
 *
 * @param AppModel $Model Reference to the model
 * @param array $conditions existing Conditions collected for the model
 * @param array $data Array of data used in search query
 * @param array $field Field definition information
 * @return array of conditions.
 */
	protected function _addCondLike(Model $Model, &$conditions, $data, $field) {
		$fieldName = $field['name'];
		if (isset($field['field'])) {
			$fieldName = $field['field'];
		}
		if (strpos($fieldName, '.') === false) {
			$fieldName = $Model->alias . '.' . $fieldName;
		}
		if (!empty($data[$field['name']])) {
			$conditions[$fieldName . " LIKE"] = "%" . $data[$field['name']] . "%";
		}
		return $conditions;
	}

/**
 * Add Conditions based on exact comparison
 *
 * @param AppModel $Model Reference to the model
 * @param array $conditions existing Conditions collected for the model
 * @param array $data Array of data used in search query
 * @param array $field Field definition information
 * @return array of conditions.
 */
	protected function _addCondValue(Model $Model, &$conditions, $data, $field) {
		$fieldName = $field['name'];
		if (isset($field['field'])) {
			$fieldName = $field['field'];
		}
		if (strpos($fieldName, '.') === false) {
			$fieldName = $Model->alias . '.' . $fieldName;
		}
		if (!empty($data[$field['name']]) || (isset($data[$field['name']]) && ($data[$field['name']] === 0 || $data[$field['name']] === '0'))) {
			$conditions[$fieldName] = $data[$field['name']];
		}
		return $conditions;
	}

/**
 * Add Conditions based query to search conditions.
 *
 * @param Object $model  Instance of AppModel
 * @param array $conditions Existing conditions.
 * @param array $data Data for a field.
 * @param array $field Info for field.
 * @return array of conditions modified by this method.
 */
	protected function _addCondQuery(Model $Model, &$conditions, $data, $field) {
		if ((method_exists($Model, $field['method']) || $this->__checkBehaviorMethods($Model, $field['method'])) && (!empty($data[$field['name']]) || (isset($data[$field['name']]) && ($data[$field['name']] === 0 || $data[$field['name']] === '0')))) {
			$conditionsAdd = $Model->{$field['method']}($data, $field);
			$conditions = array_merge($conditions, (array)$conditionsAdd);
		}
		return $conditions;
	}

/**
 * Add Conditions based expressions to search conditions.
 *
 * @param Object $model  Instance of AppModel
 * @param array $conditions Existing conditions.
 * @param array $data Data for a field.
 * @param array $field Info for field.
 * @return array of conditions modified by this method.
 */
	protected function _addCondExpression(Model $Model, &$conditions, $data, $field) {
		$fieldName = $field['field'];
		if ((method_exists($Model, $field['method']) || $this->__checkBehaviorMethods($Model, $field['method'])) && (!empty($data[$field['name']]) || (isset($data[$field['name']]) && ($data[$field['name']] === 0 || $data[$field['name']] === '0')))) {
			$fieldValues = $Model->{$field['method']}($data, $field);
			if (!empty($conditions[$fieldName]) && is_array($conditions[$fieldName])) {
				$conditions[$fieldName] = array_unique(array_merge(array($conditions[$fieldName]), array($fieldValues)));
			} else {
				$conditions[$fieldName] = $fieldValues;
			}
		}
		return $conditions;
	}

/**
 * Add Conditions based subquery to search conditions.
 *
 * @param Object $model  Instance of AppModel
 * @param array $conditions Existing conditions.
 * @param array $data Data for a field.
 * @param array $field Info for field.
 * @return array of conditions modified by this method.
 */
	protected function _addCondSubquery(Model $Model, &$conditions, $data, $field) {
		$fieldName = $field['field'];
		if ((method_exists($Model, $field['method']) || $this->__checkBehaviorMethods($Model, $field['method'])) && (!empty($data[$field['name']]) || (isset($data[$field['name']]) && ($data[$field['name']] === 0 || $data[$field['name']] === '0')))) {
			$subquery = $Model->{$field['method']}($data, $field);
			$conditions[] = array("$fieldName in ($subquery)");
		}
		return $conditions;
	}

/**
 * Helper method for getQuery.
 * extension of dbosource method. Create association query.
 *
 * @param AppModel $Model
 * @param array $queryData
 * @param integer $recursive
 */
	private function __queryGet(Model $Model, $queryData = array()) {
		/** @var DboSource $db  */
		$db = $Model->getDataSource();
		$queryData = $this->_scrubQueryData($queryData);
		$recursive = null;
		$byPass = false;
		$null = null;
		$array = array();
		$linkedModels = array();

		if (isset($queryData['recursive'])) {
			$recursive = $queryData['recursive'];
		}

		if (!is_null($recursive)) {
			$_recursive = $Model->recursive;
			$Model->recursive = $recursive;
		}

		if (!empty($queryData['fields'])) {
			$byPass = true;
			$queryData['fields'] = $db->fields($Model, null, $queryData['fields']);
		} else {
			$queryData['fields'] = $db->fields($Model);
		}

		$_associations = $Model->associations();

		if ($Model->recursive == -1) {
			$_associations = array();
		} elseif ($Model->recursive == 0) {
			unset($_associations[2], $_associations[3]);
		}

		foreach ($_associations as $type) {
			foreach ($Model->{$type} as $assoc => $assocData) {
				$linkModel = $Model->{$assoc};
				$external = isset($assocData['external']);

				$linkModel->getDataSource();
				if ($Model->useDbConfig === $linkModel->useDbConfig) {
					if ($byPass) {
						$assocData['fields'] = false;
					}
					if (true === $db->generateAssociationQuery($Model, $linkModel, $type, $assoc, $assocData, $queryData, $external, $null)) {
						$linkedModels[$type . '/' . $assoc] = true;
					}
				}
			}
		}

		return trim($db->generateAssociationQuery($Model, null, null, null, null, $queryData, false, $null));
	}

/**
 * Private helper method to remove query metadata in given data array.
 *
 * @param array $data
 * @return array
 */
	protected function _scrubQueryData($data) {
		static $base = null;
		if ($base === null) {
			$base = array_fill_keys(array('conditions', 'fields', 'joins', 'order', 'limit', 'offset', 'group'), array());
		}
		return (array)$data + $base;
	}

/**
 * Check if model have some method in attached behaviors
 *
 * @param Model $Model
 * @param string $method
 * @return boolean, true if method exists in attached and enabled behaviors
 */
	private function __checkBehaviorMethods(Model $Model, $method) {
		$behaviors = $Model->Behaviors->enabled();
		$count = count($behaviors);
		$found = false;
		for ($i = 0; $i < $count; $i++) {
			$name = $behaviors[$i];
			$methods = get_class_methods($Model->Behaviors->{$name});
			$check = array_flip($methods);
			$found = isset($check[$method]);
			if ($found) {
				return true;
			}
		}
		return $found;
	}
	
	public function _objectToArray(Model &$Model, $obj) 
	{
		$arrObj = is_object($obj) ? get_object_vars($obj) : $obj;
		$arr = '';
		if($arrObj)
		{
			foreach ($arrObj as $key => $val) 
			{
				$val = (is_array($val) || is_object($val)) ? $this->_objectToArray($Model, $val) : $val;
				$arr[$key] = $val;
			}
		}
		return $arr;
	}
}