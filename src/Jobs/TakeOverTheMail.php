<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Date;
use Pushery\SesInboundMail\Contracts\ReceivesInboundMail;
use Pushery\SesInboundMail\Inbox;
use Pushery\SesInboundMail\Models\InboundMail;
use Pushery\SesInboundMail\Transit;

/**
 * Takes the original of one recorded mail over from transit and hands the mail to the application.
 *
 * Tried up to five times, further apart each time. A run that finds the mail handed over does
 * nothing, and one that finds it taken over hands it over without copying it again, so the
 * application is called again only where it threw.
 *
 * One takeover of a mail is queued at a time. {@see Inbox::takeOver()}
 * takes its lock before it queues it, the worker gives the lock up once the job is done or has
 * failed for good, and the lock outlasts every try the job is given.
 */
final class TakeOverTheMail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** How often the job is tried before it fails. */
    public int $tries = 5;

    /** @var list<int> the seconds between two tries */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly int $mail)
    {
        $this->afterCommit();
    }

    /** The mail the lock of the takeover is held for. */
    public function uniqueId(): string
    {
        return (string) $this->mail;
    }

    /** How long the lock is held at most, in seconds: every wait between the tries, and an hour for the tries themselves. */
    public function uniqueFor(): int
    {
        $waits = $this->backoff;
        $last = $waits === [] ? 0 : $waits[array_key_last($waits)];
        $seconds = 3600;

        for ($try = 1; $try < $this->tries; $try++) {
            $seconds += $waits[$try - 1] ?? $last;
        }

        return $seconds;
    }

    public function handle(Transit $transit, ReceivesInboundMail $receiver): void
    {
        $mail = InboundMail::model()::query()->find($this->mail);

        if (! $mail instanceof InboundMail || $mail->handed_over_at !== null) {
            return;
        }

        $transit->takeOver($mail);
        $receiver->receive($mail->received());

        $mail->forceFill(['handed_over_at' => Date::now()])->save();
    }
}
