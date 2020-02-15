<?php

namespace Modules;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factory;
use Modules\DependencyException;

class ModuleServiceProvider extends ServiceProvider
{
	protected static $modulesPath  = null;
	public static $registered      = [];
	protected static $activeModule = null;
	protected $modulePath          = [];
	protected $isActive            = null;
	protected $checkActive         = true;

	public function register()
	{
		static::$registered[] = static::class;
	}

	public function boot()
	{
		$staticFileName   = (new \ReflectionClass(static::class))->getFileName();
		$modulePathName   = substr($staticFileName, 0, strpos($staticFileName, DIRECTORY_SEPARATOR, strlen(base_path('app' . DIRECTORY_SEPARATOR . 'Modules')) + 1));
		$this->modulePath = new \SplFileInfo(realpath($modulePathName));
		$this->load();

		if ($this->checkActive) {
			// check if checkActive is true and no active module is found to prevent unnecessary work
			if (!self::$activeModule) {
				$this->determineisActive();
			} else {
				// a module is already found, set this module's isActive to false bool
				$this->isActive = false;
			}
		}

		if ($this->isActive) {
			$this->activeBoot();
		}
	}

	public function activeBoot() {}

	protected function loadRoutesToLumen(string $routesFile): void
	{
		app(\Laravel\Lumen\Routing\Router::class)->group([
			'namespace' => '\App\Modules\\' . $this->modulePath->getFilename() . '\Http\Controllers',
			'as'        => Str::kebab($this->modulePath->getFilename())
		], function () use ($routesFile) {
			$router = app(\Laravel\Lumen\Routing\Router::class);
			include ($routesFile);
		});
	}

	protected function loadRoutesToLaravel(string $routesFile): void
	{
		Route::name(Str::kebab($this->modulePath->getFilename()) . '::')
			->namespace('App\Modules\\' . $this->modulePath->getFilename() . '\Http\Controllers')->middleware('bindings')
			->group($routesFile);
	}

	protected function loadRoutes(): void
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

	public function loadConfig(): void
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

	protected function loadEvents(): void
	{
		$eventsFile = $this->modulePath->getRealPath() . '/module/events.php';
		if (file_exists($eventsFile)) {
			$subscribers = include $eventsFile;
			$this->loadSubscribers($subscribers ?? []);
		}
	}

	protected function loadFactories(): void
	{
		$this->app->make(Factory::class)->load($this->modulePath->getRealPath() . '/module/database/factories');
	}

	protected function loadSubscribers(array $subscriberInfo): void
	{
		foreach ($subscriberInfo as $subscriber) {
			app('events')->subscribe($subscriber);
		}
	}

	protected function loadCommands(): void
	{
		$commands     = [];
		$commandsDir  = $this->modulePath->getRealPath() . '/Console/Commands';
		$commandsPath = is_dir($commandsDir) ? new \FilesystemIterator($commandsDir, \FilesystemIterator::SKIP_DOTS) : [];
		foreach ($commandsPath as $commandFile) {
			$commands[] = '\App\Modules\\' . $this->modulePath->getFilename() . '\Console\Commands\\' . $commandFile->getBaseName('.php');
		}

		$this->commands($commands);
	}

	protected function checkDependencies(): void
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

	protected function load(): void
	{
		$realPath      = $this->modulePath->getRealPath();
		$kebabFilename = Str::kebab($this->modulePath->getFilename());

		$this->checkDependencies();
		$this->loadCommands();
		$this->loadConfig();
		$this->loadEvents();
		$this->loadFactories();

		if (!$this->app->routesAreCached()) {
			$this->loadRoutes();
		}

		$this->loadViewsFrom($realPath . '/module/resources/views', $kebabFilename);
		$this->loadTranslationsFrom($realPath . '/module/resources/lang', $kebabFilename);
		if (app()->runningInConsole()) {
			$this->loadMigrationsFrom($realPath . '/module/database/migrations');
		}
	}

	public function getIsActive():  ? bool
	{
		return $this->isActive;
	}

	protected function getModuleIndicatorForLumen() :  ? string
	{
		$dispatcher = false;

		try {
			// get registered dispatcher if it exists
			$dispatcher = app('dispatcher');
		} catch (\Exception $e) {
			// use Lumen's simpleDispatcher to create a dispatcher
			if (function_exists('FastRoute\simpleDispatcher')) {
				$dispatcher = \FastRoute\simpleDispatcher(function ($r) {
					$router = app('router');
					foreach ($router->getRoutes() as $route) {
						$r->addRoute($route['method'], $route['uri'], $route['action']);
					}
				});
			}
		}

		if ($dispatcher) {
			$request   = app('request');
			$method    = $request->getMethod();
			$pathInfo  = $request->getPathInfo();
			$routeData = $dispatcher->dispatch($method, $pathInfo);

			unset($dispatcher);

			if ($routeData && array_filter($routeData)) {
				$action          = $routeData[1];
				$moduleIndicator = null;
				if ($action['uses'] ?? false) {
					// handle controller case
					$moduleIndicator = $action['uses'];
				} else {
					// handle closure case
					$action = $action[0] ?? false;
					if ($action && is_object($action)) {
						$rf = new \ReflectionFunction($action);
						if ($closureThis = $rf->getClosureThis()) {
							// closure's $this
							if (is_object($closureThis)) {
								$moduleIndicator = get_class($closureThis);
							}
						}
						if (!$moduleIndicator) {
							// closure's file's location
							$moduleIndicator = str_replace('/', '\\', $rf->getFileName());
						}
					}
				}

				return $moduleIndicator ?: null;
			}
		}

		return null;
	}

	protected function getModuleIndicatorForLaravel():  ? string
	{
		$router = app('router');

		try {
			$found = $router->getRoutes()->match(app('request'));
		} catch (\Exception $e) {
			// prevent routing errors
		}

		if ($found ?? false) {
			if (isset($found->action['namespace'])) {
				return $found->action['namespace'];
			}
		}

		return null;
	}

	protected function determineisActive() : void
	{
		// get action's namespace or closure's namespace to check which module it belongs to
		if (app() instanceof \Illuminate\Foundation\Application) {
			$moduleIndicator = $this->getModuleIndicatorForLaravel();
		} else {
			$moduleIndicator = $this->getModuleIndicatorForLumen();
		}

		if ($moduleIndicator) {
			$targetModule = $this->getModuleFromString($moduleIndicator);
			if (static::class === $moduleIndicator || ($targetModule && $this->getModuleFromString(static::class) === $targetModule)) {
				$this->isActive     = true;
				self::$activeModule = $targetModule;
			} elseif (!$targetModule) {
				$this->isActive     = false;
				self::$activeModule = $moduleIndicator;
			} else {
				$this->isActive = false;
			}
		}
	}

	private function getModuleFromString(string $moduleIndicator): string
	{
		$search = app()->getNamespace() . 'Modules';

		// looks for app\Modules or namespace\Modules
		if (($pos = stripos($moduleIndicator, $search)) === false) {
			$search = 'app\\Modules';
			$pos    = stripos($moduleIndicator, $search);
		}

		// and extracts the next word before \
		if (false !== $pos) {
			$trimmed    = ltrim(substr($moduleIndicator, $pos + strlen($search)), '\\');
			$moduleInfo = explode('\\', $trimmed, 2);
			$moduleName = $moduleInfo[0] ?? '';
		}

		return $moduleName ?? '';
	}
}
