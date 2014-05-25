<?php

namespace Di;

abstract class Di {
	private static $Rules     = [];
	private static $Instances = [];

	public static function addRule($Name, Rule $Rule) {
		$Rule->Substitutions = array_change_key_case($Rule->Substitutions);

		self::$Rules[strtolower(trim($Name, '\\'))] = $Rule;
	}

	public static function getRule($Name) {
		if(isset(self::$Rules[strtolower(trim($Name, '\\'))]))
			return self::$Rules[strtolower(trim($Name, '\\'))];

		foreach(self::$Rules as $Key => $Rule) 
			if($Rule->InstanceOf === null && $Key !== '*' && is_subclass_of($Name, $Key) && $Rule->Inherit === true)
				return $Rule;

		return isset(self::$Rules['*']) ? self::$Rules['*'] : new DiRule;
	}

	public static function create($Component, array $Args = [], $ForceNewInstance = false) {		
		$Component = trim(($Component instanceof Instance) ? $Component->Name : $Component, '\\');
				
		if(!isset(self::$Rules[strtolower($Component)]) && !class_exists($Component))
			throw new \Exception('Class does not exist for creation: ' . $Component);
		
		if(!$ForceNewInstance && isset(self::$Instances[strtolower($Component)]))
			return self::$Instances[strtolower($Component)];
		
		$Rule = self::getRule($Component);

		$ClassName = (!empty($Rule->InstanceOf)) ? $Rule->InstanceOf : $Component;
		$Share     = self::expandParams($Rule->ShareInstances);

		$Params    = self::getMethodParams($ClassName, '__construct',
			$Rule->Substitutions, $Rule->NewInstances, array_merge($Args, self::expandParams($Rule->ConstructParams, $Share), $Share), $Share);
				
		$Object = (count($Params) > 0) ? (new \ReflectionClass($ClassName))->newInstanceArgs($Params) : new $ClassName;

		if($Rule->Shared === true)
			self::$Instances[strtolower($Component)] = $Object;

		foreach($Rule->Call as $Call)
			call_user_func_array([$Object, $Call[0]], self::getMethodParams($ClassName, $Call[0], [], [], array_merge(self::expandParams($Call[1]), $Args)));
		
		return $Object;
	}

	private static function expandParams(array $Params, array $Share = []) {
		for($It = 0; $It < count($Params); $It++) {
			if($Params[$It] instanceof Instance)
				$Params[$It] = self::create($Params[$It], $Share);

			else if(is_callable($Params[$It]))
				$Params[$It] = call_user_func($Params[$It], null); //TODO: Bring reference from OOP to static context
		}

		return $Params;
	}

	private static function getMethodParams($ClassName, $Method, array $Substitutions = [], array $NewInstances = [], array $Args = [], array $Share = []) {
		if(!method_exists($ClassName, $Method))
			return [];

		$Params     = (new \ReflectionMethod($ClassName, $Method))->getParameters();
		$Parameters = [];

		foreach($Params as $Param) {
			$Class = $Param->getClass() ? $Param->getClass()->name : false;

			foreach($Args as $ArgName => $Arg) {
				if($Class && $Arg instanceof $Class) {
					$Parameters[] = $Arg;
					unset($Args[$ArgName]);

					continue 2;
				}
			}

			if($Class && isset($Substitutions[strtolower($Class)]))
				$Parameters[] = is_string($Substitutions[strtolower($Class)]) ? new Instance($Substitutions[strtolower($Class)]) : $Substitutions[strtolower($Class)];

			else if($Class)
				$Parameters[] = self::create($Class, $Share, in_array(strtolower($Class), array_map('strtolower', $NewInstances)));

			else if(is_array($Args) && count($Args) > 0)
				$Parameters[] = array_shift($Args);
		}

		return self::expandParams($Parameters, $Share);
	}
}

class Rule {
	public $InstanceOf;
	public $Shared  = false;
	public $Inherit = true;
	public $ConstructParams = [];
	public $Substitutions   = [];
	public $NewInstances    = [];
	public $Call 		    = [];
	public $ShareInstances  = [];
}

class Instance {
	public $Name;

	public function __construct($Instance) {
		$this->Name = $Instance;
	}
}