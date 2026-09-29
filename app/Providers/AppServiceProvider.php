<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('login:email:'.$email.'|'.$request->ip()),
                Limit::perMinute(30)->by('login:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('password-reset', function (Request $request): array {
            $email = strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(3)->by('password-reset:email:'.$email.'|'.$request->ip()),
                Limit::perMinute(15)->by('password-reset:ip:'.$request->ip()),
            ];
        });
    }
}
