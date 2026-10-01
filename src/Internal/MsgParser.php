<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Internal;

use JhumanJ\EmailParser\{Address,Attachment,EmailMessage,Format};
use JhumanJ\EmailParser\Exception\InvalidEmailException;
use ZBateson\MailMimeParser\Message;

/** @internal */
final class MsgParser
{
    public function parse(string $raw, ParseContext $context, int $depth = 0): EmailMessage
    {
        $context->nesting($depth);
        $cfb = new CompoundFile($raw, $context->options);
        return $this->message($cfb, 0, $context, $depth, false, $raw);
    }
    private function message(CompoundFile $cfb, int $storage, ParseContext $context, int $depth, bool $embedded, ?string $raw = null): EmailMessage
    {
        $context->nesting($depth);
        $p = new MsgProperties($cfb, $storage, $context, $embedded);
        $messageClass = $p->string(0x001A);
        if ($messageClass !== null && strcasecmp($messageClass, 'IPM.Note') !== 0 && !str_starts_with(strtolower($messageClass), 'ipm.note.')) {
            $context->warning('unsupported_message_class', 'MSG object '.$messageClass.' is not an email; only common properties are extracted.');
        }
        $transport = $p->string(0x007D) ?? '';
        $meta = EmlParser::metadata(Message::from(rtrim($transport)."\r\n\r\n", false));
        $meta['subject'] = $p->string(0x0037) ?? $meta['subject'];
        $meta['messageId'] = $p->string(0x1035) ?? $meta['messageId'];
        $meta['date'] = $meta['date'] ?? $p->date(0x0039) ?? $p->date(0x0E06);
        $sender = $p->string(0x5D01) ?? $p->string(0x0C1F);
        if ($sender !== null && $sender !== '') {
            $meta['from'] = [new Address($sender, $p->string(0x0C1A))];
        }
        $children = $cfb->children($storage);
        ksort($children);
        $recipients = ['to' => [],'cc' => [],'bcc' => []];
        foreach ($children as $name => $id) {
            if (!str_starts_with($name, '__recip_version1.0_#')) {
                continue;
            }
            if (!$cfb->isStorage($id)) {
                throw new InvalidEmailException('Recipient is not a storage.');
            }
            $r = new MsgProperties($cfb, $id, $context, other:true, encoding:$p->encoding);
            $address = $r->string(0x39FE) ?? $r->string(0x3003);
            if ($address === null || $address === '') {
                $context->warning('missing_recipient_address', 'MSG recipient has no address.');
                continue;
            }
            if (($r->string(0x3002) ?? 'SMTP') !== 'SMTP' && $r->string(0x39FE) === null) {
                $context->warning('unresolved_exchange_address', 'Exchange recipient has no SMTP address.');
            }
            $type = match($r->integer(0x0C15)) {
                1 => 'to',2 => 'cc',3 => 'bcc',default => null
            };
            if ($type !== null) {
                $recipients[$type][] = new Address($address, $r->string(0x3001));
            }
        }
        foreach ($recipients as $key => $addresses) {
            if ($addresses !== []) {
                $meta[$key] = $addresses;
            }
        }
        $text = $p->string(0x1000);
        $htmlRaw = $p->binary(0x1013, $context->options->maxBodyBytes);
        $html = $htmlRaw === null ? $p->string(0x1013) : rtrim(mb_convert_encoding($htmlRaw, 'UTF-8', mb_check_encoding($htmlRaw, 'UTF-8') ? 'UTF-8' : $p->encoding), "\0");
        if ($text === null || $html === null) {
            $rtf = $p->binary(0x1009, $context->options->maxInputBytes);
            if ($rtf !== null) {
                [$rtfText,$rtfHtml] = RtfDecoder::bodies(RtfDecoder::decompress($rtf, $context->options->maxBodyBytes), $context);
                $text ??= $rtfText;
                $html ??= $rtfHtml;
            }
        }
        if ($text === null && $html !== null) {
            $text = RtfDecoder::htmlText($html);
        }
        $context->body($text);
        $context->body($html);
        $attachments = [];
        foreach ($children as $name => $id) {
            if (!str_starts_with($name, '__attach_version1.0_#')) {
                continue;
            }
            if (!$cfb->isStorage($id)) {
                throw new InvalidEmailException('Attachment is not a storage.');
            }
            $a = new MsgProperties($cfb, $id, $context, other:true, encoding:$p->encoding);
            $filename = $a->string(0x3707) ?? $a->string(0x3704) ?? 'attachment.bin';
            $method = $a->integer(0x3705) ?? 1;
            $nested = null;
            $canonical = null;
            if ($method === 5) {
                $nestedId = $cfb->children($id)['__substg1.0_3701000D'] ?? null;
                if ($nestedId === null || !$cfb->isStorage($nestedId)) {
                    throw new InvalidEmailException('Missing embedded MSG storage.');
                }
                $nested = $this->message($cfb, $nestedId, $context, $depth + 1, true);
                $bytes = $nested->toEml();
                $type = 'message/rfc822';
                $canonical = preg_replace('/\.msg$/i', '.eml', $filename) ?? $filename;
                if ($canonical === $filename) {
                    $canonical .= '.eml';
                }
            } elseif ($method === 1) {
                $bytes = $a->binary(0x3701, $context->options->maxAttachmentBytes);
                if ($bytes === null) {
                    throw new InvalidEmailException('Missing attachment data.');
                }
                $type = $a->string(0x370E) ?? 'application/octet-stream';
                if ($type === 'message/rfc822') {
                    $nested = (new EmlParser())->parse($bytes, $context, $depth + 1);
                } elseif (str_starts_with($bytes, CompoundFile::SIGNATURE) && ($type === 'application/vnd.ms-outlook' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'msg')) {
                    $nested = $this->parse($bytes, $context, $depth + 1);
                }
            } else {
                $context->warning('unsupported_attachment', 'MSG attachment method '.$method.' cannot be extracted: '.$filename);
                continue;
            }
            if (!preg_match('~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+$~', $type)) {
                $context->warning('invalid_content_type', 'Invalid attachment MIME type; using application/octet-stream.');
                $type = 'application/octet-stream';
            }
            $context->attachment($bytes);
            $cid = $a->string(0x3712);
            $attachments[] = new Attachment($filename, $type, $bytes, $cid === null ? null : trim($cid, '<>'), $a->boolean(0x7FFE) || $cid !== null, $nested, $canonical);
        }
        $this->checkBody($text, $html, $context);
        return new EmailMessage(Format::MSG, ...array_merge($meta, ['textBody' => $text,'htmlBody' => $html,'attachments' => $attachments,'originalContent' => $raw]));
    }
    private function checkBody(?string $text, ?string $html, ParseContext $context): void
    {
        if ($text === null && $html === null) {
            $context->warning('missing_body', 'No readable email body found.');
        }
    }
}
