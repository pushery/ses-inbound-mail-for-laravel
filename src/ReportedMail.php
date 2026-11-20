<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail;

use DateTimeImmutable;

/**
 * A mail Amazon SES received and stored in transit, as SES reported it or, where no notification
 * arrived, as its headers name it: the id SES gave it, the sender and the recipients of the envelope,
 * SES's verdicts, the key of the stored mail in transit and when SES received it, where that is known.
 */
final readonly class ReportedMail
{
    /** The longest id a mail keeps, as SES writes its ids. */
    public const int LONGEST_ID = 255;

    /**
     * The longest address a recorded mail keeps, counted in bytes over the whole address. The number
     * is the 64 bytes RFC 5321 allows before the `@`, the `@` itself and the 255 bytes it allows after
     * it, and neither part is held to its own share. A longer address, reported by SES or read from
     * the headers of a mail no notification reported, is left out of the row.
     */
    public const int LONGEST_ADDRESS = 320;

    /** The characters an id of SES is written in, which keep it a single safe part of a path. */
    public const string ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/';

    /**
     * @param  list<string>  $recipients
     * @param  array<string, string>  $verdicts  SES's verdicts by name: `spf`, `dkim`, `dmarc`, `spam`, `virus` and `dmarc_policy`
     * @param  bool  $foundInTransit  whether the reconciliation found the mail in transit, rather than a notification reporting it
     */
    public function __construct(
        public string $messageId,
        public ?string $sender,
        public array $recipients,
        public array $verdicts,
        public string $transitKey,
        public ?DateTimeImmutable $receivedAt = null,
        public bool $foundInTransit = false,
    ) {}

    /** Whether an id is one this package keeps: no longer than SES writes them, and no part of a path. */
    public static function isAnId(string $id): bool
    {
        return strlen($id) <= self::LONGEST_ID && preg_match(self::ID_PATTERN, $id) === 1;
    }
}
