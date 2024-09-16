<?php

declare(strict_types=1);

namespace Dew\TablestoreDriver;

use Dew\Acs\Tablestore\TablestoreInstance;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

final class TablestoreServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->booting(function () {
            $this->registerCacheDriver();
            $this->registerSessionDriver();
        });
    }

    /**
     * Register the Tablestore cache driver.
     */
    private function registerCacheDriver(): void
    {
        Cache::extend('tablestore', function ($app, $config) {
            $client = new TablestoreInstance([
                'credentials' => [
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                    'token' => $config['token'] ?? null,
                ],
                'instance' => $config['instance'] ?? null,
                'endpoint' => $config['endpoint'],
            ]);

            return Cache::repository(
                new TablestoreStore(
                    $client,
                    $config['table'],
                    $config['attributes']['key'] ?? 'key',
                    $config['attributes']['value'] ?? 'value',
                    $config['attributes']['expiration'] ?? 'expires_at',
                    $this->getPrefix($config)
                )
            );
        });
    }

    /**
     * Register the Tablestore session driver.
     */
    private function registerSessionDriver(): void
    {
        /** @var \Illuminate\Session\SessionManager */
        $manager = $this->app->make('session');

        $handler = fn ($app) => $this->createCacheHandler('tablestore');

        $manager->extend('tablestore', $handler->bindTo($manager, $manager));
    }
}
