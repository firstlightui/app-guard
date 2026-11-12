<?php

declare(strict_types=1);

namespace FirstlightUI\AppGuard\Tests\Fixtures;

use FirstlightUI\AppGuard\Concerns\RequiresUnlock;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

final class ProtectedScreen extends NativeComponent
{
    use RequiresUnlock;

    public int $mounted = 0;

    public int $resumedCount = 0;

    public function mount(): void
    {
        $this->mounted++;
    }

    protected function resumed(): void
    {
        $this->resumedCount++;
    }

    public function render(): Element
    {
        return Column::make(Text::make('Vault'));
    }
}
