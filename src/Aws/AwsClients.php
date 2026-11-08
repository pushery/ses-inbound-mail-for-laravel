<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Aws;

use Aws\Iam\IamClient;
use Aws\S3\S3Client;
use Aws\Ses\SesClient;
use Aws\Sns\SnsClient;

/**
 * The clients of AWS the setup talks to, all in the region of the setup and with the credentials the
 * SDK finds on its own: the environment, a profile or the role of the machine. The application's own
 * keys are not used, because they may only read and delete in the transit.
 */
final readonly class AwsClients
{
    /**
     * @param  array<string, mixed>  $options  passed to every client, `region` and `version` among them
     */
    public function __construct(private array $options) {}

    public function s3(): S3Client
    {
        return new S3Client($this->options);
    }

    public function sns(): SnsClient
    {
        return new SnsClient($this->options);
    }

    public function ses(): SesClient
    {
        return new SesClient($this->options);
    }

    public function iam(): IamClient
    {
        return new IamClient($this->options);
    }
}
