<?php
declare(strict_types=1);

namespace JhumanJ\EmailParser\Tests;

use JhumanJ\EmailParser\EmailParser;
use JhumanJ\EmailParser\Format;
use JhumanJ\EmailParser\ParseOptions;
use JhumanJ\EmailParser\Exception\InvalidEmailException;
use JhumanJ\EmailParser\Exception\LimitExceededException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testEmlAndMsgExposeTheSameTypedContract(): void
    {
        foreach (['basic.eml', 'basic.msg', 'basic-v4.msg'] as $file) {
            $result = (new EmailParser())->parseFile(__DIR__.'/Fixtures/'.$file);
            $email = $result->message;
            self::assertSame('Facture été', $email->subject);
            self::assertSame('billing@example.com', $email->from[0]->address);
            self::assertSame('Équipe facturation', $email->from[0]->name);
            self::assertSame('operations@example.net', $email->to[0]->address);
            self::assertSame('audit@example.net', $email->cc[0]->address);
            self::assertSame('private@example.net', $email->bcc[0]->address);
            self::assertSame('support@example.com', $email->replyTo[0]->address);
            self::assertSame('2026-10-01T10:30:00+02:00', $email->date?->format(DATE_ATOM));
            self::assertSame('<fixture@example.com>', $email->messageId);
            self::assertStringContainsString('Bonjour été', $email->textBody ?? '');
            self::assertStringContainsString('<p>Bonjour été</p>', $email->htmlBody ?? '');
            self::assertCount(2, $email->attachments);
            self::assertSame('facture.pdf', $email->attachments[0]->filename);
            self::assertSame('application/pdf', $email->attachments[0]->contentType);
            self::assertSame("%PDF-1.4\nfixture\n", $email->attachments[0]->content());
            self::assertSame('logo@example.com', $email->attachments[1]->contentId);
            self::assertTrue($email->attachments[1]->inline);
            self::assertSame([], $result->warnings);
        }
    }

    public function testAutoDetectionUsesBytesRatherThanAnExtension(): void
    {
        $parser = new EmailParser();
        self::assertSame(Format::MSG, $parser->parse(file_get_contents(__DIR__.'/Fixtures/basic.msg'))->message->format);
        self::assertSame(Format::EML, $parser->parse(file_get_contents(__DIR__.'/Fixtures/basic.eml'))->message->format);
        $stream = fopen(__DIR__.'/Fixtures/basic.msg', 'rb');
        try { self::assertSame('Facture été', $parser->parseStream($stream)->message->subject); }
        finally { fclose($stream); }
    }

    public function testAnsiMessageUsesItsDeclaredCodePage(): void
    {
        $email = (new EmailParser())->parseFile(__DIR__.'/Fixtures/ansi.msg')->message;
        self::assertSame('Facture été', $email->subject);
        self::assertSame('Bonjour été €', $email->textBody);
    }

    public function testRtfOnlyMessageProducesReadableText(): void
    {
        $email = (new EmailParser())->parseFile(__DIR__.'/Fixtures/rtf-only.msg')->message;
        self::assertStringContainsString('Bonjour été €', $email->textBody ?? '');
        self::assertStringContainsString('Deuxième ligne', $email->textBody ?? '');
        self::assertStringNotContainsString('Hidden font', $email->textBody ?? '');
    }

    public function testHtmlEncapsulatedInRtfIsExtracted(): void
    {
        $email = (new EmailParser())->parseFile(__DIR__.'/Fixtures/rtf-html.msg')->message;
        self::assertStringContainsString('<p>Bonjour été</p>', $email->htmlBody ?? '');
        self::assertStringContainsString('Bonjour été', $email->textBody ?? '');
    }

    public function testEmbeddedMsgIsAvailableAndExportsAsAReadableEml(): void
    {
        $email = (new EmailParser())->parseFile(__DIR__.'/Fixtures/nested.msg')->message;
        $attachment = $email->attachments[0];
        self::assertSame('forwarded.msg', $attachment->originalFilename);
        self::assertSame('forwarded.eml', $attachment->filename);
        self::assertSame('message/rfc822', $attachment->contentType);
        self::assertSame('Facture été', $attachment->embeddedMessage?->subject);
        $roundTrip = (new EmailParser())->parse($attachment->content())->message;
        self::assertSame('Facture été', $roundTrip->subject);
        self::assertCount(2, $roundTrip->attachments);
        self::assertSame("%PDF-1.4\nfixture\n", $roundTrip->attachments[0]->content());
    }

    public function testEmbeddedEmlIsParsedWithoutLosingItsOriginalBytes(): void
    {
        $email = (new EmailParser())->parseFile(__DIR__.'/Fixtures/nested.eml')->message;
        self::assertSame('Facture été', $email->attachments[0]->embeddedMessage?->subject);
        self::assertSame(file_get_contents(__DIR__.'/Fixtures/basic.eml'), $email->attachments[0]->content());
    }

    public function testExportPreservesRecipientsDateBodiesAndBinaryAttachments(): void
    {
        $parser = new EmailParser();
        $email = $parser->parseFile(__DIR__.'/Fixtures/basic.msg')->message;
        $copy = $parser->parse($email->toEml())->message;
        self::assertSame($email->subject, $copy->subject);
        self::assertSame($email->bcc[0]->address, $copy->bcc[0]->address);
        self::assertSame($email->date?->getTimestamp(), $copy->date?->getTimestamp());
        self::assertSame($email->messageId, $copy->messageId);
        self::assertSame($email->attachments[0]->content(), $copy->attachments[0]->content());
        self::assertSame($email->attachments[1]->contentId, $copy->attachments[1]->contentId);
    }

    public function testAttachmentStreamsAreIndependentAndPreserveBytes(): void
    {
        $attachment = (new EmailParser())->parseFile(__DIR__.'/Fixtures/basic.msg')->message->attachments[0];
        $a = $attachment->openStream(); $b = $attachment->openStream();
        try { fread($a, 5); self::assertSame($attachment->content(), stream_get_contents($b)); }
        finally { fclose($a); fclose($b); }
    }

    public function testRepeatedHeadersArePreserved(): void
    {
        $email = (new EmailParser())->parse("From: sender@example.com\r\nReceived: first\r\nReceived: second\r\nSubject: Hi\r\n\r\nBody")->message;
        self::assertSame(['first', 'second'], $email->headers['received']);
    }

    public function testMissingBodyIsExplicitAndDoesNotHideAttachments(): void
    {
        $result = (new EmailParser())->parseFile(__DIR__.'/Fixtures/no-body.msg');
        self::assertNull($result->message->textBody);
        self::assertCount(2, $result->message->attachments);
        self::assertContains('missing_body', array_map(fn($warning) => $warning->code, $result->warnings));
    }

    public function testUnsupportedAttachmentProducesAWarningOrFailsInStrictMode(): void
    {
        $result = (new EmailParser())->parseFile(__DIR__.'/Fixtures/unsupported-attachment.msg');
        self::assertContains('unsupported_attachment', array_map(fn($warning) => $warning->code, $result->warnings));
        $this->expectException(InvalidEmailException::class);
        (new EmailParser(new ParseOptions(strict: true)))->parseFile(__DIR__.'/Fixtures/unsupported-attachment.msg');
    }

    #[DataProvider('invalidFiles')]
    public function testMalformedInputsFailWithoutHanging(string $file): void
    {
        $this->expectException(InvalidEmailException::class);
        (new EmailParser())->parseFile(__DIR__.'/Fixtures/'.$file);
    }

    public static function invalidFiles(): array
    {
        return array_map(fn($file) => [$file], ['empty.eml','garbage.eml','truncated.msg','fat-cycle.msg','mini-fat-cycle.msg','directory-cycle.msg','bad-rtf-crc.msg']);
    }

    #[DataProvider('limits')]
    public function testConfiguredLimitsAreEnforced(ParseOptions $options, string $file): void
    {
        $this->expectException(LimitExceededException::class);
        (new EmailParser($options))->parseFile(__DIR__.'/Fixtures/'.$file);
    }

    public static function limits(): array
    {
        return [
            [new ParseOptions(maxInputBytes: 32), 'basic.eml'],
            [new ParseOptions(maxInputBytes: 32), 'basic.msg'],
            [new ParseOptions(maxAttachments: 1), 'basic.msg'],
            [new ParseOptions(maxAttachments: 1), 'basic.eml'],
            [new ParseOptions(maxAttachmentBytes: 4), 'basic.msg'],
            [new ParseOptions(maxAttachmentBytes: 4), 'basic.eml'],
            [new ParseOptions(maxTotalAttachmentBytes: 16), 'basic.msg'],
            [new ParseOptions(maxNestingDepth: 0), 'nested.msg'],
            [new ParseOptions(maxNestingDepth: 0), 'nested.eml'],
            [new ParseOptions(maxBodyBytes: 4), 'rtf-only.msg'],
        ];
    }

    public function testPublicMAPIRegressionSamplePreservesItsKnownContent(): void
    {
        // Assertions adapted from hfig/MAPI tests/MAPI/MapiMessageFactoryTest.php (MIT).
        // Provenance and the upstream license are in tests/Fixtures/README.md.
        $email = (new EmailParser())->parseFile(__DIR__.'/Fixtures/upstream-mapi/sample.msg')->message;
        self::assertSame("Testing Manuel Lemos' MIME E-mail composing and sending PHP class: HTML message", $email->subject);
        self::assertSame('<20050430192829.0489.mlemos@acm.org>', $email->messageId);
        self::assertCount(3, $email->attachments);
        $attachments = array_column(array_map(fn($a) => ['name'=>$a->filename, 'data'=>$a->content()], $email->attachments), 'data', 'name');
        self::assertSame('This is just a plain text attachment file named attachment.txt .', $attachments['attachment.txt']);
        self::assertStringContainsString('Hello Manuel', $email->textBody ?? '');
    }
}
