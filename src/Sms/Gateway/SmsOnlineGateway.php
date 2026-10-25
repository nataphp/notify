<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push, SMS) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Sms\Gateway;

/**
 * smsonline.pt.
 *
 * smsonline.pt offers a REST API (JSON over HTTPS), an HTTP GET API and SMPP,
 * but does not publish their specification: it comes with the account
 * (comercial@smsonline.pt). Until then this adapter is the configurable
 * GenericHttpGateway under its own name, and expects the endpoint and the
 * field names in `Notify.sms.gateways.smsonline` (url, fields, params or
 * headers for the key, messageIdPath, successPath…).
 *
 * Once the specification is known, its fixed parts (endpoint, field names,
 * how the key is sent, where the message ID and the status are) go in
 * a DEFAULTS constant here (it overrides GenericHttpGateway::DEFAULTS), and
 * the configuration only keeps the key.
 */
class SmsOnlineGateway extends GenericHttpGateway {

    /**
     * @inheritDoc
     */
    public function name(): string {
        return 'smsonline';
    }

}
