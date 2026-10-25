<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Service\Channel;

use Nata\Core\Configure;
use Nata\Email\Address;
use Nata\Email\AddressCollection;
use Nata\Email\Mailer;
use Notify\Core\ChannelInterface;
use Notify\Core\NonRetryableChannelException;
use Notify\Core\NotificationDefinition;
use Notify\Core\RecipientAddress;
use Notify\Core\RetryableChannelException;

/**
 * Email delivery channel.
 *
 * Payload shape (rendered by `ChannelPayloadRenderer::renderEmail()`):
 * - subject: string
 * - html: string
 * - text: string
 *
 * Context keys (optional, all non-retryable if invalid):
 * - from:     string|Address
 * - reply_to: string|Address
 * - sender:   string|Address
 * - cc:       string|Address|AddressCollection
 * - bcc:      string|Address|AddressCollection
 * - headers:  array<string,string> extra headers (e.g. List-Unsubscribe, RFC 8058)
 *
 * Failure behavior:
 * - Missing/invalid recipient or address fields throw `NonRetryableChannelException`.
 * - Transient send issues throw `RetryableChannelException`.
 */
final class EmailChannel implements ChannelInterface {
    /**
     * @inheritDoc
     */
    public function name(): string {
        return 'email';
    }

    /**
     * @inheritDoc
     *
     * @throws NonRetryableChannelException When recipient email is missing/invalid, or address fields are invalid.
     * @throws RetryableChannelException When the mailer fails in a retryable way.
     */
    public function sendNow(RecipientAddress $to, array $payload, NotificationDefinition $definition, array $context): ?string {
        $email = (string) ($to->email ?? '');
        if (trim($email) === '') {
            throw new NonRetryableChannelException('Missing recipient email.', 'email', 'missing_recipient');
        }
        $toAddress = new Address($email);
        if (!$toAddress->isValid()) {
            throw new NonRetryableChannelException(
                sprintf('Invalid recipient email: %s.', $email),
                'email',
                'invalid_recipient'
            );
        }

        if ($context['from'] ?? null) {
            $this->_assertValidAddress($context['from'], 'from');
        }
        if ($context['reply_to'] ?? null) {
            $this->_assertValidAddress($context['reply_to'], 'reply_to');
        }
        if ($context['sender'] ?? null) {
            $this->_assertValidAddress($context['sender'], 'sender');
        }
        if ($context['cc'] ?? null) {
            $this->_assertValidAddress($context['cc'], 'cc');
        }
        if ($context['bcc'] ?? null) {
            $this->_assertValidAddress($context['bcc'], 'bcc');
        }

        $subject = (string) ($payload['subject'] ?? '');
        $html = (string) ($payload['html'] ?? '');
        $text = (string) ($payload['text'] ?? '');

        try {
            $profile = Configure::read('Notify.mailerProfile') ?? 'default';
            $mailer = new Mailer($profile);

            if ($context['from'] ?? null) {
                $mailer->from($context['from']);
            }
            if ($context['reply_to'] ?? null) {
                $mailer->replyTo($context['reply_to']);
            }
            if ($context['sender'] ?? null) {
                $mailer->sender($context['sender']);
            }
            if ($context['cc'] ?? null) {
                $mailer->cc($context['cc']);
            }
            if ($context['bcc'] ?? null) {
                $mailer->bcc($context['bcc']);
            }
            foreach ((array) ($context['headers'] ?? []) as $headerName => $headerValue) {
                $mailer->header((string) $headerName, (string) $headerValue);
            }

            $mailer->to($email);
            $mailer->subject($subject);
            $body = [];
            if (trim($html) !== '') {
                $body['html'] = $html;
            }
            if (trim($text) !== '') {
                $body['text'] = $text;
            }
            $mailer->send($body);

            if ($mailer->hasError()) {
                $errors = (array) $mailer->getError();
                $detail = implode('; ', array_filter(array_map('strval', $errors)));
                throw new RetryableChannelException($detail ?: 'Send failed.', 'email', 'mailer_error', null);
            }

            return $mailer->transport()->getId();
        } catch (\BadMethodCallException $e) {
            // e.g. missing "from" configuration – this is a deploy/config problem, not retryable.
            throw new NonRetryableChannelException($e->getMessage(), 'email', 'misconfigured', $e);
        } catch (RetryableChannelException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RetryableChannelException($e->getMessage(), 'email', 'mailer_error', $e);
        }
    }

    /**
     * Assert that a value resolves to one or more valid email addresses.
     *
     * @throws NonRetryableChannelException On empty or invalid address.
     */
    private function _assertValidAddress(mixed $value, string $field): void {
        if ($value instanceof AddressCollection) {
            if ($value->isEmpty()) {
                throw new NonRetryableChannelException(
                    sprintf("Empty address collection for '%s'.", $field),
                    'email',
                    'invalid_address'
                );
            }
            foreach ($value as $address) {
                if (!$address->isValid()) {
                    throw new NonRetryableChannelException(
                        sprintf("Invalid email address in '%s': %s.", $field, $address->email()),
                        'email',
                        'invalid_address'
                    );
                }
            }
            return;
        }

        $address = $value instanceof Address ? $value : new Address((string) $value);
        if ($address->isEmpty() || !$address->isValid()) {
            throw new NonRetryableChannelException(
                sprintf("Invalid email address for '%s'.", $field),
                'email',
                'invalid_address'
            );
        }
    }
}
