<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser;

use JhumanJ\EmailParser\Exception\InvalidEmailException;
use JhumanJ\EmailParser\Internal\{CompoundFile,EmlParser,MsgParser,ParseContext};

final class EmailParser
{
    public function __construct(private readonly ParseOptions $options = new ParseOptions())
    {
    }
    public function parse(string $bytes, ?Format $format = null): ParseResult
    {
        ParseContext::limit(strlen($bytes), $this->options->maxInputBytes, 'Input bytes');
        $format ??= str_starts_with($bytes, CompoundFile::SIGNATURE) ? Format::MSG : Format::EML;
        $context = new ParseContext($this->options);
        $message = $format === Format::MSG ? (new MsgParser())->parse($bytes, $context) : (new EmlParser())->parse($bytes, $context);
        return new ParseResult($message, $context->warnings);
    }
    public function parseFile(string $path, ?Format $format = null): ParseResult
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidEmailException('Email file is not readable: '.$path);
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new InvalidEmailException('Unable to open email file.');
        }
        try {
            return $this->parseStream($stream, $format);
        } finally {
            fclose($stream);
        }
    }
    /**
     * Reads from the current position; never closes a caller-owned stream.
     * @param resource $stream
     */
    public function parseStream($stream, ?Format $format = null): ParseResult
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('Expected an open stream resource.');
        }
        $bytes = '';
        while (!feof($stream)) {
            $chunk = fread($stream, min(8192, max(1, $this->options->maxInputBytes - strlen($bytes) + 1)));
            if ($chunk === false || ($chunk === '' && !feof($stream))) {
                throw new InvalidEmailException('Unable to read email stream.');
            }
            $bytes .= $chunk;
            ParseContext::limit(strlen($bytes), $this->options->maxInputBytes, 'Input bytes');
        }
        return $this->parse($bytes, $format);
    }
}
