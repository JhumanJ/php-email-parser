<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser;

use JhumanJ\EmailParser\Internal\EmlExporter;

final readonly class EmailMessage
{
    /**
     * @param list<Address> $from
     * @param list<Address> $to
     * @param list<Address> $cc
     * @param list<Address> $bcc
     * @param list<Address> $replyTo
     * @param list<Attachment> $attachments
     * @param array<string,list<string>> $headers Decoded header values, lowercase keys.
     */
    public function __construct(
        public Format $format,
        public ?string $subject = null,
        public array $from = [],
        public array $to = [],
        public array $cc = [],
        public array $bcc = [],
        public array $replyTo = [],
        public ?\DateTimeImmutable $date = null,
        public ?string $messageId = null,
        public ?string $textBody = null,
        public ?string $htmlBody = null,
        public array $attachments = [],
        public array $headers = [],
        public ?string $originalContent = null,
    ) {
    }
    public function toEml(): string
    {
        return EmlExporter::export($this);
    }
}
