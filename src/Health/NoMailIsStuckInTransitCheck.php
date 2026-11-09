<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Health;

use Illuminate\Container\Container;
use Pushery\SesInboundMail\Reconciliation;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails while a mail waits twice the time the reconciliation takes it, in transit or taken over and
 * never handed to the application, for an application that runs `spatie/laravel-health`. Such a
 * mail was not reconciled in two runs or more: the scheduler does not run, its takeover keeps
 * failing, or the application refuses it.
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
            ->failed(sprintf('%d mail(s) wait for longer than %d minutes to reach the application, one of them under %s. Run `php artisan ses-inbound-mail:reconcile`, and check that the scheduler runs, the queue takes the takeovers and the application takes the mail.', count($stuck), Reconciliation::STUCK_AFTER_MINUTES, $stuck[0]));
    }
}
