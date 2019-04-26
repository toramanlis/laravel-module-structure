<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ModuleServiceProvider extends ServiceProvider {

	protected static $modulesPath = null;

	/**
	 * Define your route model bindings, pattern filters, etc.
	 *
	 * @return void
	 */
	public function boot() {
		$staticFileName = (new \ReflectionClass(static::class))->getFileName();
		$modulePath = substr($staticFileName, 0, strpos($staticFileName, '/', strlen(base_path('app/Modules')) + 1));
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
					$moduleConfig = include($configFile->getPathname());
					foreach ($moduleConfig as $globalKey => $value) {
						$already = config($configFileName . '.' . $globalKey);
						$toMerge = $already ? (is_array($already) ? $already : [$already]) : [];
						config([$configFileName . '.' . $globalKey => array_merge($toMerge, is_array($value) ? $value : [$value])]);
					}
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
			Event::subscribe($subscriber);
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

	protected function load($path) {
		$pathIterator = new \SplFileInfo(realpath($path));
		$this->loadCommands($pathIterator);
		$this->loadConfig($pathIterator);
		$this->loadEvents($pathIterator);
		$this->loadRoutes($pathIterator);
		$this->loadViewsFrom($pathIterator->getRealPath() . '/module/resources/views', Str::kebab($pathIterator->getFilename()));
		$this->loadTranslationsFrom($pathIterator->getRealPath() . '/module/resources/lang', Str::kebab($pathIterator->getFilename()));
		$this->loadMigrationsFrom($pathIterator->getRealPath() . '/module/database/migrations');
	}

}
