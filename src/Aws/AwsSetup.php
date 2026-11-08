<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Aws;

use Aws\Exception\AwsException;
use Aws\Iam\IamClient;
use Aws\ResultInterface;
use Aws\S3\S3Client;
use Aws\Ses\SesClient;
use Aws\Sns\SnsClient;
use JsonException;
use RuntimeException;

/**
 * The setup of the receiving path in AWS, the same for every application: the bucket of the transit, its
 * policy for SES, its lifecycle, the topic SES reports to and its policy, the subscription of the
 * application's endpoint, the receipt rule in the rule set every application of the account shares,
 * and an IAM policy that lets the application's user only read, list and delete in its own transit.
 *
 * Every item is read first and written only where it differs, so a second run changes nothing. A
 * check writes nothing and names every item that is missing or differs.
 */
final readonly class AwsSetup
{
    /** The rule set every application of the account shares. It is created and activated once, by hand. */
    public const string RULE_SET = 'inbound';

    /** After how many days the lifecycle removes a mail from the transit, as a net under the takeover. */
    public const int EXPIRE_AFTER_DAYS = 14;

    /** After how many days the lifecycle removes a version of a mail that is no longer the current one. */
    public const int NONCURRENT_AFTER_DAYS = 1;

    /** After how many days the lifecycle removes an upload that never finished. */
    public const int ABORT_UPLOADS_AFTER_DAYS = 1;

    /** The id of the lifecycle rule of this package, beside which a rule of another kind stays. */
    public const string LIFECYCLE_RULE = 'ses-inbound-mail';

    /** What S3 answers about an item of a bucket that does not exist, which a check meets on a fresh account. */
    private const string NO_BUCKET = 'NoSuchBucket';

    public const string OK = 'ok';

    public const string CREATED = 'created';

    public const string CHANGED = 'changed';

    public const string MISSING = 'missing';

    public const string DIFFERS = 'differs';

    public function __construct(
        private AwsClients $clients,
        private SetupTarget $target,
    ) {}

    /**
     * Sets every item up, or only reads them where asked, and answers what became of each.
     *
     * @return array<string, string> the outcome of each item by its name
     */
    public function run(bool $check = false): array
    {
        $s3 = $this->clients->s3();
        $sns = $this->clients->sns();

        return [
            'bucket' => $this->bucket($check, $s3),
            'public access block' => $this->publicAccessBlock($check, $s3),
            'bucket owner enforced' => $this->ownership($check, $s3),
            'encryption' => $this->encryption($check, $s3),
            'bucket policy' => $this->bucketPolicy($check, $s3),
            'lifecycle' => $this->lifecycle($check, $s3),
            'topic' => $this->topic($sns, $check),
            'topic policy' => $this->topicPolicy($sns, $check),
            'subscription' => $this->subscription($sns, $check),
            'receipt rule' => $this->receiptRule($this->clients->ses(), $check),
            'user policy' => $this->userPolicy($this->clients->iam(), $check),
        ];
    }

    private function bucket(bool $check, S3Client $s3): string
    {
        try {
            $s3->headBucket(['Bucket' => $this->target->bucket]);

            return self::OK;
        } catch (AwsException $missing) {
            if ($missing->getStatusCode() !== 404) {
                throw $missing;
            }
        }

        if ($check) {
            return self::MISSING;
        }

        // @phpstan-ignore argument.type (the SDK lists the regions it knew at its release; a newer one is a location S3 accepts all the same)
        $s3->createBucket($this->target->region === 'us-east-1'
            ? ['Bucket' => $this->target->bucket]
            : ['Bucket' => $this->target->bucket, 'CreateBucketConfiguration' => ['LocationConstraint' => $this->target->region]]);

        return self::CREATED;
    }

    private function publicAccessBlock(bool $check, S3Client $s3): string
    {
        $wanted = ['BlockPublicAcls' => true, 'IgnorePublicAcls' => true, 'BlockPublicPolicy' => true, 'RestrictPublicBuckets' => true];
        $current = $this->read(fn (): ResultInterface => $s3->getPublicAccessBlock(['Bucket' => $this->target->bucket]), 'NoSuchPublicAccessBlockConfiguration', self::NO_BUCKET);
        $configuration = $current?->get('PublicAccessBlockConfiguration');

        return $this->settle(is_array($configuration) && array_intersect_key($configuration, $wanted) == $wanted, ! $current instanceof ResultInterface, $check, function () use ($s3, $wanted): void {
            $s3->putPublicAccessBlock(['Bucket' => $this->target->bucket, 'PublicAccessBlockConfiguration' => $wanted]);
        });
    }

    private function ownership(bool $check, S3Client $s3): string
    {
        $current = $this->read(fn (): ResultInterface => $s3->getBucketOwnershipControls(['Bucket' => $this->target->bucket]), 'OwnershipControlsNotFoundError', self::NO_BUCKET);
        $rules = $current?->search('OwnershipControls.Rules[].ObjectOwnership');

        return $this->settle($rules === ['BucketOwnerEnforced'], ! $current instanceof ResultInterface, $check, function () use ($s3): void {
            $s3->putBucketOwnershipControls(['Bucket' => $this->target->bucket, 'OwnershipControls' => ['Rules' => [['ObjectOwnership' => 'BucketOwnerEnforced']]]]);
        });
    }

    private function encryption(bool $check, S3Client $s3): string
    {
        $current = $this->read(fn (): ResultInterface => $s3->getBucketEncryption(['Bucket' => $this->target->bucket]), 'ServerSideEncryptionConfigurationNotFoundError', self::NO_BUCKET);
        $algorithms = $current?->search('ServerSideEncryptionConfiguration.Rules[].ApplyServerSideEncryptionByDefault.SSEAlgorithm');

        return $this->settle($algorithms === ['AES256'], ! $current instanceof ResultInterface, $check, function () use ($s3): void {
            $s3->putBucketEncryption(['Bucket' => $this->target->bucket, 'ServerSideEncryptionConfiguration' => ['Rules' => [['ApplyServerSideEncryptionByDefault' => ['SSEAlgorithm' => 'AES256']]]]]);
        });
    }

    private function bucketPolicy(bool $check, S3Client $s3): string
    {
        $wanted = [
            'Version' => '2012-10-17',
            'Statement' => [[
                'Sid' => 'SesStoresTheMailOfItsRule',
                'Effect' => 'Allow',
                'Principal' => ['Service' => 'ses.amazonaws.com'],
                'Action' => 's3:PutObject',
                'Resource' => sprintf('arn:aws:s3:::%s/%s*', $this->target->bucket, $this->target->prefix),
                'Condition' => [
                    'StringEquals' => ['AWS:SourceAccount' => $this->target->account],
                    'ArnLike' => ['AWS:SourceArn' => $this->target->ruleArn()],
                ],
            ]],
        ];
        $current = $this->read(fn (): ResultInterface => $s3->getBucketPolicy(['Bucket' => $this->target->bucket]), 'NoSuchBucketPolicy', self::NO_BUCKET);

        return $this->settle($this->decoded($current?->get('Policy')) == $wanted, ! $current instanceof ResultInterface, $check, function () use ($s3, $wanted): void {
            $s3->putBucketPolicy(['Bucket' => $this->target->bucket, 'Policy' => $this->encoded($wanted)]);
        });
    }

    private function lifecycle(bool $check, S3Client $s3): string
    {
        $wanted = [
            'ID' => self::LIFECYCLE_RULE,
            'Status' => 'Enabled',
            'Filter' => ['Prefix' => $this->target->prefix],
            'Expiration' => ['Days' => self::EXPIRE_AFTER_DAYS],
            'NoncurrentVersionExpiration' => ['NoncurrentDays' => self::NONCURRENT_AFTER_DAYS],
            'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => self::ABORT_UPLOADS_AFTER_DAYS],
        ];
        $current = $this->read(fn (): ResultInterface => $s3->getBucketLifecycleConfiguration(['Bucket' => $this->target->bucket]), 'NoSuchLifecycleConfiguration', self::NO_BUCKET);
        $rules = $current?->get('Rules');
        $others = [];
        $ours = null;

        foreach (is_array($rules) ? $rules : [] as $rule) {
            if (is_array($rule) && ($rule['ID'] ?? null) === self::LIFECYCLE_RULE) {
                $ours = $rule;
            } elseif (is_array($rule)) {
                $others[] = $rule;
            }
        }

        // A rule of another kind on the bucket stays: the configuration is written whole, ours beside it.
        return $this->settle($ours == $wanted, $ours === null, $check, function () use ($s3, $others, $wanted): void {
            $s3->putBucketLifecycleConfiguration(['Bucket' => $this->target->bucket, 'LifecycleConfiguration' => ['Rules' => [...$others, $wanted]]]);
        });
    }

    private function topic(SnsClient $sns, bool $check): string
    {
        $current = $this->read(fn (): ResultInterface => $sns->getTopicAttributes(['TopicArn' => $this->target->topicArn()]), 'NotFound');

        return $this->settle(true, ! $current instanceof ResultInterface, $check, function () use ($sns): void {
            $sns->createTopic(['Name' => $this->target->topic]);
        });
    }

    private function topicPolicy(SnsClient $sns, bool $check): string
    {
        $wanted = [
            'Version' => '2012-10-17',
            'Statement' => [[
                'Sid' => 'SesReportsTheMailOfItsRule',
                'Effect' => 'Allow',
                'Principal' => ['Service' => 'ses.amazonaws.com'],
                'Action' => 'SNS:Publish',
                'Resource' => $this->target->topicArn(),
                'Condition' => [
                    'StringEquals' => ['AWS:SourceAccount' => $this->target->account],
                    'ArnLike' => ['AWS:SourceArn' => $this->target->ruleArn()],
                ],
            ]],
        ];
        $current = $this->read(fn (): ResultInterface => $sns->getTopicAttributes(['TopicArn' => $this->target->topicArn()]), 'NotFound');

        return $this->settle($this->decoded($current?->search('Attributes.Policy')) == $wanted, ! $current instanceof ResultInterface, $check, function () use ($sns, $wanted): void {
            $sns->setTopicAttributes(['TopicArn' => $this->target->topicArn(), 'AttributeName' => 'Policy', 'AttributeValue' => $this->encoded($wanted)]);
        });
    }

    private function subscription(SnsClient $sns, bool $check): string
    {
        $state = null;
        $token = null;

        do {
            $arguments = ['TopicArn' => $this->target->topicArn()];

            if ($token !== null) {
                $arguments['NextToken'] = $token;
            }

            $page = $this->read(static fn (): ResultInterface => $sns->listSubscriptionsByTopic($arguments), 'NotFound');
            $subscriptions = $page?->get('Subscriptions');

            foreach (is_array($subscriptions) ? $subscriptions : [] as $subscription) {
                if (is_array($subscription) && ($subscription['Protocol'] ?? null) === 'https' && ($subscription['Endpoint'] ?? null) === $this->target->endpoint) {
                    $state = ($subscription['SubscriptionArn'] ?? null) === 'PendingConfirmation' ? 'pending' : 'confirmed';
                }
            }

            $next = $page?->get('NextToken');
            $token = is_string($next) && $next !== '' ? $next : null;
        } while ($token !== null && $state === null);

        // A subscription that waits for its confirmation is asked again, which sends SNS's request anew.
        return $this->settle($state === 'confirmed', $state === null, $check, function () use ($sns): void {
            $sns->subscribe(['TopicArn' => $this->target->topicArn(), 'Protocol' => 'https', 'Endpoint' => $this->target->endpoint]);
        });
    }

    private function receiptRule(SesClient $ses, bool $check): string
    {
        $wanted = [
            'Name' => $this->target->rule,
            'Enabled' => true,
            'TlsPolicy' => 'Optional',
            'Recipients' => $this->target->recipients,
            'ScanEnabled' => true,
            'Actions' => [['S3Action' => ['BucketName' => $this->target->bucket, 'ObjectKeyPrefix' => $this->target->prefix, 'TopicArn' => $this->target->topicArn()]]],
        ];
        $set = $this->read(fn (): ResultInterface => $ses->describeReceiptRuleSet(['RuleSetName' => self::RULE_SET]), 'RuleSetDoesNotExist');

        if (! $set instanceof ResultInterface) {
            throw new RuntimeException(sprintf('The receipt rule set %s does not exist in %s. Create it once for the account and make it the active one, then run the setup again.', self::RULE_SET, $this->target->region));
        }

        $rules = $set->get('Rules');
        $current = null;

        foreach (is_array($rules) ? $rules : [] as $rule) {
            if (is_array($rule) && ($rule['Name'] ?? null) === $this->target->rule) {
                $current = array_intersect_key($rule, $wanted);
            }
        }

        return $this->settle($current == $wanted, $current === null, $check, static function () use ($ses, $wanted, $current): void {
            if ($current === null) {
                $ses->createReceiptRule(['RuleSetName' => self::RULE_SET, 'Rule' => $wanted]);
            } else {
                $ses->updateReceiptRule(['RuleSetName' => self::RULE_SET, 'Rule' => $wanted]);
            }
        });
    }

    private function userPolicy(IamClient $iam, bool $check): string
    {
        $name = 'ses-inbound-mail-'.$this->target->bucket;
        $wanted = [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Sid' => 'ReadAndDeleteTheTransit',
                    'Effect' => 'Allow',
                    'Action' => ['s3:GetObject', 's3:DeleteObject'],
                    'Resource' => sprintf('arn:aws:s3:::%s/*', $this->target->bucket),
                ],
                [
                    'Sid' => 'ListTheTransit',
                    'Effect' => 'Allow',
                    'Action' => 's3:ListBucket',
                    'Resource' => sprintf('arn:aws:s3:::%s', $this->target->bucket),
                ],
            ],
        ];
        $current = $this->read(fn (): ResultInterface => $iam->getUserPolicy(['UserName' => $this->target->user, 'PolicyName' => $name]), 'NoSuchEntity');
        $document = $current?->get('PolicyDocument');

        return $this->settle($this->decoded(is_string($document) ? urldecode($document) : null) == $wanted, ! $current instanceof ResultInterface, $check, function () use ($iam, $name, $wanted): void {
            $iam->putUserPolicy(['UserName' => $this->target->user, 'PolicyName' => $name, 'PolicyDocument' => $this->encoded($wanted)]);
        });
    }

    /**
     * The result of a read, or null where AWS answers with one of the codes that say the item does not
     * exist.
     *
     * @param  callable(): ResultInterface  $read
     */
    private function read(callable $read, string ...$missing): ?ResultInterface
    {
        try {
            return $read();
        } catch (AwsException $failure) {
            if (in_array($failure->getAwsErrorCode(), $missing, true)) {
                return null;
            }

            throw $failure;
        }
    }

    /**
     * The outcome of an item: nothing to do where it is as wanted, and otherwise the write, unless only
     * a check runs.
     *
     * @param  callable(): void  $write
     */
    private function settle(bool $asWanted, bool $missing, bool $check, callable $write): string
    {
        if ($asWanted && ! $missing) {
            return self::OK;
        }

        if ($check) {
            return $missing ? self::MISSING : self::DIFFERS;
        }

        $write();

        return $missing ? self::CREATED : self::CHANGED;
    }

    /** @return array<array-key, mixed>|null */
    private function decoded(mixed $json): ?array
    {
        if (! is_string($json) || $json === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param  array<array-key, mixed>  $document */
    private function encoded(array $document): string
    {
        return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
