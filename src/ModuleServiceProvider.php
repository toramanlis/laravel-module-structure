<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
	protected static $modulesPath=null;

	/**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
		$staticFileName=(new \ReflectionClass(static::class))->getFileName();
		$modulePath=substr($staticFileName,0, strpos($staticFileName, '/', strlen(app_path('Modules'))+1));
		$this->load($modulePath);

    }

	protected function loadRoutes($modulePath) {
		$routesFile = $modulePath->getRealPath() . '/module/routes.php';
		if (file_exists($routesFile)) {
			Route::name(strtolower($modulePath->getFilename()) . '::')
				->namespace('App\Modules\\' . $modulePath->getFilename() . '\Http\Controllers')
				->group($routesFile);
		}
	}

	public function loadConfig($modulePath){
		$configDir= $modulePath->getRealPath().'/module/config';
		$key= 'modules.'.strtolower($modulePath->getFilename());
		config([$key=>['name'=> $modulePath->getFilename()]]);
		if(is_dir($configDir)){
			foreach(new \FilesystemIterator($configDir, \FilesystemIterator::SKIP_DOTS) as $configFile){
				$configFileName=strtolower($configFile->getBasename('.php'));
				if($configFileName=='module'){
					$this->mergeConfigFrom($configFile->getPathname(), $key);
				}else{
					$moduleConfig=include($configFile->getPathname());
					foreach($moduleConfig as $globalKey=>$value){
						$already= config($configFileName . '.' . $globalKey);
						$toMerge= $already?(is_array($already)?$already:[$already]):[];
						config([$configFileName.'.'.$globalKey=>array_merge($toMerge,is_array($value)?$value:[$value])]);
					}
				}
			}
		}
	}

	protected function loadEvents($modulePath){
		$eventsFile = $modulePath->getRealPath() . '/module/events.php';
		if (file_exists($eventsFile)) {
			$subscribers=include($eventsFile);
			$this->loadSubscribers($subscribers ?? []);
		}
	}

	protected function loadSubscribers($subscriberInfo){
		foreach ($subscriberInfo as $subscriber) {
			Event::subscribe($subscriber);
		}
	}

	protected function load($path){
		$pathIterator= new \SplFileInfo(realpath($path));
		$this->loadConfig($pathIterator);
		$this->loadEvents($pathIterator);
		$this->loadRoutes($pathIterator);
		$this->loadViewsFrom($pathIterator->getRealPath().'/module/resources/views', strtolower($pathIterator->getFilename()));
		$this->loadTranslationsFrom($pathIterator->getRealPath().'/module/resources/lang', strtolower($pathIterator->getFilename()));
		$this->loadMigrationsFrom($pathIterator->getRealPath().'/module/database/migrations');
	}
}
