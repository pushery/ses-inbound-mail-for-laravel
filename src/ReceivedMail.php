<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail;

use DateTimeImmutable;

/**
 * A mail taken over from transit, as the application receives it: what SES reported about it, and
 * where its original lies on the application's own disk, byte for byte as SES stored it, with the
 * SHA-256 of those bytes and their number.
 */
final readonly class ReceivedMail
{
    /**
     * @param  list<string>  $recipients
     * @param  array<string, string>  $verdicts  SES's verdicts by name: `spf`, `dkim`, `dmarc`, `spam`, `virus` and `dmarc_policy`
     * @param  bool  $foundInTransit  whether the reconciliation found the mail in transit, rather than a notification reporting it; its verdicts then name spam and viruses only
     */
    public function __construct(
        public string $messageId,
        public ?string $sender,
        public array $recipients,
        public array $verdicts,
        public ?DateTimeImmutable $receivedAt,
        public string $disk,
        public string $path,
        public string $sha256,
        public int $size,
        public bool $foundInTransit = false,
    ) {}
}
