<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Events;

/**
 * A message the endpoint of Amazon SNS refused before it used anything of it, for an application that
 * keeps such refusals in a security log of its own: why, the topic it named, cut to 256 characters
 * because a stranger chooses its length, and the address it came from.
 */
final readonly class NotificationRefused
{
    /** A message that could not be read as one of SNS. */
    public const string UNREADABLE = 'unreadable';

    /** A message from a topic outside the list the endpoint takes. */
    public const string TOPIC = 'topic';

    /** A message whose signature is no valid one of SNS. */
    public const string SIGNATURE = 'signature';

    /** A subscription to confirm at an address that is none of SNS. */
    public const string SUBSCRIPTION_ADDRESS = 'subscription address';

    public function __construct(
        public string $reason,
        public ?string $topic,
        public ?string $address,
    ) {}
}
