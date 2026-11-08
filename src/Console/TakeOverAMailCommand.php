<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pushery\SesInboundMail\Reconciliation;
use RuntimeException;

/**
 * Takes over one mail in transit by its key, whatever its age: records it where no notification did,
 * queues its takeover once more where it stopped, and removes the copy of a mail taken over before.
 */
#[Signature('ses-inbound-mail:take-over {key : The key of the mail on the transit disk}')]
#[Description('Take over one mail in transit by its key, whatever its age.')]
final class TakeOverAMailCommand extends Command
{
    public function handle(Reconciliation $reconciliation): int
    {
        try {
            $outcome = $reconciliation->handle($this->argument('key'));
        } catch (RuntimeException $missing) {
            $this->error($missing->getMessage());

            return self::FAILURE;
        }

        $this->info(match ($outcome) {
            Reconciliation::RECORDED => 'The mail is recorded with the envelope its headers name, and its takeover is queued.',
            Reconciliation::STARTED_AGAIN => 'The mail was recorded before, and its takeover is queued once more.',
            Reconciliation::QUEUED_ALREADY => 'The mail was recorded before, and its takeover is queued already.',
            Reconciliation::REMOVED => 'The copy in transit is removed: the mail was taken over before, or it was the file SES writes to test its access.',
            default => 'The file has a name SES gives no mail, and is left alone.',
        });

        return $outcome === Reconciliation::LEFT_ALONE ? self::FAILURE : self::SUCCESS;
    }
}
