<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Internal;

use JhumanJ\EmailParser\Exception\InvalidEmailException;

/** @internal */
final class MsgProperties
{
    /** @var array<string,int> */
    private array $children;
    /** @var array<int,string> */
    private array $fixed = [];
    public readonly string $encoding;
    public function __construct(private readonly CompoundFile $cfb, int $storage, private readonly ParseContext $context, bool $embedded = false, bool $other = false, ?string $encoding = null)
    {
        $this->children = $cfb->children($storage);
        if (isset($this->children['__properties_version1.0'])) {
            $bytes = $cfb->stream($this->children['__properties_version1.0']);
            $offset = $other ? 8 : ($embedded ? 24 : 32);
            if (strlen($bytes) < $offset || (strlen($bytes) - $offset) % 16 !== 0) {
                throw new InvalidEmailException('Invalid MSG property table.');
            }
            for ($i = $offset;$i < strlen($bytes);$i += 16) {
                $tag = CompoundFile::u32($bytes, $i);
                $this->fixed[$tag] = substr($bytes, $i + 8, 8);
            }
        } elseif (!$other) {
            throw new InvalidEmailException('Missing MSG property table.');
        }
        $this->encoding = $encoding ?? RtfDecoder::codePage($this->integer(0x3FFD) ?? $this->integer(0x3FDE) ?? 1252, $context);
    }
    public function string(int $property): ?string
    {
        foreach ([0x001F,0x001E] as $type) {
            $name = sprintf('__substg1.0_%04X%04X', $property, $type);
            if (isset($this->children[$name])) {
                $raw = $this->cfb->stream($this->children[$name], $this->context->options->maxBodyBytes);
                if ($type === 0x001F) {
                    if (strlen($raw) % 2 !== 0) {
                        throw new InvalidEmailException('Invalid UTF-16 MSG string.');
                    }
                    $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
                } else {
                    $raw = mb_convert_encoding($raw, 'UTF-8', $this->encoding);
                }
                $raw = rtrim($raw, "\0");
                $this->context->body($raw);
                return $raw;
            }
        }
        return null;
    }
    public function binary(int $property, ?int $limit = null): ?string
    {
        $name = sprintf('__substg1.0_%04X0102', $property);
        return isset($this->children[$name]) ? $this->cfb->stream($this->children[$name], $limit) : null;
    }
    public function integer(int $property): ?int
    {
        $raw = $this->fixed[($property << 16) | 3] ?? null;
        return $raw === null ? null : CompoundFile::u32($raw, 0);
    }
    public function boolean(int $property): bool
    {
        return CompoundFile::u16($this->fixed[($property << 16) | 11] ?? "\0\0", 0) !== 0;
    }
    public function date(int $property): ?\DateTimeImmutable
    {
        $raw = $this->fixed[($property << 16) | 0x0040] ?? null;
        if ($raw === null) {
            return null;
        }
        $low = CompoundFile::u32($raw, 0);
        $high = CompoundFile::u32($raw, 4);
        $seconds = (int)floor(($high * 4294967296 + $low) / 10000000) - 11644473600;
        try {
            return new \DateTimeImmutable('@'.$seconds);
        } catch (\Exception) {
            return null;
        }
    }
}
