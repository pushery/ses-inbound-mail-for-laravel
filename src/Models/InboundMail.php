<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;
use Pushery\SesInboundMail\Models\Concerns\Replaceable;
use Pushery\SesInboundMail\ReceivedMail;
use Pushery\SesInboundMail\ReportedMail;

/**
 * A mail SES reported, one row per id SES gave it, from the notification until the application has
 * received it: where it waits in transit, where its original lies once taken over, its SHA-256 and
 * size, and when it was taken over and handed to the application.
 *
 * @property int $id
 * @property string $message_id
 * @property string|null $sender
 * @property list<string> $recipients
 * @property array<string, string> $verdicts
 * @property string $transit_key
 * @property DateTimeImmutable|null $received_at
 * @property string|null $archive_disk
 * @property string|null $archive_path
 * @property string|null $sha256
 * @property int|null $size
 * @property DateTimeImmutable|null $taken_over_at
 * @property DateTimeImmutable|null $handed_over_at
 */
class InboundMail extends Model
{
    use Replaceable;

    /** @var string */
    protected $table = 'ses_inbound_mails';

    /** @var list<string> */
    protected $fillable = [
        'message_id',
        'sender',
        'recipients',
        'verdicts',
        'transit_key',
        'received_at',
        'archive_disk',
        'archive_path',
        'sha256',
        'size',
        'taken_over_at',
        'handed_over_at',
    ];

    /**
     * The attributes of a row for a mail as SES reported it.
     *
     * @return array<string, mixed>
     */
    public static function attributesOf(ReportedMail $mail): array
    {
        $fits = static fn (string $address): bool => strlen($address) <= ReportedMail::LONGEST_ADDRESS;

        return [
            'message_id' => $mail->messageId,
            'sender' => $mail->sender !== null && $fits($mail->sender) ? $mail->sender : null,
            'recipients' => array_values(array_filter($mail->recipients, $fits)),
            'verdicts' => $mail->verdicts,
            'transit_key' => $mail->transitKey,
            'received_at' => $mail->receivedAt,
        ];
    }

    /** Whether the original lies on the application's disk and is gone from transit. */
    public function isTakenOver(): bool
    {
        return $this->taken_over_at !== null;
    }

    /**
     * The mail as the application receives it.
     *
     * @throws LogicException when the mail has not been taken over yet
     */
    public function received(): ReceivedMail
    {
        $disk = $this->archive_disk;
        $path = $this->archive_path;
        $sha256 = $this->sha256;
        $size = $this->size;

        if (! $this->isTakenOver() || $disk === null || $path === null || $sha256 === null || $size === null) {
            throw new LogicException(sprintf('The mail %s has not been taken over from transit yet.', $this->message_id));
        }

        return new ReceivedMail($this->message_id, $this->sender, $this->recipients, $this->verdicts, $this->received_at, $disk, $path, $sha256, $size);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'verdicts' => 'array',
            'received_at' => 'immutable_datetime',
            'size' => 'integer',
            'taken_over_at' => 'immutable_datetime',
            'handed_over_at' => 'immutable_datetime',
        ];
    }
}
