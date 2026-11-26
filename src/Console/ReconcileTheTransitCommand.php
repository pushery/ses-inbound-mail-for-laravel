<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pushery\SesInboundMail\Reconciliation;
use Pushery\SesInboundMail\Transit;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Handles every mail that waits in transit longer than the reconciliation allows, names how many
 * mails had each outcome, and fails where a mail failed or is lost. Scheduled by the package every
 * five minutes. Where the transit disk names no bucket, no mail can be in transit, and the command
 * says so and succeeds.
 */
#[Signature('ses-inbound-mail:reconcile')]
#[Description('Take over every mail that waits in transit longer than its notification should have taken.')]
final class ReconcileTheTransitCommand extends Command
{
    public function handle(Transit $transit, Reconciliation $reconciliation): int
    {
        if ($transit->namesNoBucket()) {
            $this->info(sprintf('No mail can be in transit: the disk %s names no bucket.', OutputFormatter::escape($transit->diskName())));

            return self::SUCCESS;
        }

        $outcomes = $reconciliation->run();

        if ($outcomes === []) {
            $this->info('No mail waits in transit past its time.');

            return self::SUCCESS;
        }

        foreach ($outcomes as $outcome => $count) {
            $this->line(sprintf('%s: %d', $outcome, $count));
        }

        return array_key_exists(Reconciliation::FAILED, $outcomes) || array_key_exists(Reconciliation::LOST, $outcomes) ? self::FAILURE : self::SUCCESS;
    }
}
