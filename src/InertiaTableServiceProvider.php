<?php

namespace harmonic\InertiaTable;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class InertiaTableServiceProvider extends ServiceProvider {
    protected $commands = [
        'harmonic\InertiaTable\Commands\MakeInertiaTable',
    ];

    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot() {
        // Easily create all the inertia routes
        Route::macro('inertiaTable', function (
            $name,
            string $controller = null,
            $singular = null,
            $index = 'index',
            $create = 'create',
            $edit = 'edit',
            $store = 'store',
            $update = 'update',
            $destroy = 'destroy',
            $restore = 'restore',
            $prefix = null,
        ) {
            $name = $prefix ? $prefix . '.' . strtolower($name) : strtolower($name);
            $singular ??=  (string)Str::singular($name);
            $controller ??= 'App\Http\Controllers\\' . ucfirst($name) . 'Controller';

            Route::group([
                'prefix' => '/' . $name,
            ], function () use (
                $controller,
                $name,
                $singular,
                $index,
                $store,
                $create,
                $edit,
                $update,
                $destroy,
                $restore,

            ) {
                Route::get("/")
                    ->name("$name")
                    ->uses($controller . "@$index")
                    ->middleware('remember');

                Route::get('/create')
                    ->name("$name.$create")
                    ->uses($controller . "@$create");

                Route::post('/')
                    ->name("$name.$store")
                    ->uses($controller . "@$store");

                Route::get("/$singular/$edit")
                    ->name("$name.$edit")
                    ->uses($controller . "@$edit");

                Route::put("/$singular")
                    ->name("$name.$update")
                    ->uses($controller . "@$update");

                Route::delete("/$singular")
                    ->name("$name.$destroy")
                    ->uses($controller . "@$destroy");

                Route::put("/$singular/$restore")
                    ->name("$name.$restore")
                    ->uses($controller . "@$restore");
            });
        });

        // Publishing is only necessary when using the CLI.
        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register() {
        $this->mergeConfigFrom(__DIR__ . '/../config/inertiatable.php', 'inertiatable');

        $this->commands($this->commands);

        // Register the service the package provides.
        $this->app->singleton('inertiatable', function ($app) {
            return new InertiaTable;
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides() {
        return ['inertiatable'];
    }

    /**
     * Console-specific booting.
     *
     * @return void
     */
    protected function bootForConsole() {
        // Publishing the configuration file.
        $this->publishes([
            __DIR__ . '/../config/inertiatable.php' => config_path('inertiatable.php'),
        ], 'inertiatable.config');
    }
}
