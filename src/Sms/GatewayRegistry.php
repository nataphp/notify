<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push, SMS) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Sms;

use Nata\Core\Configure;
use Notify\Core\NonRetryableChannelException;
use Notify\Sms\Gateway\GenericHttpGateway;
use Notify\Sms\Gateway\InfobipGateway;
use Notify\Sms\Gateway\LogGateway;
use Notify\Sms\Gateway\SmsOnlineGateway;
use Notify\Sms\Gateway\TwilioGateway;
use Notify\Sms\Gateway\VonageGateway;
use Notify\Sms\Http\HttpTransportInterface;

/**
 * Creates the SMS gateway adapters by name.
 *
 * `Notify.sms.gateway` names the active one; its settings are in
 * `Notify.sms.gateways.{name}`. An entry there may also carry a `className`
 * (any SmsGatewayInterface, built with `new $className($config, $http)`), so an
 * app adds a provider without touching the plugin; register() does the same
 * from code. Built in: log, twilio, vonage, infobip, smsonline and http (the
 * configurable GenericHttpGateway).
 *
 * @example
 * $gateway = GatewayRegistry::fromConfig(); // the active one, from Configure
 * $gateway = GatewayRegistry::create('twilio', ['accountSid' => '…', 'authToken' => '…']);
 */
final class GatewayRegistry {

    /**
     * Built-in adapters.
     *
     * @var array<string,class-string<\Notify\Sms\SmsGatewayInterface>>
     */
    const BUILT_IN = [
        'log' => LogGateway::class,
        'twilio' => TwilioGateway::class,
        'vonage' => VonageGateway::class,
        'infobip' => InfobipGateway::class,
        'smsonline' => SmsOnlineGateway::class,
        'http' => GenericHttpGateway::class,
    ];

    /**
     * Adapters registered at runtime.
     *
     * @var array<string,class-string<\Notify\Sms\SmsGatewayInterface>>
     */
    private static array $registered = [];

    /**
     * Register (or replace) an adapter under a name.
     *
     * @param string $name Gateway name.
     * @param class-string<\Notify\Sms\SmsGatewayInterface> $className Adapter class.
     * @return void
     */
    public static function register(string $name, string $className): void {
        self::$registered[strtolower($name)] = $className;
    }

    /**
     * Names of every known adapter.
     *
     * @return array<int,string> Names.
     */
    public static function names(): array {
        return array_keys(self::$registered + self::BUILT_IN);
    }

    /**
     * Whether an SMS gateway is chosen in the configuration (the channel is on).
     *
     * @return bool True when `Notify.sms.gateway` is set.
     */
    public static function isConfigured(): bool {
        return trim((string)Configure::read('Notify.sms.gateway')) !== '';
    }

    /**
     * The active gateway, from `Notify.sms.gateway` and its settings.
     *
     * @param \Notify\Sms\Http\HttpTransportInterface|null $http HTTP transport (tests).
     * @return \Notify\Sms\SmsGatewayInterface Gateway.
     * @throws \Notify\Core\NonRetryableChannelException When none is chosen or it is unknown.
     */
    public static function fromConfig(?HttpTransportInterface $http = null): SmsGatewayInterface {
        $name = strtolower(trim((string)Configure::read('Notify.sms.gateway')));
        if ($name === '') {
            throw new NonRetryableChannelException('No SMS gateway configured (Notify.sms.gateway).', 'sms', 'misconfigured');
        }

        return self::create($name, (array)Configure::read('Notify.sms.gateways.' . $name), $http);
    }

    /**
     * Create an adapter.
     *
     * @param string $name Gateway name.
     * @param array<string,mixed> $config Its settings (a `className` picks a custom adapter).
     * @param \Notify\Sms\Http\HttpTransportInterface|null $http HTTP transport (tests).
     * @return \Notify\Sms\SmsGatewayInterface Gateway.
     * @throws \Notify\Core\NonRetryableChannelException When the name or the class is unknown.
     */
    public static function create(string $name, array $config = [], ?HttpTransportInterface $http = null): SmsGatewayInterface {
        $name = strtolower($name);
        $className = (string)($config['className'] ?? (self::$registered[$name] ?? (self::BUILT_IN[$name] ?? '')));
        if ($className === '' || !class_exists($className) || !is_subclass_of($className, SmsGatewayInterface::class)) {
            throw new NonRetryableChannelException(sprintf('Unknown SMS gateway "%s".', $name), 'sms', 'misconfigured');
        }
        unset($config['className']);
        if ($className === GenericHttpGateway::class || is_subclass_of($className, GenericHttpGateway::class)) {
            $config += ['name' => $name];
        }

        return new $className($config, $http);
    }

}
