<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Health;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Date;
use Pushery\SesInboundMail\Models\InboundMail;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails while a mail the prune should have deleted still has its row, for an application that runs
 * `spatie/laravel-health` and sets a retention. The prune runs once a day, so a mail counts only once
 * it is a day past the retention, and two hours more for a prune of many mails that is still running.
 * Such a mail means the scheduler does not run, or the prune fails before it reaches the mail.
 */
final class NoMailOutlivesItsRetentionCheck extends Check
{
    /** How long past the retention a mail is left to the prune: the day until it runs, and two hours for it to end. */
    public const int GRACE_HOURS = 26;

    public function run(): Result
    {
        $days = Container::getInstance()->make(Repository::class)->get('ses-inbound-mail.retention.days');

        if (! is_int($days) || $days < 1) {
            return Result::make()->ok()->shortSummary('No retention set');
        }

        // The prune deletes what the model's prunable() names, so a mail it keeps is never counted.
        $overdue = InboundMail::resolve()->prunable()
            ->where('handed_over_at', '<=', InboundMail::boundary(Date::now()->subDays($days)->subHours(self::GRACE_HOURS)))
            ->exists();

        if (! $overdue) {
            return Result::make()->ok()->shortSummary('Nothing overdue');
        }

        return Result::make()
            ->shortSummary('Overdue')
            ->failed(sprintf('A mail handed to the application still has its row %d hours after its retention of %d days ran out. Run `php artisan model:prune --model="%s"`, and check that the scheduler runs and the prune ends without an error.', self::GRACE_HOURS, $days, InboundMail::model()));
    }
}
