<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Internal;

use JhumanJ\EmailParser\{Address,Attachment,EmailMessage,Format};
use JhumanJ\EmailParser\Exception\InvalidEmailException;
use ZBateson\MailMimeParser\{Message,IMessage};
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Message\{IMessagePart,IMultiPart};

/** @internal */
final class EmlParser
{
    public function parse(string $raw, ParseContext $context, int $depth = 0): EmailMessage
    {
        $context->nesting($depth);
        if (!preg_match('/\A[!-9;-~]+:[^\r\n]*(?:\r?\n[ \t][^\r\n]*)*(?:\r?\n|\z)/', $raw)
            || !preg_match('/\r?\n\r?\n/', $raw)) {
            throw new InvalidEmailException('Input is not an RFC email with a header/body separator.');
        }
        $message = Message::from($raw, false);
        $meta = self::metadata($message);
        $content = new MimeContent();
        $walk = function (IMessagePart $part, int $partDepth) use (&$walk, $content, $context, $depth): void {
            $context->part();
            ParseContext::limit($partDepth, 64, 'MIME container depth');
            $type = $part->getContentType() ?? 'application/octet-stream';
            $filename = $part->getFilename();
            $isAttachment = $part->getContentDisposition() === 'attachment' || $filename !== null || $type === 'message/rfc822'
                || (!str_starts_with($type, 'multipart/') && !in_array($type, ['text/plain','text/html'], true));
            if ($isAttachment) {
                $stream = $part->getBinaryContentStream();
                $bytes = $stream === null ? '' : self::read($stream, $context->options->maxAttachmentBytes);
                $context->attachment($bytes);
                $embedded = null;
                if ($type === 'message/rfc822') {
                    $embedded = (new self())->parse($bytes, $context, $depth + 1);
                } elseif (str_starts_with($bytes, CompoundFile::SIGNATURE) && ($type === 'application/vnd.ms-outlook' || strtolower(pathinfo($filename ?? '', PATHINFO_EXTENSION)) === 'msg')) {
                    $embedded = (new MsgParser())->parse($bytes, $context, $depth + 1);
                }
                $content->attachments[] = new Attachment(
                    $filename ?? ($type === 'message/rfc822' ? 'message.eml' : 'attachment.bin'),
                    $type,
                    $bytes,
                    ($cid = $part->getContentId()) === null ? null : trim($cid, '<>'),
                    $part->getContentDisposition() === 'inline',
                    $embedded
                );
                return;
            }
            if ($part instanceof IMultiPart && $part->getChildCount() > 0) {
                foreach ($part->getChildParts() as $child) {
                    $walk($child, $partDepth + 1);
                }
                return;
            }
            if (in_array($type, ['text/plain','text/html'], true)) {
                $stream = $part->getContentStream();
                $body = $stream === null ? null : self::read($stream, $context->options->maxBodyBytes);
                if ($type === 'text/plain' && $content->text === null) {
                    $content->text = $body;
                } elseif ($type === 'text/html' && $content->html === null) {
                    $content->html = $body;
                }
            }
        };
        try {
            $walk($message, 0);
        } finally {
            // Break the recursive closure's reference to itself, including on errors.
            $walk = null;
        }
        if ($content->text === null && $content->html !== null) {
            $content->text = RtfDecoder::htmlText($content->html);
            $context->body($content->text);
        }
        if ($content->text === null) {
            $context->warning('missing_body', 'No readable email body found.');
        }
        return new EmailMessage(Format::EML, ...array_merge($meta, ['textBody' => $content->text,'htmlBody' => $content->html,'attachments' => $content->attachments,'originalContent' => $raw]));
    }
    /** @return array{subject:?string,from:list<Address>,to:list<Address>,cc:list<Address>,bcc:list<Address>,replyTo:list<Address>,date:?\DateTimeImmutable,messageId:?string,headers:array<string,list<string>>} */
    public static function metadata(IMessage $message): array
    {
        $headers = [];
        foreach ($message->getAllHeaders() as $header) {
            $headers[strtolower($header->getName())][] = $header->getValue() ?? '';
        }
        $date = null;
        if (($value = $message->getHeaderValue('Date')) !== null) {
            try {
                $date = new \DateTimeImmutable($value);
            } catch (\Exception) {
            }
        }
        $addressLists = [];
        foreach (['from' => 'From','to' => 'To','cc' => 'Cc','bcc' => 'Bcc','replyTo' => 'Reply-To'] as $key => $name) {
            $addressLists[$key] = [];
            foreach ($message->getAllHeadersByName($name) as $header) {
                if ($header instanceof AddressHeader) {
                    foreach ($header->getAddresses() as $address) {
                        $addressLists[$key][] = new Address($address->getEmail(), $address->getName());
                    }
                }
            }
        }
        return ['subject' => $message->getHeaderValue('Subject'),...$addressLists,'date' => $date,'messageId' => ($id = $message->getHeaderValue('Message-ID')) === null ? null : '<'.trim($id, '<>').'>','headers' => $headers];
    }
    private static function read(\Psr\Http\Message\StreamInterface $stream, int $limit): string
    {
        $stream->rewind();
        $bytes = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(min(8192, max(1, $limit - strlen($bytes) + 1)));
            if ($chunk === '') {
                break;
            }
            $bytes .= $chunk;
            ParseContext::limit(strlen($bytes), $limit, 'Decoded MIME bytes');
        }
        return $bytes;
    }
}
