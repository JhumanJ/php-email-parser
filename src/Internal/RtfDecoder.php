<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Internal;

use JhumanJ\EmailParser\Exception\InvalidEmailException;

/** MS-OXRTFCP decompression and text/encapsulated HTML extraction. @internal */
final class RtfDecoder
{
    // The 207-byte initial dictionary mandated by MS-OXRTFCP 2.1.2.1.
    private const DICTIONARY = '{\rtf1\ansi\mac\deff0\deftab720{\fonttbl;}{\f0\fnil \froman \fswiss \fmodern \fscript \fdecor MS Sans SerifSymbolArialTimes New RomanCourier{\colortbl\red0\green0\blue0'."\r\n".'\par \pard\plain\f0\fs20\b\i\u\tab\tx';
    public static function decompress(string $bytes, int $limit): string
    {
        if (strlen($bytes) < 16) {
            throw new InvalidEmailException('Truncated compressed RTF header.');
        }
        $size = CompoundFile::u32($bytes, 0);
        $rawSize = CompoundFile::u32($bytes, 4);
        $magic = CompoundFile::u32($bytes, 8);
        $crc = CompoundFile::u32($bytes, 12);
        ParseContext::limit($rawSize, $limit, 'Decompressed RTF bytes');
        if ($size !== strlen($bytes) - 4) {
            throw new InvalidEmailException('Invalid compressed RTF size.');
        }
        $payload = substr($bytes, 16);
        if ($magic === 0x414C454D) {
            if ($crc !== 0 || strlen($payload) < $rawSize) {
                throw new InvalidEmailException('Invalid uncompressed RTF.');
            }
            return substr($payload, 0, $rawSize);
        }
        if ($magic !== 0x75465A4C || self::crc($payload) !== $crc) {
            throw new InvalidEmailException('Invalid RTF compression or checksum.');
        }
        $dictionary = str_pad(self::DICTIONARY, 4096, "\0");
        $write = 207;
        $output = '';
        $position = 0;
        $end = false;
        while ($position < strlen($payload) && !$end) {
            $flags = ord($payload[$position++]);
            for ($bit = 0; $bit < 8 && $position < strlen($payload); ++$bit) {
                if (($flags & (1 << $bit)) === 0) {
                    $chunk = $payload[$position++];
                } else {
                    if ($position + 2 > strlen($payload)) {
                        throw new InvalidEmailException('Truncated RTF reference.');
                    }
                    $reference = (ord($payload[$position]) << 8) | ord($payload[$position + 1]);
                    $position += 2;
                    $offset = $reference >> 4;
                    $length = ($reference & 15) + 2;
                    if ($offset === $write) {
                        $end = true;
                        break;
                    }
                    $chunk = '';
                    // A reference may overlap the bytes being written.
                    for ($i = 0; $i < $length; ++$i) {
                        $byte = $dictionary[($offset + $i) & 4095];
                        $chunk .= $byte;
                        $dictionary[$write] = $byte;
                        $write = ($write + 1) & 4095;
                    }
                    ParseContext::limit(strlen($output) + strlen($chunk), $limit, 'Decompressed RTF bytes');
                    $output .= $chunk;
                    continue;
                }
                $dictionary[$write] = $chunk;
                $write = ($write + 1) & 4095;
                ParseContext::limit(strlen($output) + 1, $limit, 'Decompressed RTF bytes');
                $output .= $chunk;
            }
        }
        if (strlen($output) !== $rawSize) {
            throw new InvalidEmailException('RTF decompressed length mismatch.');
        }
        return $output;
    }
    private static function crc(string $bytes): int
    {
        static $table = [];
        if ($table === []) {
            for ($i = 0;$i < 256;++$i) {
                $entry = $i;
                for ($bit = 0;$bit < 8;++$bit) {
                    $entry = ($entry >> 1) ^ (($entry & 1) ? 0xEDB88320 : 0);
                }
                $table[$i] = $entry;
            }
        }
        $crc = 0;
        for ($i = 0,$len = strlen($bytes);$i < $len;++$i) {
            $crc = ($crc >> 8) ^ $table[($crc ^ ord($bytes[$i])) & 255];
        }
        return $crc;
    }
    /** @return array{0:?string,1:?string} */
    public static function bodies(string $rtf, ParseContext $context): array
    {
        if (!str_starts_with($rtf, '{\rtf')) {
            throw new InvalidEmailException('Invalid RTF document.');
        }
        $htmlMode = (bool)preg_match('/\\\\fromhtml1\b/', $rtf);
        $text = self::extract($rtf, false, $context);
        $html = $htmlMode ? self::extract($rtf, true, $context) : null;
        if ($html !== null && $html !== '') {
            $text = self::htmlText($html);
        }
        return [$text === '' ? null : $text,$html === '' ? null : $html];
    }
    public static function htmlText(string $html): string
    {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html) ?? $html;
        $html = preg_replace('~<(br\s*/?|/p|/div|/li|/tr)>~i', "\n", $html) ?? $html;
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    private static function extract(string $rtf, bool $html, ParseContext $context): string
    {
        $state = ['skip' => false,'star' => false,'html' => false,'hidden' => false,'uc' => 1,'cp' => 'Windows-1252'];
        $stack = [];
        $output = '';
        $fallback = 0;
        $high = null;
        for ($i = 0,$length = strlen($rtf);$i < $length;) {
            $char = $rtf[$i++];
            if ($char === '{') {
                ParseContext::limit(count($stack), 256, 'RTF group depth');
                $stack[] = $state;
                $state['star'] = false;
                continue;
            }
            if ($char === '}') {
                if ($stack === []) {
                    throw new InvalidEmailException('Unbalanced RTF groups.');
                }
                $state = array_pop($stack);
                continue;
            }
            if ($char === "\r" || $char === "\n") {
                continue;
            }
            if ($char !== '\\') {
                $literal = $char;
                while ($i < $length && !str_contains("{}\\\r\n", $rtf[$i])) {
                    $literal .= $rtf[$i++];
                }
                if ($fallback > 0) {
                    $skip = min($fallback, strlen($literal));
                    $literal = substr($literal, $skip);
                    $fallback -= $skip;
                }
                self::append($output, $state, $fallback, $html, $context, mb_convert_encoding($literal, 'UTF-8', $state['cp']));
                continue;
            }
            if ($i >= $length) {
                throw new InvalidEmailException('Truncated RTF escape.');
            }
            $symbol = $rtf[$i++];
            if (in_array($symbol, ['\\','{','}'], true)) {
                self::append($output, $state, $fallback, $html, $context, $symbol);
                continue;
            }
            if ($symbol === "'") {
                $hex = substr($rtf, $i, 2);
                if (strlen($hex) !== 2 || !ctype_xdigit($hex)) {
                    throw new InvalidEmailException('Invalid RTF hex escape.');
                }
                $i += 2;
                $literal = chr(hexdec($hex));
                while (substr($rtf, $i, 2) === "\\'" && ctype_xdigit(substr($rtf, $i + 2, 2)) && strlen(substr($rtf, $i + 2, 2)) === 2) {
                    $literal .= chr(hexdec(substr($rtf, $i + 2, 2)));
                    $i += 4;
                }
                if ($fallback > 0) {
                    $skip = min($fallback, strlen($literal));
                    $literal = substr($literal, $skip);
                    $fallback -= $skip;
                }
                self::append($output, $state, $fallback, $html, $context, mb_convert_encoding($literal, 'UTF-8', $state['cp']));
                continue;
            }
            if ($symbol === '*') {
                $state['star'] = true;
                continue;
            }
            if (!ctype_alpha($symbol)) {
                self::append($output, $state, $fallback, $html, $context, match($symbol) {
                    '~' => "\xc2\xa0",'_' => '-', default => ''
                });
                continue;
            }
            $word = $symbol;
            while ($i < $length && ctype_alpha($rtf[$i])) {
                $word .= $rtf[$i++];
            }
            $number = '';
            if ($i < $length && $rtf[$i] === '-') {
                $number .= $rtf[$i++];
            }
            while ($i < $length && ctype_digit($rtf[$i])) {
                $number .= $rtf[$i++];
            }
            $parameter = ($number === '' || $number === '-') ? null : (int)$number;
            if ($i < $length && $rtf[$i] === ' ') {
                ++$i;
            }
            if ($state['star']) {
                if ($word !== 'htmltag') {
                    $state['skip'] = true;
                } $state['star'] = false;
            }
            if (in_array($word, ['fonttbl','colortbl','stylesheet','info','pict','object','fldinst','xmlnstbl','datastore','themedata','listtable','listoverridetable'], true)) {
                $state['skip'] = true;
            } elseif ($word === 'htmltag') {
                $state['html'] = true;
            } elseif ($word === 'htmlrtf') {
                $state['hidden'] = $parameter !== 0;
            } elseif ($word === 'uc') {
                $state['uc'] = max(0, min(16, $parameter ?? 1));
            } elseif ($word === 'ansicpg') {
                $state['cp'] = self::codePage($parameter ?? 1252, $context);
            } elseif ($word === 'u' && $parameter !== null) {
                $unit = $parameter & 0xFFFF;
                if ($unit >= 0xD800 && $unit <= 0xDBFF) {
                    $high = $unit;
                } else {
                    $encoded = pack('v', $unit);
                    if ($high !== null && $unit >= 0xDC00 && $unit <= 0xDFFF) {
                        $encoded = pack('vv', $high, $unit);
                    }
                    $high = null;
                    $fallback = 0;
                    self::append($output, $state, $fallback, $html, $context, mb_convert_encoding($encoded, 'UTF-8', 'UTF-16LE'));
                }
                $fallback = $state['uc'];
            } elseif ($word === 'bin') {
                if ($parameter === null || $parameter < 0 || $i + $parameter > $length) {
                    throw new InvalidEmailException('Invalid RTF binary length.');
                }
                $i += $parameter;
            } elseif (in_array($word, ['par','line','tab','emdash','endash','bullet','lquote','rquote','ldblquote','rdblquote'], true)) {
                self::append($output, $state, $fallback, $html, $context, match($word) {
                    'par','line' => "\n",'tab' => "\t",'emdash' => '—','endash' => '–','bullet' => '•','lquote' => '‘','rquote' => '’','ldblquote' => '“',default => '”'
                });
            }
        }
        if ($stack !== []) {
            throw new InvalidEmailException('Unbalanced RTF groups.');
        }
        return trim($output);
    }
    /** @param array{skip:bool,star:bool,html:bool,hidden:bool,uc:int,cp:string} $state */
    private static function append(string &$output, array $state, int &$fallback, bool $html, ParseContext $context, string $value): void
    {
        if ($fallback > 0) {
            --$fallback;
            return;
        }
        if (!$state['skip'] && (!$html || ($state['html'] && !$state['hidden']))) {
            $output .= $value;
            $context->body($output);
        }
    }
    public static function codePage(int $codePage, ParseContext $context): string
    {
        $encoding = match($codePage) {
            65001 => 'UTF-8',1200 => 'UTF-16LE',20127 => 'ASCII',28591 => 'ISO-8859-1', 28592 => 'ISO-8859-2', 28595 => 'ISO-8859-5', 28599 => 'ISO-8859-9', 28605 => 'ISO-8859-15', 20866 => 'KOI8-R', 21866 => 'KOI8-U', 65000 => 'UTF-7',932 => 'SJIS',936 => 'GBK',949 => 'CP949',950 => 'BIG-5', default => 'Windows-'.$codePage
        };
        try {
            mb_convert_encoding('', 'UTF-8', $encoding);
            return $encoding;
        } catch (\ValueError) {
            $context->warning('unknown_code_page', 'Unknown code page '.$codePage.'; falling back to Windows-1252.');
            return 'Windows-1252';
        }
    }
}
