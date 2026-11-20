<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Pushery\SesInboundMail\Aws\AwsClients;
use Pushery\SesInboundMail\Aws\AwsSetup;
use Pushery\SesInboundMail\Aws\SetupTarget;

/**
 * Sets up the receiving path in AWS, or checks it with `--check`, which writes nothing and fails where an
 * item is missing or differs. A second run changes nothing. It runs with the credentials the SDK
 * finds for the process, `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` from the environment ahead
 * of any profile, and that environment holds the application's `.env` as well: the keys of an
 * administrator go in front of the command, where the `.env` does not override them.
 *
 * A run fails as well while the endpoint does not take the topic, since no run can add it to the
 * application's configuration, and it names the ARN to add.
 */
#[Signature('ses-inbound-mail:setup-aws {--check : Read every item and write nothing}')]
#[Description('Set up the bucket, the topic, the receipt rule and the IAM policy of the receiving path in AWS, or check them.')]
final class SetUpAwsCommand extends Command
{
    public function handle(AwsClients $clients, Repository $config): int
    {
        try {
            $target = SetupTarget::fromConfig($config);
        } catch (InvalidArgumentException $incomplete) {
            $this->error($incomplete->getMessage());

            return self::FAILURE;
        }

        $check = $this->option('check') === true;
        $outcomes = new AwsSetup($clients, $target)->run($check);
        $rows = [];

        foreach ($outcomes as $item => $outcome) {
            $rows[] = [$item, $outcome];
        }

        $this->table(['Item', 'Outcome'], $rows);

        $taken = $outcomes[AwsSetup::ENDPOINT_TAKES_THE_TOPIC] === AwsSetup::OK;

        if (! $taken) {
            $this->error(sprintf('The endpoint takes no message from %s, which ses-inbound-mail.topics does not name. Add it to SES_INBOUND_MAIL_TOPICS, deploy the application with it, and run the setup again.', $target->topicArn()));
        }

        $inAws = array_diff_key($outcomes, [AwsSetup::ENDPOINT_TAKES_THE_TOPIC => true]);

        if ($check && array_intersect($inAws, [AwsSetup::MISSING, AwsSetup::DIFFERS]) !== []) {
            $this->error('The setup in AWS is not complete. Run the command without --check to complete it.');

            return self::FAILURE;
        }

        return $taken ? self::SUCCESS : self::FAILURE;
    }
}
