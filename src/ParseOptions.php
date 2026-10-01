<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser;

final readonly class ParseOptions
{
    public function __construct(
        public bool $strict = false,
        public int $maxInputBytes = 52428800,
        public int $maxBodyBytes = 10485760,
        public int $maxAttachmentBytes = 20971520,
        public int $maxTotalAttachmentBytes = 52428800,
        public int $maxAttachments = 100,
        public int $maxNestingDepth = 10,
        public int $maxDirectoryEntries = 10000,
        public int $maxMimeParts = 1000,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if (is_int($value) && $value < 0) {
                throw new \InvalidArgumentException($name.' must not be negative.');
            }
        }
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('MSG parsing requires a 64-bit PHP runtime.');
        }
    }
}
