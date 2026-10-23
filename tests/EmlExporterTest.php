<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Tests;

use JhumanJ\EmailParser\{EmailMessage,EmailParser,Format};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmlExporterTest extends TestCase
{
    public function testRealWorldMsgWithSenderAndReturnPathExports(): void
    {
        $parser = new EmailParser();
        $original = $parser->parseFile(__DIR__.'/Fixtures/upstream-mapi/sample.msg');
        self::assertSame([], $original->warnings);
        $copy = $parser->parse($original->message->toEml())->message;
        self::assertSame($original->message->subject, $copy->subject);
        self::assertSame($original->message->textBody, $copy->textBody);
        self::assertSame(['mlemos@acm.org'], $copy->headers['sender']);
        self::assertSame(['mlemos@acm.org'], $copy->headers['return-path']);
    }

    #[DataProvider('transportHeaders')]
    public function testTransportHeaderExport(array $headers, array $expected): void
    {
        $message = new EmailMessage(Format::MSG, textBody:'Body', headers:$headers);
        $copy = (new EmailParser())->parse($message->toEml())->message;
        self::assertSame($copy->headers, (new EmailParser())->parse($copy->toEml())->message->headers);
        foreach ($expected as $name => $values) {
            self::assertSame($values, $copy->headers[$name]);
        }
    }

    public static function transportHeaders(): array
    {
        return [
            'bare return path' => [['return-path' => ['bounce@example.com']],['return-path' => ['bounce@example.com']]],
            'bracketed return path' => [['return-path' => ['<bounce@example.com>']],['return-path' => ['bounce@example.com']]],
            'empty return path' => [['return-path' => ['<>']],['return-path' => ['']]],
            'sender with display name' => [['sender' => ['Équipe <sender@example.com>']],['sender' => ['sender@example.com']]],
            'mixed case headers' => [['Sender' => ['sender@example.com'],'Return-Path' => ['<bounce@example.com>']],['sender' => ['sender@example.com'],'return-path' => ['bounce@example.com']]],
        ];
    }

    public function testEmbeddedMsgTransportHeadersSurviveParsingAndExport(): void
    {
        $parser = new EmailParser();
        $original = $parser->parseFile(__DIR__.'/Fixtures/nested-transport-headers.msg');
        self::assertSame([], $original->warnings);
        $copy = $parser->parse($original->message->toEml())->message;
        $embedded = $parser->parse($copy->attachments[0]->content())->message;
        self::assertSame(['sender@example.com'], $embedded->headers['sender']);
        self::assertSame(['bounce@example.com'], $embedded->headers['return-path']);
        self::assertSame("%PDF-1.4\nfixture\n", $embedded->attachments[0]->content());
    }
}
