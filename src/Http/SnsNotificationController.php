<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Http;

use Aws\Sns\Message;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Pushery\SesInboundMail\Inbox;
use Pushery\SesInboundMail\ReportedMail;
use Pushery\SesInboundMail\Sns\SesNotification;
use Pushery\SesInboundMail\Sns\SnsGate;

/**
 * The endpoint Amazon SNS delivers the notifications of Amazon SES to, one for every incoming mail.
 *
 * Its door is {@see SnsGate}: the topics of `ses-inbound-mail.topics` and a valid signature of SNS.
 * The notification of a received mail is recorded by the {@see Inbox}, which queues the takeover of
 * its original; a mail reported twice changes nothing, and a notification that reports no stored mail
 * is answered and left.
 */
final readonly class SnsNotificationController
{
    public function __construct(
        private SnsGate $gate,
        private Inbox $inbox,
        private Repository $config,
    ) {}

    public function __invoke(Request $request): Response
    {
        return $this->gate->receive($request, $this->topics(), function (Message $message): Response {
            $mail = SesNotification::mail(SnsGate::text($message['Message']) ?? '');

            if ($mail instanceof ReportedMail) {
                $this->inbox->announce($mail);
            } else {
                Log::info('A notification of Amazon SES reported no stored mail and was left alone.', ['sns_message_id' => SnsGate::text($message['MessageId'])]);
            }

            return new Response('', 204);
        });
    }

    /** @return list<string> */
    private function topics(): array
    {
        $configured = $this->config->get('ses-inbound-mail.topics');
        $topics = [];

        foreach (is_array($configured) ? $configured : [] as $topic) {
            if (is_string($topic) && $topic !== '') {
                $topics[] = $topic;
            }
        }

        return $topics;
    }
}
