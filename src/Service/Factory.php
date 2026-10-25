<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Service;

use Notify\Service\Channel\EmailChannel;
use Notify\Service\Channel\InAppChannel;
use Notify\Service\Channel\PushChannel;
use Notify\Service\Channel\SmsChannel;

/**
 * Simple service factory for constructing the Notify bus and notifier.
 *
 * This factory provides a lightweight, static entry point for application code that wants to send
 * notifications without wiring a full DI container.
 *
 * @example
 * $result = Factory::notifier()
 *     ->using('shift.assigned')
 *     ->to($recipient)
 *     ->set(['shift' => ['id' => 123]])
 *     ->send();
 */
final class Factory {
    private static ?Bus $bus = null;
    private static ?Notifier $notifier = null;

    /**
     * Return a singleton `Bus` instance with default channels registered.
     *
     * Channel names registered by default:
     * - in_app
     * - email
     * - push
     * - sms (needs `Notify.sms.gateway`; see GatewayRegistry)
     */
    public static function bus(): Bus {
        if (self::$bus) {
            return self::$bus;
        }

        $definitions = new DefinitionRegistry();
        $locator = TemplateLocator::default();
        $loader = new TemplateLoader($locator);
        $bus = new Bus($definitions, $loader);

        $bus->registerChannel(new InAppChannel());
        $bus->registerChannel(new EmailChannel());
        $bus->registerChannel(new PushChannel());
        $bus->registerChannel(new SmsChannel());

        return self::$bus = $bus;
    }

    /**
     * Return a singleton `Notifier` instance backed by the default bus.
     *
     * This is the recommended entry point for application code (fluent API).
     */
    public static function notifier(): Notifier {
        if (self::$notifier) {
            return self::$notifier;
        }
        return self::$notifier = new Notifier(self::bus());
    }
}

