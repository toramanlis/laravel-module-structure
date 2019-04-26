<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Modules;

/**
 * Description of DependencyException
 *
 * @author timucin
 */
class DependencyException extends \Exception{
	public function __construct($moduleName, $dependenciesNotMet) {
		parent::__construct('Module ' . $moduleName . ' depends on module'.(count($dependenciesNotMet>1?'s':'')).': ' . implode(',', $dependenciesNotMet));
	}
}
