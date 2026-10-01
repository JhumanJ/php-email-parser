<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Tests;

use JhumanJ\EmailParser\{EmailParser,ParseOptions};
use JhumanJ\EmailParser\Exception\InvalidEmailException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdvancedMsgTest extends TestCase
{
    #[DataProvider('rtfEncodings')]
    public function testRtfEncodingsAndDictionaryReferences(string $fixture, string $expected): void
    {
        self::assertSame($expected, (new EmailParser())->parseFile(__DIR__.'/Fixtures/'.$fixture)->message->textBody);
    }
    public static function rtfEncodings(): array
    {
        return [['rtf-utf8.msg','Bonjour été'],['rtf-sjis.msg','日本'],['rtf-unicode.msg','Smile 😀'],['rtf-reference.msg','Hi']];
    }
    public function testBothCfbSectorSizesPreserveLargeBinaryStreams(): void
    {
        $expected = implode('', array_map(chr(...), range(0, 255)));
        foreach (['regular-sectors.msg','regular-sectors-v4.msg'] as $file) {
            $email = (new EmailParser())->parseFile(__DIR__.'/Fixtures/'.$file)->message;
            self::assertSame(str_repeat($expected, 40), $email->attachments[0]->content());
        }
    }
    public function testDifatIndirectionIsSupported(): void
    {
        $email = (new EmailParser())->parseFile(__DIR__.'/Fixtures/difat.msg')->message;
        self::assertSame('Facture été', $email->subject);
        self::assertSame("%PDF-1.4\nfixture\n", $email->attachments[0]->content());
    }
    public function testUnknownCodePageIsVisibleAndStrictModeFails(): void
    {
        $result = (new EmailParser())->parseFile(__DIR__.'/Fixtures/unknown-code-page.msg');
        self::assertSame('unknown_code_page', $result->warnings[0]->code);
        $this->expectException(InvalidEmailException::class);
        (new EmailParser(new ParseOptions(strict:true)))->parseFile(__DIR__.'/Fixtures/unknown-code-page.msg');
    }
}
