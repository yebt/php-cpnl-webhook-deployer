<?php

declare(strict_types=1);

namespace Deployer\Notifiers\Application\Port;

use Deployer\Notifiers\Application\NotificationFailed;
use Deployer\Notifiers\Domain\NotifierConfig;

/** Delivers a message through a configured channel. */
interface Notifier
{
    /**
     * @param string $html message using Telegram's HTML subset, already escaped
     * @throws NotificationFailed with a message that never contains secrets
     */
    public function send(NotifierConfig $channel, string $html): void;
}
