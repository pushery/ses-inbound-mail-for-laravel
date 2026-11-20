<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Aws;

use Aws\Iam\IamClient;
use Aws\S3\S3Client;
use Aws\Ses\SesClient;
use Aws\Sns\SnsClient;

/**
 * The clients of AWS the setup talks to, all in the region of the setup and with the credentials the
 * SDK finds for the process: `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` from the environment
 * first, then a profile, then the role of the machine. Laravel loads the application's `.env` into
 * that environment, so keys it names there are the ones these clients use, `AWS_PROFILE` set or not.
 * The setup needs the keys of an administrator, set in front of the command, where the `.env` does
 * not override them.
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
