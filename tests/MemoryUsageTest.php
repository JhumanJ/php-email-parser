<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Tests;

use JhumanJ\EmailParser\EmailParser;
use JhumanJ\EmailParser\ParseOptions;
use JhumanJ\EmailParser\Exception\LimitExceededException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MemoryUsageTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function outcomes(): iterable
    {
        yield 'successful reads' => [false];
        yield 'rejected bodies' => [true];
    }

    #[DataProvider('outcomes')]
    public function testRepeatedReadsReleaseTemporaryMimeGraphs(bool $reject): void
    {
        $raw = "From: fixture@example.com\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n".str_repeat('x', 250_000);
        $parser = new EmailParser(new ParseOptions(maxBodyBytes: $reject ? 100_000 : 300_000));
        // Warm the MIME engine once to exclude its shared service cache.
        (new EmailParser())->parse("From: fixture@example.com\r\n\r\nWarmup");
        gc_collect_cycles();
        $before = memory_get_usage();
        for ($i = 0; $i < 20; $i++) {
            try {
                $result = $parser->parse($raw);
                self::assertFalse($reject);
                self::assertSame(250_000, strlen($result->message->textBody ?? ''));
                unset($result);
            } catch (LimitExceededException) {
                self::assertTrue($reject);
            }
        }
        // Deliberately do not force GC here: callers should not accumulate
        // temporary input/body buffers across a batch or queue-worker loop.
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $before);
    }
}
