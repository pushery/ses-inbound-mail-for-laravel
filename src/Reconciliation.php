<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Pushery\SesInboundMail\Models\InboundMail;
use RuntimeException;
use Throwable;

/**
 * The net under the notifications of Amazon SNS.
 *
 * A mail that waits in transit longer than {@see self::AFTER_MINUTES} minutes was either never
 * reported, and is then recorded with the envelope its headers name and taken over, or its takeover
 * stopped, and is then queued once more, unless one is queued already. A mail taken over whose
 * original is still in transit loses
 * that copy, and the file SES writes to test its access to the bucket is removed. A mail taken over
 * as long ago that never reached the application is found by its row, since its original left the
 * transit, and its takeover is queued once more as well. One mail that cannot be handled holds up
 * none of the others.
 */
final readonly class Reconciliation
{
    /** How long a mail waits in transit before the reconciliation takes it, the same for every application. */
    public const int AFTER_MINUTES = 15;

    /**
     * How long a mail waits before it counts as stuck: twice the time the reconciliation waits, so a
     * mail that its next run takes in the ordinary way, up to five minutes later, is not reported first.
     */
    public const int STUCK_AFTER_MINUTES = 2 * self::AFTER_MINUTES;

    public const string RECORDED = 'recorded';

    public const string STARTED_AGAIN = 'started again';

    public const string QUEUED_ALREADY = 'queued already';

    public const string REMOVED = 'removed';

    public const string LEFT_ALONE = 'left alone';

    public const string FAILED = 'failed';

    public function __construct(
        private Transit $transit,
        private Inbox $inbox,
        private ExceptionHandler $exceptions,
    ) {}

    /**
     * Handles every mail that waits in transit past its time.
     *
     * @return array<string, int> how many mails had each outcome
     */
    public function run(): array
    {
        $disk = $this->transit->disk();
        $due = Date::now()->subMinutes(self::AFTER_MINUTES);
        $outcomes = [];

        foreach ($disk->allFiles() as $key) {
            if ($disk->lastModified($key) > $due->getTimestamp()) {
                continue;
            }

            $outcome = $this->outcomeOf(fn (): string => $this->handle($key));
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        }

        // The takeover copies the original and deletes it in transit before the application is
        // called, so a mail the application refused in every try is left with its row alone.
        foreach ($this->notHandedOver($due)->lazyById() as $mail) {
            $outcome = $this->outcomeOf(fn (): string => $this->inbox->takeOver($mail) ? self::STARTED_AGAIN : self::QUEUED_ALREADY);
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        }

        ksort($outcomes);

        return $outcomes;
    }

    /**
     * Handles one mail in transit, whatever its age, and answers the outcome.
     *
     * @throws RuntimeException where no mail waits under the key
     */
    public function handle(string $key): string
    {
        $disk = $this->transit->disk();

        if (! $disk->exists($key)) {
            throw new RuntimeException(sprintf('No mail waits in transit under %s.', $key));
        }

        if (Transit::isTheSetupNotification($key)) {
            $disk->delete($key);

            return self::REMOVED;
        }

        $id = basename($key);

        if (! ReportedMail::isAnId($id)) {
            Log::warning('A file in transit has a name SES gives no mail, and is left alone.', ['transit_key' => $key]);

            return self::LEFT_ALONE;
        }

        $recorded = InboundMail::model()::query()->where('message_id', $id)->first();

        if ($recorded instanceof InboundMail && $recorded->isTakenOver()) {
            $disk->delete($key);

            return self::REMOVED;
        }

        if ($recorded instanceof InboundMail) {
            return $this->inbox->takeOver($recorded) ? self::STARTED_AGAIN : self::QUEUED_ALREADY;
        }

        $envelope = StoredEnvelope::read($this->headOf($disk, $key));
        $this->inbox->announce(new ReportedMail($id, $envelope->sender, $envelope->recipients, $envelope->verdicts, $key, new DateTimeImmutable('@'.$disk->lastModified($key)), foundInTransit: true));

        return self::RECORDED;
    }

    /**
     * The mails that wait longer than {@see self::STUCK_AFTER_MINUTES} minutes, by the key they have
     * or had in transit: in transit still, or taken over and never handed to the application.
     *
     * @return list<string>
     */
    public function stuck(): array
    {
        $disk = $this->transit->disk();
        $due = Date::now()->subMinutes(self::STUCK_AFTER_MINUTES);
        $stuck = [];

        foreach ($disk->allFiles() as $key) {
            if (! Transit::isTheSetupNotification($key) && ReportedMail::isAnId(basename($key)) && $disk->lastModified($key) <= $due->getTimestamp()) {
                $stuck[] = $key;
            }
        }

        foreach ($this->notHandedOver($due)->pluck('transit_key') as $key) {
            if (is_string($key) && ! in_array($key, $stuck, true)) {
                $stuck[] = $key;
            }
        }

        return $stuck;
    }

    /**
     * The mails taken over from transit up to a moment that the application never took.
     *
     * @return Builder<InboundMail>
     */
    private function notHandedOver(DateTimeInterface $until): Builder
    {
        return InboundMail::model()::query()
            ->whereNotNull('taken_over_at')
            ->whereNull('handed_over_at')
            ->where('taken_over_at', '<=', $until);
    }

    /**
     * The outcome of handling one mail, or {@see self::FAILED} where handling it threw, reported.
     *
     * @param  Closure(): string  $handle
     */
    private function outcomeOf(Closure $handle): string
    {
        try {
            return $handle();
        } catch (Throwable $failure) {
            $this->exceptions->report($failure);

            return self::FAILED;
        }
    }

    /** The start of a stored mail, as much as holds its headers. */
    private function headOf(Filesystem $disk, string $key): string
    {
        $stream = $disk->readStream($key);

        if (! is_resource($stream)) {
            throw new RuntimeException(sprintf('The mail in transit under %s could not be read.', $key));
        }

        try {
            $head = stream_get_contents($stream, StoredEnvelope::HEADER_LIMIT);
        } finally {
            fclose($stream);
        }

        return is_string($head) ? $head : '';
    }
}
