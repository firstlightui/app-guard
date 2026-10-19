<?php

declare(strict_types=1);

namespace WojtJanowski\AppGuard;

use Illuminate\Support\ServiceProvider;

final class AppGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AppGuard::class);
    }
}
