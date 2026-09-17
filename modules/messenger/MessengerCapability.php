<?php

declare(strict_types=1);

namespace Modules\Messenger;

final class MessengerCapability
{
    public function id(): string
    {
        return 'workspace.messenger';
    }
}
