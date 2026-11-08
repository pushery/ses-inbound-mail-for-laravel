<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail;

use DateTimeImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Filesystem\Filesystem;
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
 * that copy, and the file SES writes to test its access to the bucket is removed. One mail that cannot
 * be handled holds up none of the others.
 */
final readonly class Reconciliation
{
    /** How long a mail waits in transit before the reconciliation takes it, the same for every application. */
    public const int AFTER_MINUTES = 15;

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
        $due = Date::now()->subMinutes(self::AFTER_MINUTES)->getTimestamp();
        $outcomes = [];

        foreach ($disk->allFiles() as $key) {
            if ($disk->lastModified($key) > $due) {
                continue;
            }

            try {
                $outcome = $this->handle($key);
            } catch (Throwable $failure) {
                $this->exceptions->report($failure);
                $outcome = self::FAILED;
            }

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
        $this->inbox->announce(new ReportedMail($id, $envelope->sender, $envelope->recipients, [], $key, new DateTimeImmutable('@'.$disk->lastModified($key))));

        return self::RECORDED;
    }

    /**
     * The mails that wait in transit past the time the reconciliation takes them, by key.
     *
     * @return list<string>
     */
    public function stuck(): array
    {
        $disk = $this->transit->disk();
        $due = Date::now()->subMinutes(self::AFTER_MINUTES)->getTimestamp();
        $stuck = [];

        foreach ($disk->allFiles() as $key) {
            if (! Transit::isTheSetupNotification($key) && ReportedMail::isAnId(basename($key)) && $disk->lastModified($key) <= $due) {
                $stuck[] = $key;
            }
        }

        return $stuck;
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
