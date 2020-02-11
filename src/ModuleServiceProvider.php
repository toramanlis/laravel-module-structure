<?php

namespace Modules;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Modules\DependencyException;

class ModuleServiceProvider extends ServiceProvider {

	protected static $modulesPath = null;
	public static $registered = [];

	public function register() {
		static::$registered[] = static::class;
	}

	/**
	 * Define your route model bindings, pattern filters, etc.
	 *
	 * @return void
	 */
	public function boot() {
		$staticFileName = (new \ReflectionClass(static::class))->getFileName();
		$modulePathName = substr($staticFileName, 0, strpos($staticFileName, DIRECTORY_SEPARATOR, strlen(base_path('app' . DIRECTORY_SEPARATOR . 'Modules')) + 1));
		$modulePath = new \SplFileInfo(realpath($modulePathName));
		$this->load($modulePath);
	}

	protected function loadRoutesToLumen($modulePath, $routesFile) {
		app(\Laravel\Lumen\Routing\Router::class)->group([
			'namespace' => '\App\Modules\\' . $modulePath->getFilename() . '\Http\Controllers',
			'as' => Str::kebab($modulePath->getFilename()),
			], function()use ($routesFile) {
			$router = app(\Laravel\Lumen\Routing\Router::class);
			include($routesFile);
		});
	}

	protected function loadRoutesToLaravel($modulePath, $routesFile) {
		Route::name(Str::kebab($modulePath->getFilename()) . '::')
			->namespace('App\Modules\\' . $modulePath->getFilename() . '\Http\Controllers')
			->group($routesFile);
	}

	protected function loadRoutes($modulePath) {
		$routesFile = $modulePath->getRealPath() . '/module/routes.php';
		if (file_exists($routesFile)) {
			if (app() instanceof \Illuminate\Foundation\Application) {
				$this->loadRoutesToLaravel($modulePath, $routesFile);
			} else {
				$this->loadRoutesToLumen($modulePath, $routesFile);
			}
		}
	}

	public function loadConfig($modulePath) {
		$configDir = $modulePath->getRealPath() . '/module/config';
		$key = 'modules.' . Str::kebab($modulePath->getFilename());
		config([$key => ['name' => $modulePath->getFilename()]]);
		if (is_dir($configDir)) {
			foreach (new \FilesystemIterator($configDir, \FilesystemIterator::SKIP_DOTS) as $configFile) {
				$configFileName = Str::kebab($configFile->getBasename('.php'));
				if ($configFileName == 'module') {
					$this->mergeConfigFrom($configFile->getPathname(), $key);
				} else {
					$config = $this->app['config']->get($configFileName, []);
					$moduleConfig = include($configFile->getPathname());
					$this->app['config']->set($configFileName, array_merge($config, $moduleConfig));
				}
			}
		}
	}

	protected function loadEvents($modulePath) {
		$eventsFile = $modulePath->getRealPath() . '/module/events.php';
		if (file_exists($eventsFile)) {
			$subscribers = include($eventsFile);
			$this->loadSubscribers($subscribers ?? []);
		}
	}

	protected function loadSubscribers($subscriberInfo) {
		foreach ($subscriberInfo as $subscriber) {
			app('events')->subscribe($subscriber);
		}
	}

	protected function loadCommands($modulePath) {
		$commands = [];
		$commandsDir = $modulePath->getRealPath() . '/Console/Commands';
		$commandsPath = is_dir($commandsDir) ? new \FilesystemIterator($commandsDir, \FilesystemIterator::SKIP_DOTS) : [];
		foreach ($commandsPath as $commandFile) {
			$commands[] = '\App\Modules\\' . $modulePath->getFilename() . '\Console\Commands\\' . $commandFile->getBaseName('.php');
		}

		$this->commands($commands);
	}

	protected function checkDependencies($modulePath) {
		$dependencyPath = $modulePath->getRealPath() . '/module/dependencies.php';
		$dependencies = file_exists($dependencyPath) ? include($dependencyPath) : [];
		$notMet = [];
		foreach ($dependencies as $dependency) {
			if (!in_array($dependency, static::$registered)) {
				$matches = [];
				preg_match('/Modules\\\(.*?)\\\/', $dependency, $matches);
				$notMet[] = $matches[1];
			}
		}
		if (count($notMet)) {
			throw new DependencyException($modulePath->getFilename(), $notMet);
		}
	}

	protected function load($modulePath) {
		$this->checkDependencies($modulePath);
		$this->loadCommands($modulePath);
		$this->loadConfig($modulePath);
		$this->loadEvents($modulePath);
		$this->loadRoutes($modulePath);
		$this->loadViewsFrom($modulePath->getRealPath() . '/module/resources/views', Str::kebab($modulePath->getFilename()));
		$this->loadTranslationsFrom($modulePath->getRealPath() . '/module/resources/lang', Str::kebab($modulePath->getFilename()));
		$this->loadMigrationsFrom($modulePath->getRealPath() . '/module/database/migrations');
	}
}
