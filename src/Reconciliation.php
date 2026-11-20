<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
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
 * transit, and its takeover is queued once more as well. A mail recorded as long ago and never taken
 * over whose original is no longer in transit is reported as lost, run after run, until its original
 * is back under its key or its row is deleted. One mail that cannot be handled holds up none of the
 * others.
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

    public const string LOST = 'lost';

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
        $due = Date::now()->subMinutes(self::AFTER_MINUTES);
        $outcomes = [];
        $listed = [];

        foreach ($this->inTransit() as [$key, $writtenAt]) {
            $listed[$key] = true;

            if ($writtenAt > $due->getTimestamp()) {
                continue;
            }

            $outcome = $this->outcomeOf(fn (): string => $this->handle($key, $writtenAt));
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        }

        // The takeover copies the original and deletes it in transit before the application is
        // called, so a mail the application refused in every try is left with its row alone. A mail
        // never taken over is handled by its original in transit, above, and where that original is
        // gone, there is nothing left to take over.
        foreach ($this->notHandedOver($due)->lazyById() as $mail) {
            $takenOver = $mail->isTakenOver();

            if (! $takenOver && isset($listed[$mail->transit_key])) {
                continue;
            }

            $outcome = $takenOver
                ? $this->outcomeOf(fn (): string => $this->inbox->takeOver($mail) ? self::STARTED_AGAIN : self::QUEUED_ALREADY)
                : $this->lost($mail);
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        }

        ksort($outcomes);

        return $outcomes;
    }

    /**
     * Handles one mail in transit, whatever its age, and answers the outcome. Given the moment the
     * listing of the transit names for the key, as a run gives it, it asks the disk neither whether
     * the file is there nor when it was written. Given the key alone, it asks both.
     *
     * @throws RuntimeException where no mail waits under the key, or a file to delete stays in transit
     */
    public function handle(string $key, ?int $writtenAt = null): string
    {
        $disk = $this->transit->disk();

        if ($writtenAt === null && ! $disk->exists($key)) {
            throw new RuntimeException(sprintf('No mail waits in transit under %s.', $key));
        }

        if (Transit::isTheSetupNotification($key)) {
            return $this->remove($disk, $key);
        }

        $id = basename($key);

        if (! ReportedMail::isAnId($id)) {
            Log::warning('A file in transit has a name SES gives no mail, and is left alone.', ['transit_key' => $key]);

            return self::LEFT_ALONE;
        }

        $recorded = InboundMail::model()::query()->where('message_id', $id)->first();

        if ($recorded instanceof InboundMail && $recorded->isTakenOver()) {
            return $this->remove($disk, $key);
        }

        if ($recorded instanceof InboundMail) {
            return $this->inbox->takeOver($recorded) ? self::STARTED_AGAIN : self::QUEUED_ALREADY;
        }

        $envelope = StoredEnvelope::read($this->headOf($disk, $key));
        $this->inbox->announce(new ReportedMail($id, $envelope->sender, $envelope->recipients, $envelope->verdicts, $key, new DateTimeImmutable('@'.($writtenAt ?? $disk->lastModified($key))), foundInTransit: true));

        return self::RECORDED;
    }

    /**
     * The mails that wait longer than {@see self::STUCK_AFTER_MINUTES} minutes, by the key they have
     * or had in transit: in transit still, or never handed to the application since they were taken
     * over or, where they never were, since they were recorded.
     *
     * @return list<string>
     */
    public function stuck(): array
    {
        $due = Date::now()->subMinutes(self::STUCK_AFTER_MINUTES);
        $stuck = [];

        foreach ($this->inTransit() as [$key, $writtenAt]) {
            if (! Transit::isTheSetupNotification($key) && ReportedMail::isAnId(basename($key)) && $writtenAt <= $due->getTimestamp()) {
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
     * Every file in transit, by its key and the moment it was written, in the order of the keys.
     *
     * The moments come with the listing: a bucket names them in the same answer as the keys, while a
     * question about one file is a request of its own. Only a disk that offers no such listing is
     * asked file by file.
     *
     * @return list<array{string, int}>
     */
    private function inTransit(): array
    {
        $disk = $this->transit->disk();

        if (! $disk instanceof FilesystemAdapter) {
            return array_values(array_map(static fn (string $key): array => [$key, $disk->lastModified($key)], $disk->allFiles()));
        }

        $files = [];

        foreach ($disk->getDriver()->listContents('', true)->sortByPath() as $item) {
            if ($item->isFile()) {
                $files[] = [$item->path(), $item->lastModified() ?? $disk->lastModified($item->path())];
            }
        }

        return $files;
    }

    /**
     * The mails the application never took that were taken over from transit up to a moment, or
     * recorded up to it and never taken over.
     *
     * @return Builder<InboundMail>
     */
    private function notHandedOver(DateTimeInterface $until): Builder
    {
        $boundary = InboundMail::boundary($until);

        return InboundMail::model()::query()
            ->whereNull('handed_over_at')
            ->where(static fn (Builder $mails): Builder => $mails
                ->where('taken_over_at', '<=', $boundary)
                ->orWhere(static fn (Builder $never): Builder => $never->whereNull('taken_over_at')->where('created_at', '<=', $boundary)));
    }

    /**
     * Reports, by its id and its key, a mail recorded and never taken over whose original is no
     * longer in transit, where nothing is left to take over: the lifecycle of the transit deleted it
     * after fourteen days of failed takeovers, or the transit disk does not list the key SES reported.
     */
    private function lost(InboundMail $mail): string
    {
        Log::warning('A mail recorded and never taken over is no longer in transit.', ['message_id' => $mail->message_id, 'transit_key' => $mail->transit_key]);

        return self::LOST;
    }

    /**
     * Deletes a file in transit and answers {@see self::REMOVED}. Under `'throw' => false` a disk
     * answers a deletion it refused with false, and the file is then still there.
     *
     * @throws RuntimeException where the file stays in transit
     */
    private function remove(Filesystem $disk, string $key): string
    {
        if (! $disk->delete($key) && $disk->exists($key)) {
            throw new RuntimeException(sprintf('The file in transit under %s could not be deleted.', $key));
        }

        return self::REMOVED;
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
