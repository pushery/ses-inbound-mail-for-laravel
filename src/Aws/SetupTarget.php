<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Aws;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * What the setup in AWS is for, read from `ses-inbound-mail.aws`: the region and the account, the
 * bucket of the transit and the prefix SES stores under, the topic and the receipt rule of this
 * application, the recipients the rule takes, the endpoint SNS delivers to and the IAM user the
 * application runs under.
 */
final readonly class SetupTarget
{
    /**
     * @param  list<string>  $recipients
     */
    public function __construct(
        public string $region,
        public string $account,
        public string $bucket,
        public string $prefix,
        public string $topic,
        public string $rule,
        public array $recipients,
        public string $endpoint,
        public string $user,
    ) {}

    /**
     * The target the configuration names.
     *
     * @throws InvalidArgumentException naming every setting that is missing
     */
    public static function fromConfig(Repository $config): self
    {
        $text = static function (string $key) use ($config): string {
            $value = $config->get('ses-inbound-mail.aws.'.$key);

            return is_string($value) ? trim($value) : '';
        };

        $configured = $config->get('ses-inbound-mail.aws.recipients');
        $recipients = [];

        foreach (is_array($configured) ? $configured : [] as $recipient) {
            if (is_string($recipient) && trim($recipient) !== '') {
                $recipients[] = trim($recipient);
            }
        }

        $values = [
            'region' => $text('region'),
            'account' => $text('account'),
            'bucket' => $text('bucket'),
            'topic' => $text('topic'),
            'rule' => $text('rule'),
            'endpoint' => $text('endpoint'),
            'user' => $text('user'),
        ];
        $missing = array_keys(array_filter($values, static fn (string $value): bool => $value === ''));

        if ($recipients === []) {
            $missing[] = 'recipients';
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf('The setup in AWS needs ses-inbound-mail.aws.%s.', implode(', ses-inbound-mail.aws.', $missing)));
        }

        return new self($values['region'], $values['account'], $values['bucket'], $text('prefix'), $values['topic'], $values['rule'], $recipients, $values['endpoint'], $values['user']);
    }

    /** The ARN of the topic. */
    public function topicArn(): string
    {
        return sprintf('arn:aws:sns:%s:%s:%s', $this->region, $this->account, $this->topic);
    }

    /** The ARN of the receipt rule in the rule set every application of the account shares. */
    public function ruleArn(): string
    {
        return sprintf('arn:aws:ses:%s:%s:receipt-rule-set/%s:receipt-rule/%s', $this->region, $this->account, AwsSetup::RULE_SET, $this->rule);
    }
}
