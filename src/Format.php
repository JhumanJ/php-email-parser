<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser;

enum Format: string
{
    case EML = 'eml';
    case MSG = 'msg';
}
