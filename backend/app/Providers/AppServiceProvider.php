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
        //
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
