<?php

namespace Modules;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Modules\DependencyException;

class ModuleServiceProvider extends ServiceProvider
{
	protected static $modulesPath 	= null;
	public static $registered     	= [];
	protected $modulePath     		= [];

	public function register()
	{
		static::$registered[] = static::class;
	}

	/**
	 * Define your route model bindings, pattern filters, etc.
	 *
	 * @return void
	 */
	public function boot()
	{
		$staticFileName = (new \ReflectionClass(static::class))->getFileName();
		$modulePathName = substr($staticFileName, 0, strpos($staticFileName, DIRECTORY_SEPARATOR, strlen(base_path('app' . DIRECTORY_SEPARATOR . 'Modules')) + 1));
		$this->modulePath     = new \SplFileInfo(realpath($modulePathName));
		$this->load();
	}

	protected function loadRoutesToLumen(string $routesFile) : void
	{
		app(\Laravel\Lumen\Routing\Router::class)->group([
			'namespace' => '\App\Modules\\' . $this->modulePath->getFilename() . '\Http\Controllers',
			'as'        => Str::kebab($this->modulePath->getFilename())
		], function () use ($routesFile) {
			$router = app(\Laravel\Lumen\Routing\Router::class);
			include ($routesFile);
		});
	}

	protected function loadRoutesToLaravel(string $routesFile) : void
	{
		Route::name(Str::kebab($this->modulePath->getFilename()) . '::')
			->namespace('App\Modules\\' . $this->modulePath->getFilename() . '\Http\Controllers')
			->group($routesFile);
	}

	protected function loadRoutes() : void
	{
		$routesFile = $this->modulePath->getRealPath() . '/module/routes.php';
		if (file_exists($routesFile)) {
			if (app() instanceof \Illuminate\Foundation\Application) {
				$this->loadRoutesToLaravel($routesFile);
			} else {
				$this->loadRoutesToLumen($routesFile);
			}
		}
	}

	public function loadConfig() : void
	{
		$configDir = $this->modulePath->getRealPath() . '/module/config';
		$key       = 'modules.' . Str::kebab($this->modulePath->getFilename());
		config([$key => ['name' => $this->modulePath->getFilename()]]);
		if (is_dir($configDir)) {
			foreach (new \FilesystemIterator($configDir, \FilesystemIterator::SKIP_DOTS) as $configFile) {
				$configFileName = Str::kebab($configFile->getBasename('.php'));
				if ('module' == $configFileName) {
					$this->mergeConfigFrom($configFile->getPathname(), $key);
				} else {
					$config       = $this->app['config']->get($configFileName, []);
					$moduleConfig = include $configFile->getPathname();
					$this->app['config']->set($configFileName, array_merge($config, $moduleConfig));
				}
			}
		}
	}

	protected function loadEvents() : void
	{
		$eventsFile = $this->modulePath->getRealPath() . '/module/events.php';
		if (file_exists($eventsFile)) {
			$subscribers = include $eventsFile;
			$this->loadSubscribers($subscribers ?? []);
		}
	}

	protected function loadSubscribers(array $subscriberInfo) : void
	{
		foreach ($subscriberInfo as $subscriber) {
			app('events')->subscribe($subscriber);
		}
	}

	protected function loadCommands() : void
	{
		$commands     = [];
		$commandsDir  = $this->modulePath->getRealPath() . '/Console/Commands';
		$commandsPath = is_dir($commandsDir) ? new \FilesystemIterator($commandsDir, \FilesystemIterator::SKIP_DOTS) : [];
		foreach ($commandsPath as $commandFile) {
			$commands[] = '\App\Modules\\' . $this->modulePath->getFilename() . '\Console\Commands\\' . $commandFile->getBaseName('.php');
		}

		$this->commands($commands);
	}

	protected function checkDependencies() : void
	{
		$dependencyPath = $this->modulePath->getRealPath() . '/module/dependencies.php';
		$dependencies   = file_exists($dependencyPath) ? include $dependencyPath : [];
		$notMet         = [];
		foreach ($dependencies as $dependency) {
			if (!in_array($dependency, static::$registered)) {
				$matches = [];
				preg_match('/Modules\\\(.*?)\\\/', $dependency, $matches);
				$notMet[] = $matches[1];
			}
		}
		if (count($notMet)) {
			throw new DependencyException($this->modulePath->getFilename(), $notMet);
		}
	}

	protected function load() : void
	{
		$realPath 		= $this->modulePath->getRealPath();
		$kebabFilename 	= Str::kebab($this->modulePath->getFilename());

		$this->checkDependencies();
		$this->loadCommands();
		$this->loadConfig();
		$this->loadEvents();
		$this->loadRoutes();
		$this->loadViewsFrom($realPath . '/module/resources/views', $kebabFilename);
		$this->loadTranslationsFrom($realPath . '/module/resources/lang', $kebabFilename);
		if (app()->runningInConsole()) {
			$this->loadMigrationsFrom($realPath . '/module/database/migrations');
		}
	}
}
