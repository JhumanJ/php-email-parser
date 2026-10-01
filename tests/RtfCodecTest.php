<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Tests;

use JhumanJ\EmailParser\Internal\{RtfDecoder,ParseContext};
use JhumanJ\EmailParser\ParseOptions;
use JhumanJ\EmailParser\Exception\{InvalidEmailException,LimitExceededException};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RtfCodecTest extends TestCase
{
    public function testDeclaredRtfLengthIsLimitedBeforeDecompression(): void
    {
        $this->expectException(LimitExceededException::class);
        RtfDecoder::decompress(pack('V4', 12, 1000000000, 0x75465a4c, 0), 1000);
    }
    #[DataProvider('badRtf')]
    public function testInvalidRtfFailsExplicitly(string $raw): void
    {
        $this->expectException(InvalidEmailException::class);
        RtfDecoder::bodies($raw, new ParseContext(new ParseOptions()));
    }
    public static function badRtf(): array
    {
        return [['not RTF'],['{\\rtf1 Missing close'],['{\\rtf1 Extra close}}'],["{\\rtf1 \\'zz}"],['{\\rtf1 \\bin100 short}']];
    }
    public function testExcessiveRtfGroupsAreLimited(): void
    {
        $this->expectException(LimitExceededException::class);
        RtfDecoder::bodies('{\\rtf1 '.str_repeat('{', 300).str_repeat('}', 301), new ParseContext(new ParseOptions()));
    }
}
