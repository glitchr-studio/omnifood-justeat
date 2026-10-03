# Just Eat with Omnifood

## Installation

```sh
composer require glitchr/omnifood omnifood/justeat
```

## Configuration

```yaml
omnifood:
    platforms:
        justeat:
            factory: justeat
            options:
                api_key: '%env(default::JUSTEAT_API_KEY)%'
                restaurant: '%env(default::JUSTEAT_RESTAURANT)%'
                webhook_key: '%env(default::JUSTEAT_WEBHOOK_KEY)%'
                webhook_secret: '%env(default::JUSTEAT_WEBHOOK_SECRET)%'
                currency: EUR
                timezone: Europe/Dublin
```

Give JET the endpoints (receive order, cancel, driver status, backup flow, temporarily offline), the
key it will send back as `Authorization`, and an HMAC secret.

## Receiving an order

```php
try {
    $notification = $justeat->notify($request->getContent(), $request->headers->all());
} catch (InvalidSignatureException) {
    return new JsonResponse(['errorMessage' => 'Unauthorized'], 401);
}
if ('order.received' === $notification->event) {
    $till->take($notification->order);                         // into the till, now
    return new JsonResponse(['OrderId' => $notification->order->reference], 200);
}

// The other webhooks are answered with the same payload.
return new JsonResponse(json_decode($request->getContent(), true), 200);
```

To take it in later, answer 202, then within 5 minutes:

```php
$justeat->accept($reference);                                  // in the till
$justeat->deny($reference, DenyReason::ITEM_UNAVAILABLE, 'No more gyoza');   // to the tablet's backup flow
```

## The menu and the restaurant

```php
$justeat->pushMenu($menu);
$justeat->setAvailability('gyoza', false, new \DateTimeImmutable('tomorrow 11:00'));
$justeat->pause(new \DateTimeImmutable('+30 minutes'));       // offline until then (local time)
$justeat->resume();
$justeat->setHours($hours);                                    // delivery and collection service times
```

Not verified against the live API.
