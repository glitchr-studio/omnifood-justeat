<?php

namespace Omnifood\JustEat;

use Omnifood\Channel;
use Omnifood\Exception\InvalidConfigException;
use Omnifood\Exception\InvalidMenuException;
use Omnifood\Exception\InvalidSignatureException;
use Omnifood\Exception\NotSupportedException;
use Omnifood\MenuInterface;
use Omnifood\Model\Allergen;
use Omnifood\Model\Capabilities;
use Omnifood\Model\Courier;
use Omnifood\Model\Customer;
use Omnifood\Model\DenyReason;
use Omnifood\Model\Hours;
use Omnifood\Model\Line;
use Omnifood\Model\Menu\Item;
use Omnifood\Model\Menu\Menu;
use Omnifood\Model\Menu\Modifier as MenuModifier;
use Omnifood\Model\Menu\ModifierGroup;
use Omnifood\Model\Modifier;
use Omnifood\Model\Money;
use Omnifood\Model\Notification;
use Omnifood\Model\NotificationSubject;
use Omnifood\Model\Order;
use Omnifood\Model\OrderStatus;
use Omnifood\Model\OrderType;
use Omnifood\Model\StoreStatus;
use Omnifood\NotifiableInterface;
use Omnifood\OrdersInterface;
use Omnifood\StoreInterface;
use Omnifood\Validator;

/**
 * A restaurant on Just Eat Takeaway.com through JET Connect, the public
 * point-of-sale API (https://developers.just-eat.com/documentation/jet-connect,
 * reference https://uk.api.just-eat.io/docs/jetconnect/openapi.yaml).
 *
 * How JET Connect works, and what it leaves out:
 *
 * - An order is accepted on JET's Orderpad (the tablet), then **pushed** to
 *   the restaurant's endpoint (Receive Order): notify() reads it. The
 *   endpoint answers 200 {"OrderId"} when the order is in, or 202 to take
 *   it in later - then accept() (sent-to-pos-success) or deny()
 *   (sent-to-pos-failed) within 5 minutes. A failed injection is not a
 *   refusal: the order goes to the tablet, to be keyed in by hand.
 * - JET Connect publishes no call to read an order, list them, mark one
 *   ready or cancel one: order(), orders(), ready() and cancel() throw
 *   NotSupportedException. Cancellations, the driver's progress and the
 *   restaurant set offline by JET arrive as webhooks (notify()).
 * - The menu is pushed whole (POST /menus) and items are marked out of
 *   stock (POST /item-availability); the restaurant is set offline and
 *   online, and its service times replaced. Its current state is not
 *   readable: status() throws NotSupportedException.
 */
final class JustEatPlatform implements OrdersInterface, MenuInterface, StoreInterface, NotifiableInterface
{
    /** JET lists each cereal and each nut; Omnifood's GLUTEN and NUTS are the regulation's families. */
    public const CEREALS = ['CEREAL_WHEAT', 'CEREAL_RYE', 'CEREAL_BARLEY', 'CEREAL_OATS', 'CEREAL_SPELT', 'CEREAL_KAMUT'];
    public const NUTS = ['NUTS_ALMONDS', 'NUTS_HAZELNUTS', 'NUTS_WALNUTS', 'NUTS_CASHEWS', 'NUTS_PECAN', 'NUTS_BRAZIL', 'NUTS_PISTACHIO', 'NUTS_MACADAMIA'];

    private const ALLERGENS = [
        'crustaceans' => 'CRUSTACEANS', 'eggs' => 'EGGS', 'fish' => 'FISH', 'peanuts' => 'PEANUTS', 'soybeans' => 'SOYBEANS',
        'milk' => 'MILK', 'celery' => 'CELERY', 'mustard' => 'MUSTARD', 'sesame' => 'SESAME_SEEDS',
        'sulphites' => 'SULPHUR_DIOXIDE_SULPHITES', 'lupin' => 'LUPIN', 'molluscs' => 'MOLLUSCS',
    ];

    private const DAYS = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];

    /** Adjustments (payment.adjustments[].name) that are fees, and the one that is a discount. */
    private const FEES = ['deliveryFee', 'serviceCharge', 'bagfee', 'smallOrderFee'];

    public function __construct(
        private readonly Api $api,
        private readonly ?string $restaurant = null,
        private readonly ?string $webhookKey = null,
        private readonly ?string $webhookSecret = null,
        private readonly string $currency = 'GBP',
        private readonly \DateTimeZone $timezone = new \DateTimeZone('Europe/London'),
        private readonly string $menuType = 'DELIVERY',
        private readonly float $standardVatRate = 20.0,
        private readonly Validator $validator = new Validator(),
    ) {
    }

    public function getName(): string
    {
        return 'justeat';
    }

    public function getChannel(): Channel
    {
        return Channel::JUSTEAT;
    }

    /**
     * Options are items of their own in JET's schema, with their modifiers:
     * two levels are taken here. Allergens are shown in DE, NL, AT, BE, BG,
     * DK, LU, PL and SK only; VAT is a category, not a rate.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            orderTypes: [OrderType::DELIVERY, OrderType::PICKUP],
            acceptanceDelay: null,
            readyAt: false,
            modifierDepth: 2,
            allergens: true,
            photos: true,
            vatRates: false,
            pauseUntil: true,
        );
    }

    public function order(string $ref): Order
    {
        throw NotSupportedException::operation($this->getName(), 'read an order (JET Connect pushes it to the Receive Order webhook: notify())');
    }

    public function orders(?\DateTimeImmutable $since = null): array
    {
        throw NotSupportedException::operation($this->getName(), 'list orders (JET Connect pushes each to the Receive Order webhook: notify())');
    }

    /**
     * The order taken into the till (sent-to-pos-success), after the
     * Receive Order webhook was answered 202. The acceptance itself is the
     * Orderpad's: $readyAt is not sent.
     */
    public function accept(string $ref, ?\DateTimeImmutable $readyAt = null): void
    {
        $this->api->request('POST', '/order/'.rawurlencode($ref).'/sent-to-pos-success', new \ArrayObject());
    }

    /**
     * The order could not be taken into the till (sent-to-pos-failed), after
     * a 202: JET sends it to the tablet's backup flow, where it is keyed in
     * by hand or refused there.
     */
    public function deny(string $ref, DenyReason $reason, ?string $note = null): void
    {
        $this->api->request('POST', '/order/'.rawurlencode($ref).'/sent-to-pos-failed', [
            'happenedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'errorCode' => match ($reason) {
                DenyReason::ITEM_UNAVAILABLE, DenyReason::PRICING => 'MENU_ERROR',
                DenyReason::CLOSED => 'STORE_CLOSED',
                default => 'UNKNOWN',
            },
            'errorMessage' => $note ?? $reason->value,
        ]);
    }

    public function ready(string $ref): void
    {
        throw NotSupportedException::operation($this->getName(), 'mark an order ready (JET Connect publishes no such call for the restaurant)');
    }

    public function cancel(string $ref, DenyReason $reason, ?string $note = null): void
    {
        throw NotSupportedException::operation($this->getName(), 'cancel an order (it is done on the Orderpad; JET Connect sends the Cancel Order Notification)');
    }

    public function pushMenu(Menu $menu): void
    {
        if ($violations = $this->validator->validate($menu, $this->capabilities())) {
            throw new InvalidMenuException($this->getName(), $violations);
        }
        $this->api->request('POST', '/menus', $this->menuPayload($menu));
    }

    /**
     * The JSON POST /menus takes for $menu, for this restaurant.
     *
     * @return array<string, mixed>
     */
    public function menuPayload(Menu $menu): array
    {
        $payload = [
            'name' => $menu->name,
            'reference' => $menu->ref ?? $menu->name,
            'type' => $this->menuType,
            'categories' => array_map(fn ($c) => array_filter([
                'name' => $c->name,
                'description' => $c->description ?? '',
                'type' => 'root',
                'items' => array_map($this->item(...), $c->items),
            ], static fn ($v) => null !== $v), $menu->categories),
        ];
        if (null !== $menu->description) {
            $payload['description'] = $menu->description;
        }
        if (null !== $menu->hours) {
            $payload['availability'] = [];
            foreach (self::DAYS as $n => $day) {
                $payload['availability'][$day] = array_map(static fn (array $r) => $r[0].' - '.$r[1], $menu->hours->day($n));
            }
        }

        return ['restaurants' => [$this->restaurant()], 'menus' => [$payload]];
    }

    public function setAvailability(string $itemRef, bool $available, ?\DateTimeImmutable $until = null): void
    {
        $this->api->request('POST', '/item-availability', array_filter([
            'event' => $available ? 'AVAILABLE' : 'UNAVAILABLE',
            'itemReferences' => [$itemRef],
            'restaurant' => $this->restaurant(),
            'happenedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'nextAvailableAt' => !$available && $until ? $until->format(\DateTimeInterface::ATOM) : null,
        ], static fn ($v) => null !== $v));
    }

    public function status(): StoreStatus
    {
        throw NotSupportedException::operation($this->getName(), 'read the restaurant\'s status (JET Connect only pushes it: the Restaurant Temporarily Offline webhook, notify())');
    }

    /**
     * Offline: no new order until $until, in the restaurant's local time
     * (null: until resume()). In AT, BE, BG, CH, DE, LU, NL, PL and SK JET
     * brings it back online at 07:00 Amsterdam time the next day whatever
     * is asked. $reason is not sent: JET takes none.
     */
    public function pause(?\DateTimeImmutable $until = null, ?string $reason = null): void
    {
        $this->api->request('PUT', '/restaurants/'.rawurlencode($this->restaurant()).'/offline', null === $until ? new \ArrayObject() : ['onlineAt' => $until->setTimezone($this->timezone)->format('Y-m-d\TH:i:s')]);
    }

    public function resume(): void
    {
        $this->api->request('PUT', '/restaurants/'.rawurlencode($this->restaurant()).'/online');
    }

    /**
     * The same week for delivery and collection. JET applies the overlap of
     * these, the menu's availability and its delivery pool's hours; it has
     * no call for dates apart: Hours::$exceptions are not sent (a closed
     * day is a pause()).
     */
    public function setHours(Hours $hours): void
    {
        $week = [];
        foreach (self::DAYS as $n => $day) {
            $week[$day] = array_map(static fn (array $r) => ['openingTime' => $r[0], 'closingTime' => $r[1]], $hours->day($n));
        }
        $this->api->request('PUT', '/restaurants/'.rawurlencode($this->restaurant()).'/servicetimes', [
            'timezone' => $this->timezone->getName(),
            'serviceTimes' => [
                ['serviceType' => 'Delivery', 'openingTimes' => $week],
                ['serviceType' => 'Collection', 'openingTimes' => $week],
            ],
        ]);
    }

    /**
     * Any of JET Connect's webhooks to the restaurant - Receive Order (the
     * whole order), Cancel Order, Driver Status, Restaurant Temporarily
     * Offline, Failed Order for Backup Flow - told apart by their body.
     *
     * Checked by what was given to JET: the key, which comes back as the
     * Authorization header; the HMAC secret, as X-JET-Connect-Hash
     * ("HMAC-SHA256 t=...,signature=<base64 HMAC-SHA256 of the body>"),
     * which JET documents on the order webhooks. One of the two must be
     * configured. JET gives no event id: Notification::$id is composed from
     * the order and the event, the same on a retry.
     *
     * The endpoint answers each with 200 and the same body (an order:
     * {"OrderId": ...}); a 500 makes JET retry, up to 5 times.
     */
    public function notify(string $body, array $headers): Notification
    {
        $headers = array_change_key_case(array_map(static fn ($v) => \is_array($v) ? (string) reset($v) : (string) $v, $headers));
        if (!$this->webhookKey && !$this->webhookSecret) {
            throw new InvalidConfigException('The "justeat" platform needs: webhook_key or webhook_secret, to check its webhooks.');
        }
        if ($this->webhookKey && !hash_equals($this->webhookKey, $headers['authorization'] ?? '')) {
            throw new InvalidSignatureException($this->getName(), 'The Authorization header is not the key given to JET.');
        }
        $data = json_decode($body, true);
        if (!\is_array($data)) {
            throw new InvalidSignatureException($this->getName(), 'The body is not JSON.');
        }
        $isOrder = isset($data['third_party_order_reference'], $data['items']);
        if ($this->webhookSecret && (isset($headers['x-jet-connect-hash']) || $isOrder)) {
            $this->checkHash($body, $headers['x-jet-connect-hash'] ?? '');
        }

        if ($isOrder) {
            $order = $this->toOrder($data);

            return new Notification(Channel::JUSTEAT, 'order.received', NotificationSubject::ORDER, $order->reference, $order->reference.':received'.(isset($data['transmission_id']) ? ':'.$data['transmission_id'] : ''), $order, null, OrderStatus::NEW, $order->placedAt, $order->store, $data);
        }
        if (isset($data['orderID'], $data['reason'])) {
            return new Notification(Channel::JUSTEAT, 'order.cancelled', NotificationSubject::ORDER, (string) $data['orderID'], $data['orderID'].':cancelled', status: OrderStatus::CANCELLED, occurredAt: self::date($data['happenedAt'] ?? null), raw: $data);
        }
        if (isset($data['orderID'], $data['driverStatus'])) {
            $code = (string) ($data['driverStatus']['code'] ?? '');

            return new Notification(Channel::JUSTEAT, 'driver.'.$code, NotificationSubject::COURIER, (string) $data['orderID'], $data['orderID'].':driver:'.$code, status: match ($code) {
                'onItsWay' => OrderStatus::PICKED_UP,
                'delivered' => OrderStatus::DELIVERED,
                default => null,
            }, occurredAt: self::date($data['happenedAt'] ?? null), raw: $data);
        }
        if (isset($data['restaurantId'], $data['lastChangedTimeStampUtc'])) {
            return new Notification(Channel::JUSTEAT, 'restaurant.temp_offline', NotificationSubject::STORE, null, $data['restaurantId'].':offline:'.$data['lastChangedTimeStampUtc'], occurredAt: self::date((string) $data['lastChangedTimeStampUtc']), store: (string) $data['restaurantId'], raw: $data);
        }
        if (isset($data['validationError'], $data['order'])) {
            $ref = (string) ($data['order']['orderId'] ?? '');

            return new Notification(Channel::JUSTEAT, 'order.backup_flow', NotificationSubject::ORDER, '' !== $ref ? $ref : null, $ref.':backup', raw: $data);
        }

        return new Notification(Channel::JUSTEAT, 'unknown', NotificationSubject::OTHER, raw: $data);
    }

    /**
     * A Receive Order body as an Order. Amounts come in minor units without
     * a currency: the configured one.
     *
     * @param array<string, mixed> $o
     */
    public function toOrder(array $o): Order
    {
        $payment = (array) ($o['payment'] ?? []);
        $fees = 0;
        $discount = 0;
        foreach ((array) ($payment['adjustments'] ?? []) as $adjustment) {
            $amount = (int) ($adjustment['price']['inc_tax'] ?? 0);
            if (\in_array($adjustment['name'] ?? null, self::FEES, true)) {
                $fees += $amount;
            } elseif ('discount' === ($adjustment['name'] ?? null)) {
                $discount += abs($amount);
            }
        }
        $type = (string) ($o['type'] ?? '');
        $person = (array) ($o['delivery'] ?? $o['collector'] ?? []);
        $address = array_filter([$person['company_name'] ?? null, trim(($person['street_number'] ?? '').' '.($person['street'] ?? '')), $person['line_one'] ?? null, $person['line_two'] ?? null, trim(($person['postcode'] ?? '').' '.($person['city'] ?? ''))], static fn ($v) => null !== $v && '' !== trim((string) $v));
        $driver = (array) ($o['driver'] ?? []);
        $notes = array_filter([$o['kitchen_notes'] ?? null, $o['delivery_notes'] ?? null, $o['collection_notes'] ?? null], static fn ($v) => null !== $v && '' !== $v);

        return new Order(
            Channel::JUSTEAT,
            (string) $o['id'],
            isset($o['third_party_order_reference']) ? (string) $o['third_party_order_reference'] : null,
            'collection-by-customer' === $type ? OrderType::PICKUP : OrderType::DELIVERY,
            OrderStatus::NEW,
            array_map($this->line(...), array_values((array) ($o['items'] ?? []))),
            $person ? new Customer(
                trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? '')) ?: null,
                ($person['phone_number'] ?? '') ?: null,
                ($person['phone_masking_code'] ?? '') ?: null,
                ($person['email'] ?? '') ?: null,
                'delivery-by-merchant' === $type && $address ? implode(', ', $address) : null,
            ) : null,
            $this->money($payment['final']['inc_tax'] ?? $o['total'] ?? null),
            $this->money($payment['items_in_cart']['inc_tax'] ?? $o['total'] ?? null),
            $discount ? $this->money($discount) : null,
            $fees ? $this->money($fees) : null,
            $this->money($payment['final']['tax'] ?? null),
            self::timestamp($o['created_at'] ?? null),
            self::timestamp($o['collect_at'] ?? null),
            self::timestamp($o['deliver_at'] ?? null),
            $driver ? new Courier(trim(($driver['first_name'] ?? '').' '.($driver['last_name'] ?? '')) ?: null, ($driver['phone_number'] ?? '') ?: null) : null,
            $notes ? implode("\n", $notes) : null,
            isset($o['posLocationId']) ? (string) $o['posLocationId'] : null,
            null,
            $o,
        );
    }

    /** @param array<string, mixed> $i */
    private function line(array $i): Line
    {
        $quantity = (int) ($i['quantity'] ?? 1);

        return new Line(
            (string) ($i['name'] ?? ''),
            $quantity,
            $this->money($i['unitPrice'] ?? $i['price'] ?? null),
            $this->money($i['totalPrice'] ?? (isset($i['price']) ? (int) $i['price'] * $quantity : null)),
            ($i['plu'] ?? '') ?: null,
            ($i['reference'] ?? '') ?: null,
            array_map($this->modifier(...), array_values(array_merge((array) ($i['children'] ?? []), (array) ($i['items'] ?? [])))),
            ($i['notes'] ?? '') ?: null,
        );
    }

    /** @param array<string, mixed> $m */
    private function modifier(array $m): Modifier
    {
        return new Modifier(
            (string) ($m['name'] ?? ''),
            (int) ($m['quantity'] ?? 1),
            $this->money($m['unitPrice'] ?? $m['price'] ?? null),
            ($m['plu'] ?? $m['reference'] ?? '') ?: null,
            null,
            array_map($this->modifier(...), array_values(array_merge((array) ($m['children'] ?? []), (array) ($m['items'] ?? [])))),
        );
    }

    /** @return array<string, mixed> */
    private function item(Item|MenuModifier $item): array
    {
        $allergens = [];
        foreach ($item->allergens as $allergen) {
            $allergens = array_merge($allergens, match ($allergen) {
                // The family, unless the item names its members among its labels ("CEREAL_WHEAT").
                Allergen::GLUTEN => array_values(array_intersect(self::CEREALS, $this->labels($item))) ?: self::CEREALS,
                Allergen::NUTS => array_values(array_intersect(self::NUTS, $this->labels($item))) ?: self::NUTS,
                default => [self::ALLERGENS[$allergen->value]],
            });
        }
        $labels = array_map('strtolower', $this->labels($item));
        $diet = \in_array('vegan', $labels, true) ? ['VEGAN'] : (\in_array('vegetarian', $labels, true) ? ['VEGETARIAN'] : []);

        return array_filter([
            'name' => $item->name,
            'description' => $item instanceof Item ? $item->description : null,
            'plu' => $item->ref,
            'price' => $item->price?->amount ?? 0,
            'out_of_stock' => !$item->available,
            'tax_category' => null === $item->vatRate ? null : match (true) {
                $item->vatRate <= 0.0 => 'NO_TAX',
                $item->vatRate >= $this->standardVatRate => 'STANDARD_RATE',
                default => 'REDUCED_RATE',
            },
            'allergens' => $allergens ?: null,
            'dietary_restrictions' => $diet ?: null,
            'gallery' => $item instanceof Item && $item->photo ? [['url' => $item->photo]] : null,
            'modifiers' => $item->modifierGroups ? array_map($this->group(...), $item->modifierGroups) : null,
        ], static fn ($v) => null !== $v);
    }

    /** @return array<string, mixed> */
    private function group(ModifierGroup $group): array
    {
        $pick = null !== $group->max && $group->min === $group->max
            ? ['pick_same_option' => false, 'exactly' => $group->min]
            : ['pick_same_option' => false, 'range' => ['from' => $group->min, 'to' => $group->max ?? \count($group->modifiers)]];

        return [
            'name' => $group->name,
            'description' => '',
            'pick' => $pick,
            'options' => array_map($this->item(...), $group->modifiers),
        ];
    }

    /** @return list<string> */
    private function labels(Item|MenuModifier $item): array
    {
        return $item instanceof Item ? $item->labels : [];
    }

    private function checkHash(string $body, string $header): void
    {
        if (!preg_match('/signature=([A-Za-z0-9+\/=]+)/', $header, $m)) {
            throw new InvalidSignatureException($this->getName(), 'No X-JET-Connect-Hash signature.');
        }
        if (!hash_equals(base64_encode(hash_hmac('sha256', $body, (string) $this->webhookSecret, true)), $m[1])) {
            throw new InvalidSignatureException($this->getName(), 'The X-JET-Connect-Hash signature does not match the body.');
        }
    }

    private function restaurant(): string
    {
        return $this->restaurant ?: throw new InvalidConfigException('The "justeat" platform needs: restaurant.');
    }

    private function money(mixed $minor): ?Money
    {
        return null === $minor || '' === $minor ? null : Money::of((int) $minor, $this->currency);
    }

    private static function timestamp(mixed $unix): ?\DateTimeImmutable
    {
        return is_numeric($unix) ? (new \DateTimeImmutable('@'.(int) $unix)) : null;
    }

    private static function date(?string $date): ?\DateTimeImmutable
    {
        try {
            return $date ? new \DateTimeImmutable($date) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
