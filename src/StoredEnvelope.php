<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail;

/**
 * The envelope of a stored mail as its headers name it, for a mail no notification reported: the
 * sender from `Return-Path`, or from `From` where that is missing, and the recipients from
 * `Delivered-To`, `X-Original-To`, `To` and `Cc`, each address once.
 *
 * Only the headers are read, as far as {@see self::HEADER_LIMIT} bytes reach, and a header folded over
 * several lines is read as one. A blind copy names no recipient in the headers, so a mail SES
 * delivered only to a blind copy is read with the recipients its headers do name.
 */
final readonly class StoredEnvelope
{
    /** How much of a stored mail is read for its headers. */
    public const int HEADER_LIMIT = 65536;

    /** The headers that name recipients, in the order they are read. */
    private const array RECIPIENT_HEADERS = ['delivered-to', 'x-original-to', 'to', 'cc'];

    /**
     * @param  list<string>  $recipients
     */
    public function __construct(
        public ?string $sender,
        public array $recipients,
    ) {}

    /** The envelope the headers at the start of a stored mail name. */
    public static function read(string $head): self
    {
        $headers = self::headersOf($head);
        $sender = self::addressesIn($headers['return-path'][0] ?? '')[0] ?? self::addressesIn($headers['from'][0] ?? '')[0] ?? null;
        $recipients = [];

        foreach (self::RECIPIENT_HEADERS as $name) {
            foreach ($headers[$name] ?? [] as $value) {
                foreach (self::addressesIn($value) as $address) {
                    if (! in_array($address, $recipients, true)) {
                        $recipients[] = $address;
                    }
                }
            }
        }

        return new self($sender, $recipients);
    }

    /**
     * The headers of a mail by their name in lower case, each value unfolded.
     *
     * @return array<string, list<string>>
     */
    private static function headersOf(string $head): array
    {
        $block = preg_split('/\r?\n\r?\n/', $head, 2)[0] ?? '';
        $unfolded = (string) preg_replace('/\r?\n[ \t]+/', ' ', $block);
        $headers = [];

        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            $colon = strpos($line, ':');

            if ($colon === false || $colon === 0) {
                continue;
            }

            $headers[strtolower(trim(substr($line, 0, $colon)))][] = trim(substr($line, $colon + 1));
        }

        return $headers;
    }

    /**
     * The addresses a header value names, in lower case: each one between angle brackets, or the
     * bare ones where none is.
     *
     * @return list<string>
     */
    private static function addressesIn(string $value): array
    {
        $pattern = str_contains($value, '<') ? '/<([^<>\s@]+@[^<>\s@]+)>/' : '/([^\s,;<>"]+@[^\s,;<>"]+)/';
        preg_match_all($pattern, $value, $matches);

        return array_map(strtolower(...), $matches[1]);
    }
}
