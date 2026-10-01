<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Internal;

use JhumanJ\EmailParser\Attachment;

/** @internal */
final class MimeContent
{
    public ?string $text = null;
    public ?string $html = null;
    /** @var list<Attachment> */
    public array $attachments = [];
}
