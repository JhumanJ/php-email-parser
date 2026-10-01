<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Tests;

use JhumanJ\EmailParser\{Attachment,EmailParser,Format,ParseOptions};
use JhumanJ\EmailParser\Exception\{InvalidEmailException,LimitExceededException};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RobustnessTest extends TestCase
{
    public function testAttachmentNamesCannotEscapeTheDestinationDirectory(): void
    {
        $attachment = new Attachment("..\\..\\secret\0.pdf", 'application/pdf', 'bytes');
        self::assertSame('secret.pdf', $attachment->filename);
        self::assertSame("..\\..\\secret\0.pdf", $attachment->originalFilename);
        self::assertSame('attachment.bin', (new Attachment('../', 'application/octet-stream', ''))->filename);
        self::assertSame(5, $attachment->size());
    }
    public function testStreamPositionAndOwnershipAreRespected(): void
    {
        $raw = "Subject: Test\r\n\r\nBody";
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, 'prefix'.$raw);
            fseek($stream, 6);
            self::assertSame('Test', (new EmailParser())->parseStream($stream)->message->subject);
            self::assertTrue(is_resource($stream));
        } finally {
            fclose($stream);
        }
    }
    public function testHtmlOnlyBodyIsReadableWithoutScriptText(): void
    {
        $message = (new EmailParser())->parse("Subject: HTML\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>Bonjour &amp; merci</p><script>alert('bad')</script>")->message;
        self::assertSame('Bonjour & merci', $message->textBody);
        self::assertStringContainsString('<script>', $message->htmlBody ?? '');
    }
    public function testEncodedHeadersAddressGroupsAndTextAttachment(): void
    {
        $raw = "From: =?UTF-8?Q?Jos=C3=A9?= <jose@example.com>\r\nTo: Team: a@example.com, \"B Person\" <b@example.com>;\r\nSubject: =?UTF-8?Q?=C3=89t=C3=A9?=\r\nContent-Type: multipart/mixed; boundary=x\r\n\r\n--x\r\nContent-Type: text/plain; charset=iso-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n=C9t=E9\r\n--x\r\nContent-Type: text/plain\r\nContent-Disposition: attachment; filename=notes.txt\r\n\r\nExact attachment\r\n--x--\r\n";
        $email = (new EmailParser())->parse($raw)->message;
        self::assertSame('Été', $email->subject);
        self::assertSame('José', $email->from[0]->name);
        self::assertCount(2, $email->to);
        self::assertSame('B Person', $email->to[1]->name);
        self::assertSame('Été', $email->textBody);
        self::assertSame('Exact attachment', $email->attachments[0]->content());
    }
    public function testExportRetainsRepeatedHeadersAndNonAddressContentId(): void
    {
        $parser = new EmailParser();
        $raw = "From: a@example.com\r\nSubject: Cid\r\nReceived: hop one\r\nReceived: hop two\r\nContent-Type: multipart/mixed; boundary=x\r\n\r\n--x\r\nContent-Type: text/plain\r\n\r\nHi\r\n--x\r\nContent-Type: image/gif\r\nContent-ID: <local-image.gif>\r\nContent-Disposition: inline; filename=logo.gif\r\nContent-Transfer-Encoding: base64\r\n\r\nR0lG\r\n--x--\r\n";
        $email = $parser->parse($raw)->message;
        $copy = $parser->parse($email->toEml())->message;
        self::assertSame(['hop one','hop two'], $copy->headers['received']);
        self::assertSame('local-image.gif', $copy->attachments[0]->contentId);
        self::assertSame('GIF', $copy->attachments[0]->content());
    }
    public function testContainerAndDirectoryCountLimits(): void
    {
        foreach ([['basic.eml',new ParseOptions(maxMimeParts:2)],['basic.msg',new ParseOptions(maxDirectoryEntries:2)]] as [$file,$options]) {
            try {
                (new EmailParser($options))->parseFile(__DIR__.'/Fixtures/'.$file);
                self::fail('Limit was not enforced.');
            } catch (LimitExceededException) {
                self::assertTrue(true);
            }
        }
    }
    public function testOleDocumentIsNotMistakenForAnEmbeddedEmail(): void
    {
        $bytes = file_get_contents(__DIR__.'/Fixtures/basic.msg');
        $raw = "Subject: Doc\r\nContent-Type: multipart/mixed; boundary=x\r\n\r\n--x\r\nContent-Type: application/msword\r\nContent-Disposition: attachment; filename=document.doc\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode($bytes)."\r\n--x--\r\n";
        $attachment = (new EmailParser())->parse($raw)->message->attachments[0];
        self::assertNull($attachment->embeddedMessage);
        self::assertSame($bytes, $attachment->content());
    }
    public function testExplicitFormatAndFileErrors(): void
    {
        $this->expectException(InvalidEmailException::class);
        (new EmailParser())->parse(file_get_contents(__DIR__.'/Fixtures/basic.msg'), Format::EML);
    }
    public function testMissingFileFailsExplicitly(): void
    {
        $this->expectException(InvalidEmailException::class);
        (new EmailParser())->parseFile(__DIR__.'/does-not-exist.eml');
    }
    public function testNegativeLimitsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ParseOptions(maxInputBytes:-1);
    }
    #[DataProvider('mutatedHeaders')]
    public function testInvalidCfbMetadataCannotCauseNativeErrors(int $offset, string $replacement): void
    {
        $bytes = file_get_contents(__DIR__.'/Fixtures/basic.msg');
        $bytes = substr_replace($bytes, $replacement, $offset, strlen($replacement));
        $this->expectException(InvalidEmailException::class);
        (new EmailParser())->parse($bytes, Format::MSG);
    }
    public static function mutatedHeaders(): array
    {
        return [[26,pack('v', 5)],[28,pack('v', 0)],[30,pack('v', 31)],[32,pack('v', 7)],
            [44,pack('V', 0)],[44,pack('V', 0xffffffff)],[48,pack('V', 0xffffffff)],
            [56,pack('V', 2048)],[64,pack('V', 0xffffff00)],[72,pack('V', 0xffffff00)],[76,pack('V', 0xffffff00)]];
    }
}
