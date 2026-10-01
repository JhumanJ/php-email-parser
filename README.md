# PHP Email Parser

[![CI](https://github.com/JhumanJ/php-email-parser/actions/workflows/ci.yml/badge.svg)](https://github.com/JhumanJ/php-email-parser/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/jhumanj/email-parser.svg)](https://packagist.org/packages/jhumanj/email-parser)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Read **EML and Outlook MSG** through one typed PHP API. Extract recipients, text, HTML, attachments and embedded emails, then serialize either format to EML. Independent of Laravel and any application storage, queue or OCR system. The production implementation runs entirely in PHP.

## Install

```sh
composer require jhumanj/email-parser
```

Requires **64-bit PHP 8.2+**, `mbstring` and `iconv`. Composer installs ZBateson Mail Mime Parser 3.0.9+ for RFC/MIME parsing and Symfony Mime for EML serialization. MSG/CFB and compressed RTF are implemented in this package; no Python, Outlook, COM, PEAR OLE or Swiftmailer runtime is required.

## Parse an email

```php
use JhumanJ\EmailParser\EmailParser;

$result = (new EmailParser())->parseFile('/path/to/message.msg'); // .eml works too
$email = $result->message;

echo $email->subject;
echo $email->textBody;

foreach ($email->to as $recipient) {
    echo $recipient->address; // UTF-8 display name in $recipient->name
}

foreach ($email->attachments as $attachment) {
    echo $attachment->filename;      // safe basename, no directory components
    echo $attachment->contentType;
    $bytes = $attachment->content(); // decoded binary bytes, not base64
    $stream = $attachment->openStream(); // independent, caller closes it
    fclose($stream);

    if ($attachment->embeddedMessage !== null) {
        echo $attachment->embeddedMessage->subject;
    }
}

foreach ($result->warnings as $warning) {
    echo $warning->code.': '.$warning->message;
}
```

Format detection uses bytes, not filename extensions. Use `parse($bytes)` for strings or `parseStream($resource)` for a stream, read from its current position. Caller-owned streams stay open. An optional `Format::EML` or `Format::MSG` argument forces the format for all three entry points.

`EmailMessage` exposes immutable `format`, `subject`, `from`, `to`, `cc`, `bcc`, `replyTo`, `date` (`DateTimeImmutable|null`), `messageId`, `textBody`, `htmlBody`, `attachments`, `headers` and `originalContent`. Missing values are `null` or empty lists. Text is UTF-8; MSG string terminators/padding are removed. Message IDs include angle brackets. Header keys are lowercase and repeated values remain ordered lists. `originalContent` contains the input bytes; for an MSG embedded as an OLE storage it is `null` because that storage is not an independent MSG file.

Attachments expose `originalFilename`, sanitized `filename`, `contentType`, `contentId` without angle brackets, `inline`, `embeddedMessage`, `size()`, `content()` and `openStream()`. Duplicate filenames are possible: choose your own storage identifiers instead of overwriting files by name. The library never writes attachments to disk.

## Export MSG or EML to EML

```php
file_put_contents('/path/to/export.eml', $email->toEml());
```

This serializes an archive message, retaining Bcc, available dates, message IDs, bodies and binary attachments. MIME boundaries and encodings are regenerated; it is not a byte-identical copy and signatures cannot survive reserialization. Original DKIM/DomainKey signature headers are omitted. Keep `originalContent` when byte-level preservation matters.

An embedded MSG storage becomes a `message/rfc822` attachment with a `.eml` filename, retaining its original `.msg` name in `originalFilename`. Its `content()` is a valid EML and `embeddedMessage` exposes its parsed fields. Embedded EML attachments retain their decoded original bytes.

## Limits and errors

```php
use JhumanJ\EmailParser\ParseOptions;
use JhumanJ\EmailParser\Exception\InvalidEmailException;
use JhumanJ\EmailParser\Exception\LimitExceededException;

$parser = new EmailParser(new ParseOptions(
    strict: true,
    maxInputBytes: 20 * 1024 * 1024,
    maxAttachmentBytes: 10 * 1024 * 1024,
    maxNestingDepth: 5,
));

try {
    $result = $parser->parseFile('/path/to/mail.eml');
} catch (LimitExceededException $e) {
    // A configured resource limit was exceeded.
} catch (InvalidEmailException $e) {
    // Invalid file, corrupt CFB/RTF, or a warning promoted by strict mode.
}
```

| Option | Default | Scope |
| --- | ---: | --- |
| `strict` | `false` | Promote recoverable warnings to exceptions |
| `maxInputBytes` | 50 MiB | Each input read |
| `maxBodyBytes` | 10 MiB | Each decoded body/string and decompressed RTF |
| `maxAttachmentBytes` | 20 MiB | Each decoded/extracted attachment |
| `maxTotalAttachmentBytes` | 50 MiB | Aggregate attachments, including nested messages |
| `maxAttachments` | 100 | Aggregate attachment count |
| `maxNestingDepth` | 10 | Embedded emails; root is depth zero |
| `maxDirectoryEntries` | 10,000 | CFB directory records |
| `maxMimeParts` | 1,000 | Aggregate MIME parts |

CFB chains and directory trees are checked for cycles and invalid indexes. RTF decompression verifies its size and checksum. MIME container depth is capped at 64 and RTF group depth at 256. Input and output objects are buffered in memory: stream entry points are bounded reads, not a constant-memory parser. Choose limits and worker memory/time budgets appropriate to your workload. Zero limits are supported; negative limits are rejected.

Recoverable warning codes currently include `missing_body`, `unsupported_attachment`, `unknown_code_page`, `missing_recipient_address`, `unresolved_exchange_address`, `unsupported_message_class` and `invalid_content_type`. Warnings from nested messages are included in the root result. Unsupported external/OLE attachment methods are explicitly reported and omitted from extracted attachments. Missing bodies do not hide supported attachments.

## Supported scope

| Feature | EML | MSG |
| --- | --- | --- |
| Subject, addresses, dates, repeated transport headers | Yes | Yes, where present |
| MIME charsets, encoded headers, base64/quoted-printable | ZBateson engine | MAPI Unicode and declared ANSI code pages |
| Text and HTML bodies | Yes | Yes |
| LZFu/MELA RTF, text fallback and encapsulated HTML | N/A | Yes |
| Attachments, inline content IDs | Yes | By-value attachments |
| Embedded messages and EML export | Yes | Yes |
| CFB v3/v4, mini streams, FAT/DIFAT | N/A | Yes |

The API targets **email reading/extraction**, not all Outlook item types. Calendar, contact, task and other non-email MSG classes expose only common properties and produce a warning. It does not write MSG, decode TNEF (`winmail.dat`), decrypt S/MIME/IRM, verify signatures, sanitize HTML, render mail or provide a complete MAPI property editor. RTF formatting is reduced to text; unsupported custom destinations and embedded objects are ignored. Encapsulated HTML is extracted, not rendered. HTML must be sanitized by the consuming application before display. This is an initial 0.x release: APIs may evolve between minor releases, and the test corpus does not prove compatibility with every Outlook file.

## Development

```sh
composer install
composer check # PHPUnit, PHPStan and formatting
composer audit
python3 tests/Support/generate-fixtures.py # optional; original fixtures only
```

Tests use PHPUnit directly with no Laravel bootstrap. CI tests PHP 8.2–8.5 and a compatibility job using ZBateson 3.0.9 / Symfony 6.4. The test suite was committed before implementation and covers both formats, Unicode/ANSI/RTF, embedded messages, exact attachment bytes, round trips, resource limits and malformed CFB inputs.

See [fixture provenance](tests/Fixtures/README.md), [implementation references](docs/REFERENCES.md), [contribution guidelines](CONTRIBUTING.md) and [security reporting](SECURITY.md).

## License and acknowledgements

MIT. EML parsing uses [ZBateson Mail Mime Parser](https://github.com/zbateson/mail-mime-parser) (BSD-2-Clause); export uses [Symfony Mime](https://github.com/symfony/mime) (MIT). The public MSG regression fixture and adapted assertions from [hfig/MAPI](https://github.com/hfig/MAPI) retain their MIT notice. [extract-msg](https://github.com/TeamMsgExtractor/msg-extractor) informed behavioral comparisons only; no GPL code, tests or fixtures are distributed here. MSG/CFB/RTF code is an original implementation based on Microsoft specifications.
