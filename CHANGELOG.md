# Changelog

All notable changes to `pushery/ses-inbound-mail-for-laravel` are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

A release carries only the sections that have an entry, in this order: `### ⚠️ Breaking`, `### ✨ Added`, `### 🔧 Changed`, `### ⚡ Performance`, `### 🐛 Fixed`, `### 🔒 Security`, `### 🧹 Deprecated`, `### 🗑️ Removed` and `### 📚 Documentation`.

## [0.2.2] - 2026-11-11

### 🐛 Fixed

- **A daily prune that was killed no longer keeps the next day's prune from running.** 0.2.1 locked the prune for 1440 minutes, exactly its interval, so the lock of a prune that died without releasing it lasted until the next prune was due, and that prune was skipped without a word. An application whose schedule guard asks every task for a lock under its interval failed on it as well. The lock now lasts 120 minutes, and a running prune still holds back a second one.

## [0.2.1] - 2026-11-10

### 🐛 Fixed

- **The daily prune of the retention never runs twice at once.** It was planned on one server but without an overlap guard, so a prune that outlasted the day ran beside the next one, both deleting the same rows and originals, and an application whose schedule guard checks every task, the package's included, failed on it. A running prune now holds back the next one, as the reconciliation does.

## [0.2.0] - 2026-11-09

### ✨ Added

- **`ReceivedMail::$foundInTransit` says whether the reconciliation found a mail rather than a notification reporting it.** Such a mail carries the verdicts SES wrote into it and none on SPF, DKIM or DMARC, so an application that treats it differently no longer has to read that off empty verdicts. **Upgrade:** run `php artisan migrate`. The migration `0001_01_01_000001_add_found_in_transit_to_ses_inbound_mails_table` adds the column `found_in_transit` to `ses_inbound_mails`, and a mail recorded before it reads as reported.
- **A retention for the rows and the originals of the mails handed to the application.** `SES_INBOUND_MAIL_RETENTION_DAYS` sets how many days a mail keeps its row, which names its sender and recipients, and its original on the archive disk after its handover. With it set, the package schedules a daily `model:prune` for its model, which deletes both. Without it nothing is deleted, as before.

### 🐛 Fixed

- **A mail the reconciliation finds in transit is handed over for the recipient SES delivered it to, with SES's verdicts on spam and viruses.** The reconciliation read the recipients from `Delivered-To`, `X-Original-To`, `To` and `Cc`, so a mail forwarded to the application's address named only the addresses it came through, and the application found no recipient it knew; and it handed every such mail over without a verdict, as if SES had never checked it. It now takes the recipient from the `for` clause of the `Received` header SES puts on top, and the headers as before only where that clause is missing, and reads `X-SES-Spam-Verdict` and `X-SES-Virus-Verdict`.
- **A mail whose handover to the application failed in every try is handed over again.** Its original had already left the transit, so neither the reconciliation nor the health check saw it, and the mail never reached the application. The reconciliation now queues its takeover once more fifteen minutes after the original was taken over, and the health check counts it while it waits.
- **The health check counts a mail as stuck after thirty minutes in the transit, not fifteen.** Fifteen minutes is when the reconciliation takes a mail, and its next run comes up to five minutes later, so a mail on its ordinary way made the check fail. `Reconciliation::STUCK_AFTER_MINUTES` names the thirty minutes.

## [0.1.0] - 2026-11-08

### ✨ Added

- **An endpoint for the notifications of Amazon SNS.** It takes a message only from a topic of `ses-inbound-mail.topics` and only with a valid signature of SNS, confirms a subscription only at an address of SNS, and refuses everything else with 403, or 400 where it cannot read the message, before it uses anything of it. Every refusal is logged and dispatched as `NotificationRefused`. Its path, host and middleware come from the configuration.
- **The takeover of a mail from transit.** Every mail SES reports gets one row, once per id SES gave it. A queued job copies the original byte for byte onto a disk of the application, reads the copy back and holds its SHA-256 and size against the original, and only then deletes it in transit. The application receives the mail through `ReceivesInboundMail`, again on a later try where it threw. One takeover of a mail is queued at a time, with its lock in the application's default cache store, so the reconciliation queues no second one while the first waits for its next try.
- **The reconciliation, the net under the notifications.** Every five minutes, on one server, `ses-inbound-mail:reconcile` goes through every mail that has waited in transit for more than fifteen minutes, a limit that is the same for every application. A mail no notification reported is recorded with the envelope its headers name, leaving out an address longer than 320 characters, and taken over. A takeover that stopped is queued once more, the copy of a mail that was already taken over and the file SES writes to test its access are removed, and a mail that cannot be handled holds up none of the others. `ses-inbound-mail:take-over <key>` takes one mail by its key, whatever its age. For an application that runs `spatie/laravel-health`, `NoMailIsStuckInTransitCheck` fails while a mail waits past that time.
- **The setup of the receiving path in AWS.** `php artisan ses-inbound-mail:setup-aws` sets up what `ses-inbound-mail.aws` names: the bucket of the transit with Block Public Access, Bucket owner enforced and SSE-S3, its policy that lets SES store only the mail of the application's own receipt rule, its lifecycle, the topic and its policy, the subscription of the endpoint, the receipt rule in the rule set `inbound` the applications of an account share, and an IAM policy that lets the application's user only read, list and delete in the transit. The lifecycle is the same for every application: a mail is deleted after 14 days as a net under the takeover, a version that is no longer the current one after a day, and an upload that never finished after a day. Every item is read first and written only where it differs, so a second run changes nothing, and `--check` writes nothing and fails where an item is missing or differs.

[Unreleased]: https://github.com/pushery/ses-inbound-mail-for-laravel/compare/v0.2.2...HEAD
[0.2.2]: https://github.com/pushery/ses-inbound-mail-for-laravel/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/pushery/ses-inbound-mail-for-laravel/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/pushery/ses-inbound-mail-for-laravel/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/pushery/ses-inbound-mail-for-laravel/releases/tag/v0.1.0
