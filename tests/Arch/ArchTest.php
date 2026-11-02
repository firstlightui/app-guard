<?php

arch('the plugin never blocks the PHP thread by sleeping')
    ->expect(['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'])
    ->not->toBeUsed();

arch('plugin code declares strict types')
    ->expect('FirstlightUI\AppGuard')
    ->toUseStrictTypes();
