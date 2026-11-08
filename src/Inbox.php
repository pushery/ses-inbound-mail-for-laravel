<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Pushery\SesInboundMail\Jobs\TakeOverTheMail;
use Pushery\SesInboundMail\Models\InboundMail;

/**
 * Takes in what SES reports: a row for each mail, once per id SES gave it, and the takeover of its
 * original queued for once the row is committed. A mail reported a second time, by a notification
 * SNS delivered again or by the reconciliation, changes nothing.
 */
final readonly class Inbox
{
    public function __construct(
        private Dispatcher $bus,
        private Repository $config,
        private Cache $cache,
    ) {}

    /**
     * Records a mail and queues its takeover, and answers whether it was new. The file SES writes to
     * test its access to the bucket is no mail and is recorded never.
     */
    public function announce(ReportedMail $mail): bool
    {
        if (Transit::isTheSetupNotification($mail->transitKey)) {
            return false;
        }

        $row = InboundMail::model()::query()->createOrFirst(['message_id' => $mail->messageId], InboundMail::attributesOf($mail));

        if (! $row->wasRecentlyCreated) {
            return false;
        }

        $this->takeOver($row);

        return true;
    }

    /**
     * Queues the takeover of a recorded mail on the connection and the queue the configuration names,
     * as often as it allows, and answers whether it did: while a takeover of the mail is queued or
     * waits for its next try, no second one is.
     */
    public function takeOver(InboundMail $mail): bool
    {
        $job = new TakeOverTheMail($mail->id);
        $connection = $this->config->get('ses-inbound-mail.queue.connection');
        $queue = $this->config->get('ses-inbound-mail.queue.name');
        $tries = $this->config->get('ses-inbound-mail.queue.tries');

        if (is_int($tries) && $tries >= 1) {
            $job->tries = $tries;
        }

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        if (! new UniqueLock($this->cache)->acquire($job)) {
            return false;
        }

        $this->bus->dispatch($job);

        return true;
    }
}
