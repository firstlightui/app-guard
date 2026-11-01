<?php

declare(strict_types=1);

namespace WojtJanowski\AppGuard\Tests;

use Native\Mobile\NativeServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use WojtJanowski\AppGuard\AppGuardServiceProvider;

abstract class TestCase extends BaseTestCase
{
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
    }
}
