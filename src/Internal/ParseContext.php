<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Internal;

use JhumanJ\EmailParser\{ParseOptions, ParseWarning};
use JhumanJ\EmailParser\Exception\{InvalidEmailException, LimitExceededException};

/** @internal */
final class ParseContext
{
    /** @var list<ParseWarning> */
    public array $warnings = [];
    private int $attachments = 0;
    private int $attachmentBytes = 0;
    private int $parts = 0;
    public function __construct(public readonly ParseOptions $options)
    {
    }
    public function warning(string $code, string $message): void
    {
        if ($this->options->strict) {
            throw new InvalidEmailException($code.': '.$message);
        }
        $this->warnings[] = new ParseWarning($code, $message);
    }
    public function nesting(int $depth): void
    {
        self::limit($depth, $this->options->maxNestingDepth, 'Nesting depth');
    }
    public function part(): void
    {
        self::limit(++$this->parts, $this->options->maxMimeParts, 'MIME part count');
    }
    public function attachment(string $bytes): void
    {
        self::limit(++$this->attachments, $this->options->maxAttachments, 'Attachment count');
        self::limit(strlen($bytes), $this->options->maxAttachmentBytes, 'Attachment bytes');
        $this->attachmentBytes += strlen($bytes);
        self::limit($this->attachmentBytes, $this->options->maxTotalAttachmentBytes, 'Total attachment bytes');
    }
    public function body(?string $body): void
    {
        self::limit(strlen($body ?? ''), $this->options->maxBodyBytes, 'Body bytes');
    }
    public static function limit(int $actual, int $limit, string $name): void
    {
        if ($actual > $limit) {
            throw new LimitExceededException($name.' exceeds configured limit of '.$limit.'.');
        }
    }
}
