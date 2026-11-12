<?php

declare(strict_types=1);

namespace FirstlightUI\AppGuard;

use FirstlightUI\AppGuard\Events\Foregrounded;
use FirstlightUI\AppGuard\Http\Middleware\EnsureUnlocked;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AppGuardServiceProvider extends ServiceProvider
{
    public const string MIDDLEWARE = 'app-lock';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/app-guard.php', 'app-guard');

        $this->app->singleton(AppGuard::class);
        $this->app->singleton(AppLock::class);
        $this->app->singleton(Pin::class);

        // An app that keeps lock choices in its own settings binds LockSettings itself.
        $this->app->singletonIf(LockSettings::class, fn () => new FileLockSettings(
            (string) config('app-guard.lock.settings_path'),
            (bool) config('app-guard.lock.enabled', true),
            (int) config('app-guard.lock.timeout', 60),
            (bool) config('app-guard.lock.privacy_cover', true),
        ));
    }

    public function boot(Router $router): void
    {
        $this->publishes([
            __DIR__.'/../config/app-guard.php' => config_path('app-guard.php'),
        ], 'app-guard-config');

        $router->aliasMiddleware(self::MIDDLEWARE, EnsureUnlocked::class);

        // Inert until AppLock::boot() has run, so an app that drives the shield itself keeps control of it.
        Event::listen(Foregrounded::class, function (Foregrounded $event): void {
            $lock = $this->app->make(AppLock::class);

            if ($lock->booted()) {
                $lock->foregrounded($event->shielded);
            }
        });
    }
}
