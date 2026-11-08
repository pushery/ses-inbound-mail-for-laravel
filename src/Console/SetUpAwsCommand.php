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
 * finds on the machine, never with the application's own keys.
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

        if ($check && array_intersect($outcomes, [AwsSetup::MISSING, AwsSetup::DIFFERS]) !== []) {
            $this->error('The setup in AWS is not complete. Run the command without --check to complete it.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
