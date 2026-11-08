<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Health;

use Illuminate\Container\Container;
use Pushery\SesInboundMail\Reconciliation;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails while a mail waits in transit past the time the reconciliation takes it, for an application
 * that runs `spatie/laravel-health`. Such a mail was neither reported nor reconciled: the scheduler
 * does not run, or its takeover keeps failing.
 */
final class NoMailIsStuckInTransitCheck extends Check
{
    public function run(): Result
    {
        $stuck = Container::getInstance()->make(Reconciliation::class)->stuck();
        $result = Result::make()->meta(['stuck' => count($stuck)]);

        if ($stuck === []) {
            return $result->ok()->shortSummary('Nothing stuck');
        }

        return $result
            ->shortSummary(sprintf('%d stuck', count($stuck)))
            ->failed(sprintf('%d mail(s) wait in transit for longer than %d minutes, one of them under %s. Run `php artisan ses-inbound-mail:reconcile`, and check that the scheduler runs and the queue takes the takeovers.', count($stuck), Reconciliation::AFTER_MINUTES, $stuck[0]));
    }
}
