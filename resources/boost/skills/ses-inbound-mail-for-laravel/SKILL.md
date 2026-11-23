---
name: ses-inbound-mail-for-laravel
description: >
  Install, configure, and apply the SES Inbound Mail for Laravel package in a Laravel
  application.
license: MIT
metadata:
  author: pushery
---

# SES Inbound Mail for Laravel

Use this skill when a Laravel application installs or integrates the
`pushery/ses-inbound-mail-for-laravel` package. The full reference is at
<https://docs.pushery.com/ses-inbound-mail-for-laravel/>.

## Primary Goal

Apply the package's public API in the smallest correct way for the consuming
application.

## Workflow

### 1. Install

```bash
composer require pushery/ses-inbound-mail-for-laravel
composer require league/flysystem-aws-s3-v3 "^3.0"
```

The service provider is registered automatically through package discovery. The second command
installs the Flysystem adapter for S3, which Laravel's `s3` driver needs for the transit disk;
skip it where the application has the adapter already.

### 2. Configure

Publish the configuration file:

```bash
php artisan vendor:publish --tag="ses-inbound-mail-config"
```

Every option in `config/ses-inbound-mail.php` is documented inline.

The other publish tags:

| Tag | Publishes |
|---|---|
| `ses-inbound-mail-migrations` | the migrations, in the steps below |
| `ses-inbound-mail` | every group at once, the migrations included |

The migrations run from the package with `php artisan migrate`, and the package keeps
registering them after they are published until the application calls `ignoreMigrations()`. To
keep them in the application instead, call
`\Pushery\SesInboundMail\SesInboundMailServiceProvider::ignoreMigrations(tablesExist: true)` in the
`register` method of one of the application's service providers before the application's first
`php artisan migrate`, then publish and run them:

```bash
php artisan vendor:publish --tag="ses-inbound-mail-migrations"
php artisan migrate
```

Without that call, the package goes on migrating its table `ses_inbound_mails` from its own files,
and the published copies, which Laravel's default configuration gives a new name, find the table
built. The same holds for the `ses-inbound-mail` tag, which publishes the migrations with every
other group. `tablesExist: true` keeps the reconciliation and the prune of the retention on the
schedule, since the application migrates the table they read; called without it,
`ignoreMigrations()` takes both off the schedule.

Publish the migrations once. A migration a later release adds, which its release notes name, is
copied from `vendor/pushery/ses-inbound-mail-for-laravel/database/migrations` into
`database/migrations`, renamed to start with today's date, and never by publishing the tag again,
which writes every migration a second time under a new date. Where `php artisan migrate` has
already run them from the package, copy the files from that directory under their own names
instead of publishing them, so the `migrations` table recognizes them as run.

### 3. Apply the package

Bind `Pushery\SesInboundMail\Contracts\ReceivesInboundMail` in a service provider of the
application. Its `receive(ReceivedMail $mail)` gets the id SES gave the mail, the envelope, the
verdicts of SES and the disk, path, SHA-256 and size of the original, which the package copied
onto the application's disk before the call. It may be called again for the same mail where it
threw, so it treats a mail it has seen before as done.

Configure the transit disk (`ses-inbound-mail.transit_disk`, an `s3` disk on the bucket SES
stores into) and the archive disk (`ses-inbound-mail.archive.disk`). The archive holds the only
copy of a mail once it is taken over, so it is a disk every web server and worker reads and that
survives a deploy, such as a bucket of its own; the default `local` suits only a single server
whose disk outlasts its deploys. Name the allowed topics in
`SES_INBOUND_MAIL_TOPICS`, and set up the receiving path with `php artisan ses-inbound-mail:setup-aws`,
checked with `--check`. The topic the setup creates,
`arn:aws:sns:<SES_INBOUND_MAIL_AWS_REGION>:<SES_INBOUND_MAIL_AWS_ACCOUNT>:<SES_INBOUND_MAIL_TOPIC>`,
is one of the allowed topics, deployed before the setup runs: the endpoint refuses the
confirmation of a subscription to a topic outside the list, and the setup fails while the list
lacks it. The setup leaves three things to the owner of the AWS account, each done once in the
region of `SES_INBOUND_MAIL_AWS_REGION`: the domain is a verified identity of SES, its MX record
is `10 inbound-smtp.<region>.amazonaws.com`, and the receipt rule set `inbound` exists and is the
active one. "Before you start" in the documentation gives the commands. The package schedules
`php artisan ses-inbound-mail:reconcile` every
five minutes, which takes every mail that waits in the transit longer than fifteen minutes. To
take one mail by its key in the transit, whatever its age, run
`php artisan ses-inbound-mail:take-over <key>`.

## Examples

```php
namespace App\Mail;

use App\Models\Document;
use Pushery\SesInboundMail\Contracts\ReceivesInboundMail;
use Pushery\SesInboundMail\ReceivedMail;

final class FileTheDocument implements ReceivesInboundMail
{
    public function receive(ReceivedMail $mail): void
    {
        if (Document::query()->where('ses_message_id', $mail->messageId)->exists()) {
            return;
        }

        Document::query()->create([
            'ses_message_id' => $mail->messageId,
            'disk' => $mail->disk,
            'path' => $mail->path,
            'sha256' => $mail->sha256,
        ]);
    }
}
```

## Anti-Patterns

- Reading the mail from the transit disk. Its lifecycle removes everything after fourteen days;
  read the original from `$mail->disk` and `$mail->path` instead.
- Setting a mail aside in the transit for a person to look at. Keep it on the application's
  own disk.
- Putting the endpoint behind the `web` group or a sign-in. SNS sends no session and no CSRF
  token; the endpoint checks the signature of every message itself.
- Running the AWS setup with the application's own keys. The SDK takes `AWS_ACCESS_KEY_ID` and
  `AWS_SECRET_ACCESS_KEY` from the environment ahead of any profile, and Laravel loads the `.env`
  into that environment, so keys the `.env` names win over `AWS_PROFILE`. The setup runs with the
  keys of an administrator set in front of the command, which the `.env` does not override.
