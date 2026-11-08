<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | SES Inbound Mail for Laravel
    |--------------------------------------------------------------------------
    |
    | Amazon SES receives a mail, stores it in a bucket, the transit, and has Amazon SNS report it
    | to this application. The package takes the original over onto a disk of the application and
    | hands the mail to it. Published to config/ses-inbound-mail.php with
    | `php artisan vendor:publish --tag=ses-inbound-mail-config`.
    |
    | Read environment values with env() here and nowhere else: outside a config file env()
    | returns null once the application caches its config.
    |
    */

    /*
    | The topics of Amazon SNS the endpoint takes messages from, by their ARN, separated by commas
    | in SES_INBOUND_MAIL_TOPICS. A message from any other topic is refused with 403, and so is
    | every message while the list is empty.
    */
    'topics' => is_string($topics = env('SES_INBOUND_MAIL_TOPICS'))
        ? array_values(array_filter(array_map(trim(...), explode(',', $topics)), static fn (string $topic): bool => $topic !== ''))
        : [],

    /*
    | Where SNS delivers its messages: the path of the endpoint, the host it answers on (null for
    | every host, or the API host of the application) and its middleware. The endpoint checks the
    | signature of every message itself, so it needs no session, no CSRF token and no sign-in.
    */
    'route' => [
        'path' => is_string($path = env('SES_INBOUND_MAIL_PATH')) && trim($path, " \t/") !== '' ? trim($path, " \t/") : 'ses-inbound-mail/notifications',
        'domain' => is_string($domain = env('SES_INBOUND_MAIL_DOMAIN')) && trim($domain) !== '' ? trim($domain) : null,
        'middleware' => [],
    ],

    /*
    | The disk SES stores the mails on until they are taken over: the bucket of the transit.
    */
    'transit_disk' => is_string($transit = env('SES_INBOUND_MAIL_TRANSIT_DISK')) && trim($transit) !== '' ? trim($transit) : 'ses-transit',

    /*
    | Where the original of every mail goes, byte for byte, before the application receives it: a
    | disk of the application and a directory on it. The transit keeps nothing for a person to
    | look at, because its lifecycle removes whatever is older than fourteen days.
    */
    'archive' => [
        'disk' => is_string($archive = env('SES_INBOUND_MAIL_ARCHIVE_DISK')) && trim($archive) !== '' ? trim($archive) : 'local',
        'directory' => is_string($directory = env('SES_INBOUND_MAIL_ARCHIVE_DIRECTORY')) ? trim($directory, " \t/") : 'inbound-mail',
    ],

    /*
    | The queue the takeover of a mail runs on, and how often it is tried before it fails. A takeover
    | that fails for good leaves the mail in transit, where the reconciliation finds it again.
    */
    'queue' => [
        'connection' => is_string($connection = env('SES_INBOUND_MAIL_QUEUE_CONNECTION')) && trim($connection) !== '' ? trim($connection) : null,
        'name' => is_string($queue = env('SES_INBOUND_MAIL_QUEUE')) && trim($queue) !== '' ? trim($queue) : null,
        'tries' => is_numeric($tries = env('SES_INBOUND_MAIL_TAKEOVER_TRIES')) && (int) $tries >= 1
            ? (int) $tries
            : 5,
    ],

    /*
    | What `php artisan ses-inbound-mail:setup-aws` sets up in AWS, and checks with --check: the
    | bucket of the transit and the prefix SES stores under, the topic SES reports to, the receipt
    | rule of this application in the rule set `inbound` every application of the account shares,
    | the recipients it takes (addresses or domains, separated by commas), the endpoint SNS delivers
    | to, and the IAM user the application runs under. The lifecycle of the bucket is the same for
    | every application and is no setting.
    */
    'aws' => [
        'region' => is_string($region = env('SES_INBOUND_MAIL_AWS_REGION')) && trim($region) !== '' ? trim($region) : 'eu-central-1',
        'account' => env('SES_INBOUND_MAIL_AWS_ACCOUNT'),
        'bucket' => env('SES_INBOUND_MAIL_BUCKET'),
        'prefix' => is_string($prefix = env('SES_INBOUND_MAIL_PREFIX')) ? trim($prefix) : 'inbound/',
        'topic' => env('SES_INBOUND_MAIL_TOPIC'),
        'rule' => env('SES_INBOUND_MAIL_RULE'),
        'recipients' => is_string($recipients = env('SES_INBOUND_MAIL_RECIPIENTS'))
            ? array_values(array_filter(array_map(trim(...), explode(',', $recipients)), static fn (string $recipient): bool => $recipient !== ''))
            : [],
        'endpoint' => env('SES_INBOUND_MAIL_ENDPOINT'),
        'user' => env('SES_INBOUND_MAIL_IAM_USER'),
    ],

    /*
    | A model of the application in the place of a model of this package, keyed by the package's
    | class. A class that does not extend the package's is ignored.
    |
    | Pushery\SesInboundMail\Models\InboundMail::class => App\Models\InboundMail::class,
    */
    'models' => [],

];
