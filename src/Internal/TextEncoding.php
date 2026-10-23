<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Internal;

use JhumanJ\EmailParser\Exception\InvalidEmailException;

/** @internal */
final class TextEncoding
{
    public static function decode(string $bytes, string $encoding): string
    {
        try {
            return mb_convert_encoding($bytes, 'UTF-8', $encoding);
        } catch (\ValueError) {
            // Some standard Windows code pages (including 1258) are supported
            // by iconv but not mbstring. Never ignore invalid source bytes.
            $decoded = @iconv($encoding, 'UTF-8', $bytes);
            if ($decoded === false) {
                throw new InvalidEmailException('Cannot decode text using '.$encoding.'.');
            }
            return $decoded;
        }
    }
}
