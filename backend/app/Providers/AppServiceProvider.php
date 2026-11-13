<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(\App\Contracts\HomologationCloudinaryClient::class, \App\Services\Photos\HomologationSdkClient::class);
        $this->app->bind(\App\Contracts\CloudinaryClient::class, \App\Services\Photos\SdkCloudinaryClient::class);
        $this->app->bind(\App\Contracts\PhotoStorage::class, function ($app) {
            return match (config('photos.provider')) {
                'local' => $app->make(\App\Services\Photos\LocalPhotoStorage::class),
                'cloudinary' => $app->make(\App\Services\Photos\CloudinaryPhotoStorage::class),
                default => throw new \RuntimeException('Unsupported photo storage provider.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        ResetPassword::createUrlUsing(fn ($user, $token) => rtrim(config('app.frontend_url'), '/').'/redefinir-senha?'.http_build_query(['token' => $token, 'email' => $user->email]));
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Password::defaults(fn () => Password::min(8)
            ->mixedCase()
            ->numbers()
            ->symbols());
    }
}
