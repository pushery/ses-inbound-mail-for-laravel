<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail;

/**
 * The envelope of a stored mail as its headers name it, for a mail no notification reported: the
 * sender from `Return-Path`, or from `From` where that is missing, and the recipient SES delivered it
 * to, with the verdicts SES wrote into it.
 *
 * SES names the recipient in the `for` clause of the `Received` header it puts on top of the mail, and
 * that is the address the mail was delivered to, also where it was forwarded from another one. Where
 * that clause is missing, the recipients come from `Delivered-To`, `X-Original-To`, `To` and `Cc`, each
 * address once. A blind copy names no recipient in those headers, so a mail SES delivered only to a
 * blind copy, without the clause, is read with the recipients its headers do name.
 *
 * The verdicts on spam and viruses are read from `X-SES-Spam-Verdict` and `X-SES-Virus-Verdict`, under
 * the names a notification gives them. Only the headers are read, as far as {@see self::HEADER_LIMIT}
 * bytes reach, and a header folded over several lines is read as one.
 */
final readonly class StoredEnvelope
{
    /** How much of a stored mail is read for its headers. */
    public const int HEADER_LIMIT = 65536;

    /** The headers that name recipients, in the order they are read. */
    private const array RECIPIENT_HEADERS = ['delivered-to', 'x-original-to', 'to', 'cc'];

    /** The headers SES writes its verdicts into, by the name a notification gives each verdict. */
    private const array VERDICT_HEADERS = ['spam' => 'x-ses-spam-verdict', 'virus' => 'x-ses-virus-verdict'];

    /**
     * @param  list<string>  $recipients
     * @param  array<string, string>  $verdicts  `spam` and `virus`, where SES wrote them, as `PASS`, `FAIL`, `GRAY` or `PROCESSING_FAILED`
     */
    public function __construct(
        public ?string $sender,
        public array $recipients,
        public array $verdicts = [],
    ) {}

    /** The envelope the headers at the start of a stored mail name. */
    public static function read(string $head): self
    {
        $headers = self::headersOf($head);
        $sender = self::addressesIn($headers['return-path'][0] ?? '')[0] ?? self::addressesIn($headers['from'][0] ?? '')[0] ?? null;
        $recipients = self::deliveredTo($headers['received'][0] ?? '') ?? self::namedRecipients($headers);
        $verdicts = [];

        foreach (self::VERDICT_HEADERS as $verdict => $name) {
            $status = strtoupper(trim($headers[$name][0] ?? ''));

            if (preg_match('/\A[A-Z_]{1,32}\z/', $status) === 1) {
                $verdicts[$verdict] = $status;
            }
        }

        return new self($sender, $recipients, $verdicts);
    }

    /**
     * The recipient in the `for` clause of the topmost `Received` header, the one SES added, or null
     * where that header names none.
     *
     * @return list<string>|null
     */
    private static function deliveredTo(string $received): ?array
    {
        if (preg_match('/\sfor\s+<?([^\s<>;@]+@[^\s<>;@]+?)>?\s*;/i', $received, $match) !== 1) {
            return null;
        }

        return [strtolower($match[1])];
    }

    /**
     * The recipients the headers of a mail name, each address once, in the order of
     * {@see self::RECIPIENT_HEADERS}.
     *
     * @param  array<string, list<string>>  $headers
     * @return list<string>
     */
    private static function namedRecipients(array $headers): array
    {
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

        return $recipients;
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
