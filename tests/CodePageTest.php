<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Tests;

use JhumanJ\EmailParser\EmailParser;
use JhumanJ\EmailParser\ParseOptions;
use PHPUnit\Framework\TestCase;

final class CodePageTest extends TestCase
{
    public function testVietnameseCodePagePreservesAnsiAndHtmlBodiesAndAttachments(): void
    {
        $result = (new EmailParser(new ParseOptions(strict: true)))->parseFile(__DIR__.'/Fixtures/code-page-1258.msg');
        self::assertSame('Thơ', $result->message->subject);
        self::assertSame('Thơ', $result->message->textBody);
        self::assertSame('<p>Thơ</p>', $result->message->htmlBody);
        self::assertSame("%PDF-1.4\nfixture\n", $result->message->attachments[0]->content());
        self::assertSame([], $result->warnings);
    }

    public function testVietnameseRtfUsesTheDeclaredCodePage(): void
    {
        $result = (new EmailParser(new ParseOptions(strict: true)))->parseFile(__DIR__.'/Fixtures/rtf-code-page-1258.msg');
        self::assertSame('Thơ', $result->message->textBody);
        self::assertSame([], $result->warnings);
    }

    public function testZeroMessageCodePageUsesAnExplicitInternetCodePage(): void
    {
        $result = (new EmailParser(new ParseOptions(strict: true)))->parseFile(__DIR__.'/Fixtures/zero-message-code-page.msg');
        self::assertSame('Thơ', $result->message->subject);
        self::assertSame('Thơ', $result->message->textBody);
        self::assertSame([], $result->warnings);
    }
}
