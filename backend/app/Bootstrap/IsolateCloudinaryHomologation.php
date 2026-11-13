<?php

namespace App\Bootstrap;

use Illuminate\Contracts\Foundation\Application;

class IsolateCloudinaryHomologation
{
    public function bootstrap(Application $app): void
    {
        if (in_array('cloudinary:homologate', $_SERVER['argv'] ?? [], true)) {
            // Runs after config loading (including config cache), before providers.
            $app['config']->set('database.connections', []);
            $app['config']->set('database.default', 'disabled');
            $app['config']->set('session.driver', 'array');
            $app['config']->set('cache.default', 'array');
            $app['config']->set('auth.passwords.users.store', 'array');
            $app['config']->set('logging.default', 'stderr');
        }
    }
}
