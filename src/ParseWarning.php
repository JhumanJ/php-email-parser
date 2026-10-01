<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser;

final readonly class ParseWarning
{
    public function __construct(public string $code, public string $message)
    {
    }
}
