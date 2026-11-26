<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Sns;

use Aws\Sns\MessageValidator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * The certificates Amazon SNS signs its messages with, for the {@see MessageValidator}.
 *
 * The validator hands over only an address it has checked to be one of SNS's own, an `https`
 * address of `sns.<region>.amazonaws.com`, and the fetch follows no redirect away from it. A
 * certificate is fetched through the HTTP client and kept
 * for a day, because SNS signs with few of them and every incoming mail would otherwise fetch one. A
 * certificate that could not be fetched is not kept.
 */
final readonly class SigningCertificates
{
    /** How long a fetched certificate is kept, in seconds. */
    private const int KEPT_FOR = 86400;

    /** How long a fetch may take, in seconds. */
    private const int TIMEOUT = 5;

    public function __construct(
        private Factory $http,
        private Repository $cache,
    ) {}

    /**
     * The certificate at the address, or false where its address answered with none.
     *
     * @throws CertificateUnavailable where the address did not answer, or answered with a server error or 429,
     *                                which says nothing about the message the certificate is for
     */
    public function fetch(string $address): string|false
    {
        $key = 'ses-inbound-mail:sns-certificate:'.hash('sha256', $address);
        $kept = $this->cache->get($key);

        if (is_string($kept) && $kept !== '') {
            return $kept;
        }

        try {
            $response = $this->http->timeout(self::TIMEOUT)->withoutRedirecting()->get($address);
        } catch (Throwable $failure) {
            throw CertificateUnavailable::because('its address did not answer', $failure);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw CertificateUnavailable::because(sprintf('its address answered %d', $response->status()));
        }

        $certificate = $response->body();

        if (! $response->successful() || $certificate === '') {
            return false;
        }

        $this->cache->put($key, $certificate, self::KEPT_FOR);

        return $certificate;
    }
}
