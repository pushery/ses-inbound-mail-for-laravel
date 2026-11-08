<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use Pushery\SesInboundMail\Models\InboundMail;
use RuntimeException;

/**
 * Where Amazon SES stores every incoming mail until it is taken over: a bucket at AWS, a disk here,
 * with the id SES gave a mail as the last part of its key.
 *
 * Transit holds nothing but mail in transit. Its lifecycle removes whatever is older than fourteen
 * days, so the original of a mail goes to the application's own disk before anything else happens
 * to it, and nothing is set aside in transit for a person to look at.
 */
final readonly class Transit
{
    /** The file SES writes into the bucket when a receipt rule is saved, to test its access. */
    public const string SETUP_NOTIFICATION = 'AMAZON_SES_SETUP_NOTIFICATION';

    /** How many bytes are read from a disk at a time. */
    private const int CHUNK = 65536;

    public function __construct(
        private Factory $disks,
        private Repository $config,
    ) {}

    /** The disk SES stores the mails in transit on. */
    public function disk(): Filesystem
    {
        return $this->disks->disk($this->string('ses-inbound-mail.transit_disk', 'ses-transit'));
    }

    /** Whether a key in transit is the file SES writes to test its access, which is no mail. */
    public static function isTheSetupNotification(string $key): bool
    {
        return basename($key) === self::SETUP_NOTIFICATION;
    }

    /**
     * Takes the original of a mail over from transit: copies it byte for byte onto the archive disk,
     * reads the copy back and holds its SHA-256 and size against the original's, and only then
     * deletes it in transit. A mail taken over before is left as it is, and one whose deletion in
     * transit went through while its row was not saved is completed from its copy.
     *
     * @throws RuntimeException where the original cannot be read, or the copy differs from it
     */
    public function takeOver(InboundMail $mail): void
    {
        if ($mail->isTakenOver()) {
            return;
        }

        $transit = $this->disk();
        $diskName = $this->string('ses-inbound-mail.archive.disk', 'local');
        $archive = $this->disks->disk($diskName);
        $path = $this->archivePathOf($mail->message_id);

        if ($transit->exists($mail->transit_key)) {
            [$sha256, $size] = $this->copy($transit, $mail->transit_key, $archive, $path);
        } elseif ($archive->exists($path)) {
            [$sha256, $size] = $this->digestOf($archive, $path);
        } else {
            throw new RuntimeException(sprintf('The mail %s is neither in transit under %s nor on the disk %s under %s.', $mail->message_id, $mail->transit_key, $diskName, $path));
        }

        $mail->forceFill([
            'archive_disk' => $diskName,
            'archive_path' => $path,
            'sha256' => $sha256,
            'size' => $size,
            'taken_over_at' => Date::now(),
        ])->save();

        $transit->delete($mail->transit_key);
    }

    /**
     * Where the original of a mail lies on the archive disk: under the directory the configuration
     * names, at the top of the disk where it names an empty one, and under `inbound-mail` where it
     * names no text at all.
     */
    public function archivePathOf(string $messageId): string
    {
        $configured = $this->config->get('ses-inbound-mail.archive.directory');
        $directory = trim(is_string($configured) ? $configured : 'inbound-mail', '/');

        return ($directory === '' ? '' : $directory.'/').$messageId.'.eml';
    }

    /**
     * Copies a file from one disk to another and answers the SHA-256 and the size of the copy, read
     * back from the disk it was written to.
     *
     * @return array{string, int}
     *
     * @throws RuntimeException where the file cannot be read, or the copy differs from it
     */
    private function copy(Filesystem $from, string $key, Filesystem $to, string $path): array
    {
        $source = $from->readStream($key);

        if (! is_resource($source)) {
            throw new RuntimeException(sprintf('The mail in transit under %s could not be read.', $key));
        }

        try {
            // php://temp is memory that moves to a temporary file once it grows, so it fails to open
            // only where the process can open no file at all.
            $buffer = fopen('php://temp', 'w+b') ?: throw new RuntimeException('No temporary stream could be opened for the copy of a mail.');

            try {
                [$sha256, $size] = $this->pipe($source, $buffer);
                rewind($buffer);
                $to->writeStream($path, $buffer);
            } finally {
                fclose($buffer);
            }
        } finally {
            fclose($source);
        }

        [$copied, $copiedSize] = $this->digestOf($to, $path);

        if ($copied !== $sha256 || $copiedSize !== $size) {
            $to->delete($path);

            throw new RuntimeException(sprintf('The copy of the mail under %s differs from its original, and the original stays in transit.', $key));
        }

        return [$sha256, $size];
    }

    /**
     * The SHA-256 and the size of a file on a disk.
     *
     * @return array{string, int}
     *
     * @throws RuntimeException where the file cannot be read
     */
    private function digestOf(Filesystem $disk, string $path): array
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException(sprintf('The file %s could not be read.', $path));
        }

        try {
            return $this->pipe($stream, null);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Reads a stream to its end, writing every chunk to a second one where it is given, and answers
     * the SHA-256 and the number of the bytes read.
     *
     * @param  resource  $from
     * @param  resource|null  $to
     * @return array{string, int}
     */
    private function pipe(mixed $from, mixed $to): array
    {
        $context = hash_init('sha256');
        $size = 0;

        while (! feof($from)) {
            $chunk = fread($from, self::CHUNK);

            if ($chunk === false) {
                throw new RuntimeException('A stream of a mail ended before its end could be read.');
            }

            hash_update($context, $chunk);
            $size += strlen($chunk);

            if ($to !== null) {
                fwrite($to, $chunk);
            }
        }

        return [hash_final($context), $size];
    }

    /** A setting of the package as text, or its default where it is no text. */
    private function string(string $key, string $default): string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
