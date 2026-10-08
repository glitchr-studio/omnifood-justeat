# omnifood/justeat

Just Eat Takeaway.com (Just Eat, Takeaway, Skip The Dishes, Menulog) for
[glitchr/omnifood](https://github.com/glitchr-studio/omnifood): the orders JET Connect pushes to the
restaurant read and confirmed in the till, the cancellations and the drivers' progress, the menu
pushed and its items out of stock, the restaurant set offline and online and its service times -
through **JET Connect**, Just Eat's public point-of-sale API (formerly Flyt).

> **Not verified against the live API (non vérifié en réel).** JET gives its API key only to
> partners under a commercial agreement: this package is written from JET's public documentation
> (https://developers.just-eat.com/documentation/jet-connect and
> https://uk.api.just-eat.io/docs/jetconnect/openapi.yaml, read on 2026-10-04) and tested against
> its examples.

```php
use Omnifood\JustEat\JustEatPlatformFactory;
use Symfony\Component\HttpClient\HttpClient;

$justeat = (new JustEatPlatformFactory(HttpClient::create()))->create([
    'api_key' => getenv('JUSTEAT_API_KEY') ?: null,
    'restaurant' => getenv('JUSTEAT_RESTAURANT') ?: null,
    'webhook_key' => getenv('JUSTEAT_WEBHOOK_KEY') ?: null,
    'webhook_secret' => getenv('JUSTEAT_WEBHOOK_SECRET') ?: null,
    'currency' => 'EUR',
    'timezone' => 'Europe/Dublin',
]);
```

Plain PHP, no framework needed: the factory takes any `HttpClientInterface` - the application's, a
`MockHttpClient` in a test - and makes its own when given none. In a Symfony application, the same
options under `omnifood.platforms` ([the bundle](https://github.com/glitchr-studio/omnifood/blob/1.x/docs/symfony.md)):

```yaml
omnifood:
    platforms:
        justeat:
            factory: justeat
            options:
                api_key: '%env(default::JUSTEAT_API_KEY)%'           # X-Flyt-Api-Key, per brand
                restaurant: '%env(default::JUSTEAT_RESTAURANT)%'     # the restaurant's reference at JET Connect (posLocationId)
                webhook_key: '%env(default::JUSTEAT_WEBHOOK_KEY)%'   # the key given to JET: its webhooks' Authorization
                webhook_secret: '%env(default::JUSTEAT_WEBHOOK_SECRET)%' # the HMAC secret given to JET
                currency: GBP            # the orders' amounts come without one
                timezone: Europe/London  # service times, the end of a pause
                menu_type: DELIVERY      # or COLLECTION
                standard_vat_rate: 20.0  # at this rate STANDARD_RATE, under it REDUCED_RATE, at 0 NO_TAX
```

## How JET Connect works

An order is accepted on JET's **Orderpad** (the tablet), then pushed to the restaurant's endpoint
(Receive Order). `notify()` reads it whole. The endpoint answers 200 `{"OrderId": "..."}` when the
order is in the till; or 202, and then `accept()` (sent-to-pos-success) or `deny()`
(sent-to-pos-failed) within 5 minutes. A failed injection is not a refusal: JET sends the order to
the tablet, to be keyed in by hand or refused there.

| Omnifood | JET Connect |
|---|---|
| `notify($body, $headers)` | Receive Order, Cancel Order, Driver Status, Restaurant Temporarily Offline, Failed Order (backup flow) |
| `accept($id)` | `POST /order/{id}/sent-to-pos-success` |
| `deny($id, $reason, $note)` | `POST /order/{id}/sent-to-pos-failed` (`errorCode`, `errorMessage`) |
| `pushMenu($menu)` | `POST /menus` |
| `setAvailability($ref, ...)` | `POST /item-availability` (`AVAILABLE` / `UNAVAILABLE`, `nextAvailableAt`) |
| `pause($until)`, `resume()` | `PUT /restaurants/{ref}/offline` (`onlineAt`), `PUT .../online` |
| `setHours($hours)` | `PUT /restaurants/{ref}/servicetimes` (delivery and collection) |

- The webhooks are checked by what was given to JET: the key (their `Authorization` header) and/or
  the HMAC secret (`X-JET-Connect-Hash: HMAC-SHA256 t=...,signature=<base64>`, the HMAC-SHA256 of
  the body - JET's own example is verified in the tests). One of the two is required.
- JET gives no event id: `Notification::$id` is composed from the order and the event.
- Allergens use JET's list (shown in DE, NL, AT, BE, BG, DK, LU, PL, SK only): `GLUTEN` and `NUTS`
  declare every cereal and nut unless the item's labels name them (`CEREAL_WHEAT`, `NUTS_ALMONDS`...).
- In AT, BE, BG, CH, DE, LU, NL, PL and SK, JET brings a restaurant back online at 07:00 (Amsterdam)
  the next day whatever `pause()` asked.

## Left in NotSupportedException

JET Connect publishes none of these for the restaurant (OpenAPI above; the older Order API with
`TimeAcceptedFor` is no longer public):

- `order()`, `orders()`: no call reads or lists orders; they are pushed.
- `ready()`: no call marks an order ready.
- `cancel()`: cancelled on the Orderpad; JET sends the Cancel Order Notification.
- `status()`: the restaurant's state is not readable; the Restaurant Temporarily Offline webhook says it.

Not sent: `Hours::$exceptions` (no call for dates apart: a closed day is a `pause()`).

## What it takes

- A **commercial agreement** with Just Eat Takeaway.com (integrations@justeattakeaway.com).
- Self-serve onboarding: a one-time link to the brand's **API key** (7 days), the configuration
  `PUT /partners/onboarding/{session}/configuration` (its fields come from JET's integration contact),
  the menu and service times pushed, then `go-live` per location.
- The **restaurant references** as configured at JET Connect; the **endpoint URLs** given to JET
  (receive order, cancel, driver status, backup flow, temporarily offline), with an **API key**
  and/or an **HMAC secret** for them; static IPs on request.

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
