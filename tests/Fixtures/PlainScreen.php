<?php

declare(strict_types=1);

namespace FirstlightUI\AppGuard\Tests\Fixtures;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Stands in for the app's lock, setup and settings screens: none of them use RequiresUnlock.
 */
final class PlainScreen extends NativeComponent
{
    public function render(): Element
    {
        return Column::make(Text::make('Plain'));
    }
}
