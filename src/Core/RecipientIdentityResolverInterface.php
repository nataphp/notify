<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Core;

use Nata\Controller\Controller;

/**
 * Resolve the current recipient identity for an HTTP request context.
 *
 * Implementations are provided by the host application.
 */
interface RecipientIdentityResolverInterface {
    public function resolve(Controller $controller): ?RecipientIdentity;
}

