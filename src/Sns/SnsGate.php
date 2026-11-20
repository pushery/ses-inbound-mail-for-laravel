<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Sns;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Pushery\SesInboundMail\Events\NotificationRefused;
use RuntimeException;

/**
 * The door of the endpoint Amazon SNS delivers to.
 *
 * A message is taken only from a topic in the list the endpoint gives and only with a valid signature
 * of SNS. Any other answers 403, one that cannot be read 400, and each refusal is logged and
 * dispatched as {@see NotificationRefused} before anything of the message is used. A subscription is
 * confirmed only for a topic in the list and only at an address of SNS itself; a notification goes on
 * to the endpoint, and every other type of message is answered and left.
 */
final readonly class SnsGate
{
    /** The hosts of SNS, the only ones a subscription is confirmed at. */
    private const string SNS_HOST = '/\Asns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?\z/i';

    /** How long the confirmation of a subscription may take, in seconds. */
    private const int CONFIRMATION_TIMEOUT = 10;

    /** How much of a topic a refusal keeps, since a stranger chooses its length. */
    private const int LONGEST_TOPIC_KEPT = 256;

    /**
     * The fields the validator of the signatures reads as text: the fields SNS signs, the signature,
     * its version and the address of its certificate, each in every spelling the validator takes.
     */
    private const array TEXT_FIELDS = ['Message', 'MessageId', 'Subject', 'SubscribeURL', 'SubscribeUrl', 'Timestamp', 'Token', 'TopicArn', 'Type', 'Signature', 'SignatureVersion', 'SigningCertURL', 'SigningCertUrl'];

    /** The validator of the signatures, with the certificates fetched and kept by the package. */
    private MessageValidator $validator;

    public function __construct(
        SigningCertificates $certificates,
        private Factory $http,
        private Dispatcher $events,
    ) {
        $this->validator = new MessageValidator($certificates->fetch(...));
    }

    /**
     * Reads the SNS message of a request and hands a notification on.
     *
     * @param  list<string>  $topics  the topics the endpoint takes messages from
     * @param  Closure(Message): Response  $notification
     */
    public function receive(Request $request, array $topics, Closure $notification): Response
    {
        try {
            $message = Message::fromJsonString($request->getContent());
        } catch (InvalidArgumentException|RuntimeException) {
            return $this->refuse($request, NotificationRefused::UNREADABLE, null, 400);
        }

        $topic = self::text($message['TopicArn']);

        if ($topic === null || ! in_array($topic, $topics, true)) {
            return $this->refuse($request, NotificationRefused::TOPIC, $topic);
        }

        // The validator refuses a signature it cannot verify, but a list or a map where it reads text
        // breaks it with an error. Such a message, like one without a signature to verify, is
        // refused before it is handed over.
        if (! $this->carriesTextWhereSnsWritesText($message)) {
            return $this->refuse($request, NotificationRefused::UNREADABLE, $topic, 400);
        }

        if (! $this->validator->isValid($message)) {
            return $this->refuse($request, NotificationRefused::SIGNATURE, $topic);
        }

        return match ($message['Type']) {
            'SubscriptionConfirmation' => $this->confirm($request, $message, $topic),
            'Notification' => $notification($message),
            default => new Response('', 204),
        };
    }

    /**
     * A field of a message of SNS as text, or null where it is none or empty.
     *
     * @param  mixed  $value  a field of the message as the sender wrote it, which may hold any JSON value
     */
    public static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Whether every field the validator reads as text is text where the message has it, and the
     * signature, its version and the address of its certificate are there and not empty.
     */
    private function carriesTextWhereSnsWritesText(Message $message): bool
    {
        foreach (self::TEXT_FIELDS as $field) {
            if ($message[$field] !== null && ! is_string($message[$field])) {
                return false;
            }
        }

        return self::text($message['Signature']) !== null
            && self::text($message['SignatureVersion']) !== null
            && (self::text($message['SigningCertURL']) ?? self::text($message['SigningCertUrl'])) !== null;
    }

    private function confirm(Request $request, Message $message, string $topic): Response
    {
        $address = self::text($message['SubscribeURL']);

        if ($address === null || ! $this->isAnAddressOfSns($address)) {
            return $this->refuse($request, NotificationRefused::SUBSCRIPTION_ADDRESS, $topic);
        }

        // No redirect is followed: the address is one of SNS, and Guzzle would follow an answer wherever it points, over
        // http as well. A 3xx answer is no confirmation either, and throw() covers 4xx and 5xx alone.
        $response = $this->http->timeout(self::CONFIRMATION_TIMEOUT)->withoutRedirecting()->get($address)->throw();

        if ($response->redirect()) {
            throw new RuntimeException(sprintf('The address of a subscription of Amazon SNS answered %d instead of confirming it.', $response->status()));
        }

        Log::info('Confirmed the subscription to a topic of Amazon SNS for incoming mail.', ['topic' => $topic]);

        return new Response('', 204);
    }

    private function refuse(Request $request, string $reason, ?string $topic, int $status = 403): Response
    {
        $topic = $topic === null ? null : mb_substr($topic, 0, self::LONGEST_TOPIC_KEPT);

        Log::warning('A message to the endpoint of Amazon SNS for incoming mail was refused.', ['reason' => $reason, 'topic' => $topic]);
        $this->events->dispatch(new NotificationRefused($reason, $topic, $request->ip()));

        return new Response('', $status);
    }

    private function isAnAddressOfSns(string $address): bool
    {
        $parts = parse_url($address);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && preg_match(self::SNS_HOST, $parts['host'] ?? '') === 1;
    }
}
