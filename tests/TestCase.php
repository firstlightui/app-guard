<?php

declare(strict_types=1);

namespace FirstlightUI\AppGuard\Tests;

use FirstlightUI\AppGuard\AppGuardServiceProvider;
use FirstlightUI\AppGuard\Tests\Fixtures\PlainScreen;
use FirstlightUI\AppGuard\Tests\Fixtures\ProtectedScreen;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Native\Mobile\NativeServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected string $settingsDirectory;

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            NativeServiceProvider::class,
            AppGuardServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['view']->addLocation(__DIR__.'/Fixtures/views');

        $this->settingsDirectory = sys_get_temp_dir().'/app-guard-tests/'.bin2hex(random_bytes(6));
        $app['config']->set('app-guard.lock.settings_path', $this->settingsDirectory.'/lock.json');
    }

    protected function defineRoutes($router): void
    {
        Route::middleware(AppGuardServiceProvider::MIDDLEWARE)->group(function () {
            Route::native('/vault', ProtectedScreen::class)->name('vault');
            Route::native('/lock', PlainScreen::class)->name('lock');
            Route::native('/lock/setup', PlainScreen::class)->name('lock.setup');
        });

        Route::native('/settings', PlainScreen::class)->name('settings');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->settingsDirectory);

        parent::tearDown();
    }
}
