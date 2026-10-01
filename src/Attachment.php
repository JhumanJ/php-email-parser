<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser;

final readonly class Attachment
{
    public string $filename;
    public function __construct(
        public string $originalFilename,
        public string $contentType,
        private string $bytes,
        public ?string $contentId = null,
        public bool $inline = false,
        public ?EmailMessage $embeddedMessage = null,
        ?string $filename = null,
    ) {
        $safe = str_replace('\\', '/', $filename ?? $originalFilename);
        $safe = preg_replace('/[\x00-\x1f\x7f]/', '', basename($safe)) ?? '';
        $this->filename = ($safe === '' || $safe === '.' || $safe === '..') ? 'attachment.bin' : $safe;
    }
    public function content(): string
    {
        return $this->bytes;
    }
    public function size(): int
    {
        return strlen($this->bytes);
    }
    /** @return resource */
    public function openStream()
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false || fwrite($stream, $this->bytes) !== strlen($this->bytes)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new \RuntimeException('Unable to create attachment stream.');
        }
        rewind($stream);
        return $stream;
    }
}
