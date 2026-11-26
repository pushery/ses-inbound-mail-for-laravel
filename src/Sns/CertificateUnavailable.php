<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Sns;

use RuntimeException;
use Throwable;

/**
 * The certificate of a message could not be fetched for a reason that says nothing about the message: its address did
 * not answer, or answered with a server error or 429. The endpoint answers such a message with 503, which SNS delivers
 * again, where a 403 would end its delivery for good.
 *
 * @internal
 */
final class CertificateUnavailable extends RuntimeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self('The signing certificate of Amazon SNS could not be fetched: '.$reason.'.', 0, $previous);
    }
}
