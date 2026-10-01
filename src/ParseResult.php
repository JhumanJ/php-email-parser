<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser;

final readonly class ParseResult
{
    /** @param list<ParseWarning> $warnings */
    public function __construct(public EmailMessage $message, public array $warnings = [])
    {
    }
}
