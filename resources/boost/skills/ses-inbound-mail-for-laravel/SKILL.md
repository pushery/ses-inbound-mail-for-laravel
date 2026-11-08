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
```

The service provider is registered automatically through package discovery.

### 2. Configure

Publish everything at once, or one group at a time:

```bash
php artisan vendor:publish --tag="ses-inbound-mail"
php artisan vendor:publish --tag="ses-inbound-mail-config"
php artisan vendor:publish --tag="ses-inbound-mail-migrations"
php artisan vendor:publish --tag="ses-inbound-mail-lang"
```

Every option in `config/ses-inbound-mail.php` is documented inline.

The migrations run from the package with `php artisan migrate`, and the package keeps
registering them after they are published. Before publishing them, on their own or through
the umbrella tag, call `\Pushery\SesInboundMail\SesInboundMailServiceProvider::ignoreMigrations()` in the
`register` method of one of the application's service providers. Under Laravel's default
configuration the published copy gets a new name, and without that call it fails on a table
that already exists.

### 3. Apply the package

Bind `Pushery\SesInboundMail\Contracts\ReceivesInboundMail` in a service provider of the
application. Its `receive(ReceivedMail $mail)` gets the id SES gave the mail, the envelope, the
verdicts of SES and the disk, path, SHA-256 and size of the original, which the package copied
onto the application's disk before the call. It may be called again for the same mail where it
threw, so it treats a mail it has seen before as done.

Configure the transit disk (`ses-inbound-mail.transit_disk`, an `s3` disk on the bucket SES
stores into) and the archive disk (`ses-inbound-mail.archive.disk`), name the allowed topics in
`SES_INBOUND_MAIL_TOPICS`, and set up the receiving path with `php artisan ses-inbound-mail:setup-aws`,
checked with `--check`. The package schedules `php artisan ses-inbound-mail:reconcile` every
five minutes, which takes every mail that waits in the transit longer than fifteen minutes. To
take one mail by its key in the transit, whatever its age, run
`php artisan ses-inbound-mail:take-over <key>`.

## Examples

```php
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
- Running the AWS setup with the application's own keys. They may only read, list and delete
  in the transit; the setup runs with the credentials of the machine.
