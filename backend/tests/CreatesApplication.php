<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        if (getenv('RUN_MONGODB_INTEGRATION') === '1') {
            $settings = [];
            foreach (['TEST_MONGODB_URI', 'TEST_MONGODB_DATABASE', 'TEST_MONGODB_CONFIRM_DATABASE', 'TEST_MONGODB_ISOLATED'] as $key) {
                $settings[$key] = getenv($key) ?: '';
            }
            [$uri, $database] = \Tests\Support\MongoIntegrationGuard::validate($settings);
            // Override even cached application config before service providers
            // boot. Never let the application's Atlas URI become a fallback.
            $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app) use ($uri, $database) {
                $app['config']->set('database.connections.mongodb.dsn', $uri);
                $app['config']->set('database.connections.mongodb.database', $database);
                $app['config']->set('database.default', 'mongodb');
                $app['config']->set('cache.default', 'array');
                $app['config']->set('session.driver', 'array');
                $app['config']->set('auth.passwords.users.store', 'array');
            });
        }

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
