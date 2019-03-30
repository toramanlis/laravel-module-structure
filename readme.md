Laravel Module Structure
========================

This package helps you distribute your application logic into modules on Laravel framework.


Installation
-----------

You guessed it:

    composer require toramanlis/laravel-module-structure

That's it, you can simply start coding in modules by creating the directory `app/Modules`


Creating a module
-----------------

Each folder under the directory `app/Modules` will be the name of your module. You are gonna need a service provider to register you module for the app to load it.

Any Service provider under `app/Modules/<your-module>/`, at any level will do. For instance `app/Modules/CoolModule/Providers/Loader.php`:

    <?php

    namspace App\Modules\Providers;

    use Modules\ModuleServiceProvider;

    class Loader extends ModuleServiceProvider{

    }

Add it to the config file `config/app.php`:

    'providers' => [
        ...
        App\Modules\CoolModule\Providers\Loader::class,
    ],

Now the module is ready to use.

Directories
-----------

The directory logic is very similar with Laravel's with one exception. Anything that is usually in the folder `app` in Laravel is out in the open in the module folder `app/Modules/<your-module>` and everything else is in `app/Modules/<your-module>/module`.

Configuration
-------------

Each php file under the directory `app/Modules/<your-module>/modules/config` will work as a configuration file as expected. Only a special file name `module.php` has an exception. Any configuration in `app/Modules/<your-module>/modules/config/module.php` will have the prefix `modules.<module-name-in-kebab-case>.`.

Routes
-------

The file `app/Modules/<your-module>/module/routes.php` contains the route definitions that belong to the module. Thay will have the prefix `<module-name-in-kebab-case>::` and the namespace `App\Modules\Http\Controllers`.

You can define your routes just like you usually do in Laravel.

    <?php

    Route::middleware(['web',])->group(function() {
     Route::get('test', 'Test@test')->name('whataroute');
    });

Note that you will have to specify that the route uses the middleware `web`. The web routes file in laravel `app/routes/web.php` is already loaded under the `web` middleware, this one's not.


Views
-----

`app/Modules/<your-module>/module/resources/views` directory contains the views of the module. All the views under this directory will have the namespace `<module-name-in-kebab-case>`. This means if you have a blade template `app/Modules/module/resources/views/wonderful-view.blade.php`, you will need to use it like so:

    ...
    return view('<module-name-in-kebab-case>::wonderful-view',['data'=>$data]);

Localization
------------

Like the views the dedicated path is under the `resource` folder, `app/Modules/<your-module>/module/resources/lang` and everything defined under it has the namespace  `<module-name-in-kebab-case>`. Will be called like `__('<module-name-in-kebab-case>::something.something-else')`

Events
------

For module events, subscribiers are utilized. The file `app/Modules/<your-module>/module/events.php` includes an array of event [subsciber classes](https://laravel.com/docs/master/events#event-subscribers):

    <?php

    return [
        App\Modules\CoolModule\Subscribiers\SomthingHappens::class,
    ];


Migrations
----------

Migrations will under `app/Modules/<your-module>/module/database/migrations` will be loaded as expected. Unfortunately ht artisan command `make:migration` will not create any under said path because, you know, it's not aware of us.

Commands
--------

Unfortunately the path `app/Modules/<your-module>/Console/Commands` will NOT work as expected. In order to get it to work, you will need to load the path in `app/Console/Kernel.php` like so:

    ...

    protected function commands()
    {
        ...

        $this->load(app_path('Modules').'/<your-module>/Console/Commands');

        ...
    }
