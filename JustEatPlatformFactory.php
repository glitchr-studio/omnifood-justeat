<?php

namespace Omnifood\JustEat;

use Omnifood\Config;
use Omnifood\PlatformFactory;
use Omnifood\PlatformInterface;

/**
 * Just Eat Takeaway.com (Just Eat, Takeaway, Skip The Dishes, Menulog),
 * through JET Connect - the public point-of-sale API:
 *
 *   options:
 *     api_key: '%env(default::JUSTEAT_API_KEY)%'          # JET Connect's key for the brand (X-Flyt-Api-Key): every call
 *     restaurant: '%env(default::JUSTEAT_RESTAURANT)%'    # the restaurant's reference as configured by JET Connect (posLocationId)
 *     webhook_key: '%env(default::JUSTEAT_WEBHOOK_KEY)%'  # the key given to JET for its webhooks (their Authorization header)
 *     webhook_secret: '%env(default::JUSTEAT_WEBHOOK_SECRET)%' # the HMAC secret given to JET (X-JET-Connect-Hash)
 *     currency: GBP                                       # the orders' amounts come without one
 *     timezone: Europe/London                             # the restaurant's: service times, the end of a pause
 *     menu_type: DELIVERY                                 # or COLLECTION
 *     standard_vat_rate: 20.0                             # an item at this rate is STANDARD_RATE, under it REDUCED_RATE, at 0 NO_TAX
 *     base_uri: https://api.flytplatform.com
 *
 * Nothing is required to build the platform: capabilities() needs no key;
 * a call that needs one and finds none throws InvalidConfigException.
 */
final class JustEatPlatformFactory extends PlatformFactory
{
    protected function populate(Config $config): void
    {
        $config->defaults([
            'omnifood.factory_name' => 'justeat',
            'omnifood.required_options' => [],
            'api_key' => null,
            'restaurant' => null,
            'webhook_key' => null,
            'webhook_secret' => null,
            'currency' => 'GBP',
            'timezone' => 'Europe/London',
            'menu_type' => 'DELIVERY',
            'standard_vat_rate' => 20.0,
            'base_uri' => Api::BASE_URI,
        ]);
    }

    protected function build(Config $config): PlatformInterface
    {
        return new JustEatPlatform(
            new Api($config['api_key'] ? (string) $config['api_key'] : null, (string) $config['base_uri'], $this->http),
            $config['restaurant'] ? (string) $config['restaurant'] : null,
            $config['webhook_key'] ? (string) $config['webhook_key'] : null,
            $config['webhook_secret'] ? (string) $config['webhook_secret'] : null,
            (string) $config['currency'],
            new \DateTimeZone((string) $config['timezone']),
            strtoupper((string) $config['menu_type']),
            (float) $config['standard_vat_rate'],
        );
    }
}
