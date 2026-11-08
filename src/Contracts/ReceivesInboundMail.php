<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Contracts;

use Pushery\SesInboundMail\ReceivedMail;

/**
 * What the application does with a mail once its original lies on the application's own disk: open
 * a conversation, file a document, anything. The application binds it in a provider.
 *
 * It is called again for the same mail where it threw, until it returns, so it treats a mail it has
 * seen before as done. The original stays on the application's disk either way.
 */
interface ReceivesInboundMail
{
    public function receive(ReceivedMail $mail): void;
}
