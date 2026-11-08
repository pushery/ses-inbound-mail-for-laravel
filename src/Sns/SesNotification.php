<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Sns;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Pushery\SesInboundMail\ReportedMail;

/**
 * What a notification of Amazon SES says about a mail it received and stored in transit, read into
 * a reported mail: the id SES gave the mail, the sender of the envelope, the recipients the receipt
 * rule matched (`receipt.recipients`, or `mail.destination` where those are missing), SES's verdicts
 * on SPF, DKIM, DMARC, spam and viruses, the key of the stored mail, and when SES received it.
 *
 * A notification of another type, or of a mail SES did not store in a bucket, reports nothing to
 * take over and is read as null, and so is one whose id is no id SES writes or whose key no bucket
 * could hold.
 */
final class SesNotification
{
    /** The verdicts of a receipt, by the name a mail keeps each under. */
    private const array VERDICTS = [
        'spf' => 'spfVerdict',
        'dkim' => 'dkimVerdict',
        'dmarc' => 'dmarcVerdict',
        'spam' => 'spamVerdict',
        'virus' => 'virusVerdict',
    ];

    /** The longest key of an object in S3. */
    private const int LONGEST_KEY = 1024;

    /** The mail a notification reports, read from the `Message` of the SNS message, or null. */
    public static function mail(string $message): ?ReportedMail
    {
        $notification = self::map(json_decode($message, true));

        if (($notification['notificationType'] ?? null) !== 'Received') {
            return null;
        }

        $mail = self::map($notification['mail'] ?? null);
        $receipt = self::map($notification['receipt'] ?? null);
        $action = self::map($receipt['action'] ?? null);
        $id = self::text($mail['messageId'] ?? null);

        if ($id === null || ! ReportedMail::isAnId($id) || ($action['type'] ?? null) !== 'S3') {
            return null;
        }

        $key = self::text($action['objectKey'] ?? null) ?? $id;

        if (strlen($key) > self::LONGEST_KEY) {
            return null;
        }

        $recipients = self::texts($receipt['recipients'] ?? null);

        if ($recipients === []) {
            $recipients = self::texts($mail['destination'] ?? null);
        }

        return new ReportedMail($id, self::text($mail['source'] ?? null), $recipients, self::verdictsOf($receipt), $key, self::timeOf($mail['timestamp'] ?? null));
    }

    /**
     * @param  array<array-key, mixed>  $receipt
     * @return array<string, string>
     */
    private static function verdictsOf(array $receipt): array
    {
        $verdicts = [];

        foreach (self::VERDICTS as $verdict => $field) {
            $status = self::text(self::map($receipt[$field] ?? null)['status'] ?? null);

            if ($status !== null) {
                $verdicts[$verdict] = $status;
            }
        }

        $policy = self::text($receipt['dmarcPolicy'] ?? null);

        if ($policy !== null) {
            $verdicts['dmarc_policy'] = $policy;
        }

        return $verdicts;
    }

    /** @return array<array-key, mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** A time SES wrote as ISO 8601, in UTC, or null for none or one it is not. */
    private static function timeOf(mixed $value): ?DateTimeImmutable
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($text)->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private static function texts(mixed $value): array
    {
        $texts = [];

        foreach (self::map($value) as $item) {
            if (is_string($item) && $item !== '') {
                $texts[] = $item;
            }
        }

        return $texts;
    }
}
