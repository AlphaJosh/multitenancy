<?php
namespace Ollieread\Multitenancy;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Ollieread\Multitenancy\Auth\DatabaseUserProvider;
use Ollieread\Multitenancy\Auth\SessionGuard;
use Ollieread\Multitenancy\Contracts\Provider;
use Ollieread\Multitenancy\Contracts\Tenant;
use Ollieread\Multitenancy\Exceptions\InvalidTenantException;
use Ollieread\Multitenancy\Providers\Database;
use Ollieread\Multitenancy\Providers\Eloquent;

/**
 * Class ServiceProvider
 *
 * @package Ollieread\Multitenancy
 */
class ServiceProvider extends BaseServiceProvider
{

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/multitenancy.php' => config_path('multitenancy.php')
        ], 'config');

        $this->registerAuth();
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $manager = new TenantManager($this->app);
        $manager->extend('eloquent', function ($app, $config) {
            return new Eloquent($config['model']);
        })->extend('database', function ($app, $config) {
            return new Database($app['db']->connection(), $config['table'], $config['identifiers']);
        });

        // Bind TenantManager
        $this->app->instance('multitenancy', $manager);
        // For injection
        $this->app->bind(TenantManager::class, function ($app) {
            return $app['multitenancy'];
        });
        // Bind Provider
        $this->app->bind('multitenancy.provider', function ($app) {
            return $app['multitenancy']->provider();
        });
        // For injection
        $this->app->bind(Provider::class, function ($app) {
            return $app['multitenancy']->provider();
        });
        // For Tenant injection
        $this->app->bind(Tenant::class, function ($app) {
            return $app['multitenancy']->tenant();
        });

        $this->app['db']->extend('multitenancy', function ($config, $name) use ($manager) {
            if ($manager->hasTenant()) {
                $config = $manager->parseConnection($config);
            }

            return $this->app['db.factory']->make($config, $name);
        });

        $manager->setConnectionParser(function ($config = [], Tenant $tenant) {
            if ($tenant) {
                $config['database'] = 'tenant_' . $tenant->id;
            }

            return $config;
        });
    }

    public function provides()
    {
        return ['multitenancy'];
    }

    public function registerAuth()
    {
        // Register the session guard
        Auth::extend('session.multi', function ($app, $name, array $config) {
            $guard = new SessionGuard($name, Auth::createUserProvider($config['provider']), $app['session.store']);
            $guard->setCookieJar($app['cookie']);
            $guard->setDispatcher($app['events']);
            $guard->setRequest($app->refresh('request', $guard, 'setRequest'));

            return $guard;
        });

        // Register the database provider
        Auth::provider('database.multi', function ($app, array $config) {
            return new DatabaseUserProvider($app['db']->connection, $app['hash'], $config['table']);
        });
    }
}