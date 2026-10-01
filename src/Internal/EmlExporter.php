<?php

declare(strict_types=1);

namespace JhumanJ\EmailParser\Internal;

use JhumanJ\EmailParser\EmailMessage;
use JhumanJ\EmailParser\Exception\InvalidEmailException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Part\{DataPart,TextPart};
use Symfony\Component\Mime\Part\Multipart\{AlternativePart,MixedPart};

/** @internal */
final class EmlExporter
{
    public static function export(EmailMessage $email): string
    {
        try {
            $headers = new Headers();
            foreach (['from' => 'From','to' => 'To','cc' => 'Cc','bcc' => 'Bcc','replyTo' => 'Reply-To'] as $property => $name) {
                if ($email->$property !== []) {
                    $headers->addMailboxListHeader($name, array_map(fn ($a) => new Address($a->address, $a->name ?? ''), $email->$property));
                }
            }
            if ($email->subject !== null) {
                $headers->addTextHeader('Subject', $email->subject);
            }
            if ($email->date !== null) {
                $headers->addDateHeader('Date', $email->date);
            }
            if ($email->messageId !== null) {
                $headers->addIdHeader('Message-ID', trim($email->messageId, '<>'));
            }
            $headers->addTextHeader('MIME-Version', '1.0');
            $excluded = ['from','to','cc','bcc','reply-to','subject','date','message-id','mime-version','content-type','content-transfer-encoding','content-disposition','content-id','content-length','dkim-signature','domainkey-signature'];
            foreach ($email->headers as $name => $values) {
                if (!in_array(strtolower($name), $excluded, true)) {
                    foreach ($values as $value) {
                        $headers->addTextHeader($name, $value);
                    }
                }
            }
            $bodies = [];
            if ($email->textBody !== null) {
                $bodies[] = new TextPart($email->textBody, 'utf-8', 'plain');
            }
            if ($email->htmlBody !== null) {
                $bodies[] = new TextPart($email->htmlBody, 'utf-8', 'html');
            }
            $body = count($bodies) === 2 ? new AlternativePart(...$bodies) : ($bodies[0] ?? new TextPart(''));
            $parts = [$body];
            foreach ($email->attachments as $attachment) {
                $part = new DataPart($attachment->content(), $attachment->filename, $attachment->contentType, 'base64');
                if ($attachment->inline) {
                    $part->asInline();
                }
                if ($attachment->contentId !== null) {
                    $part->getHeaders()->addTextHeader('Content-ID', '<'.trim($attachment->contentId, '<>').'>');
                }
                $parts[] = $part;
            }
            if (count($parts) > 1) {
                $body = new MixedPart(...$parts);
            }
            // Archive serialization: retain Bcc, dates and IDs instead of preparing a message for sending.
            return $headers->toString().$body->toString();
        } catch (\InvalidArgumentException|\Symfony\Component\Mime\Exception\ExceptionInterface $e) {
            throw new InvalidEmailException('Unable to serialize email: '.$e->getMessage(), 0, $e);
        }
    }
}
