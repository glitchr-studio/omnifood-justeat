<?php

namespace Omnifood\JustEat\Tests;

use Omnifood\Exception\InvalidConfigException;
use Omnifood\Exception\InvalidMenuException;
use Omnifood\Exception\InvalidSignatureException;
use Omnifood\Exception\NotSupportedException;
use Omnifood\Exception\ProviderException;
use Omnifood\Exception\UnauthorizedException;
use Omnifood\JustEat\JustEatPlatform;
use Omnifood\JustEat\JustEatPlatformFactory;
use Omnifood\Model\Allergen;
use Omnifood\Model\DenyReason;
use Omnifood\Model\Hours;
use Omnifood\Model\Menu\Category;
use Omnifood\Model\Menu\Item;
use Omnifood\Model\Menu\Menu;
use Omnifood\Model\Menu\Modifier;
use Omnifood\Model\Menu\ModifierGroup;
use Omnifood\Model\Money;
use Omnifood\Model\NotificationSubject;
use Omnifood\Model\OrderStatus;
use Omnifood\Model\OrderType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JustEatPlatformTest extends TestCase
{
    /** @var list<array{string, string, array<string, string>, mixed}> method, path, headers, JSON body */
    private array $calls = [];

    private function platform(array $options = [], ?MockResponse $answer = null): JustEatPlatform
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $headers = [];
            foreach ($options['headers'] as $header) {
                [$name, $value] = explode(': ', $header, 2);
                $headers[strtolower($name)] = $value;
            }
            $this->calls[] = [$method, (string) parse_url($url, \PHP_URL_PATH), $headers, isset($options['body']) && '' !== $options['body'] ? json_decode($options['body'], true) : null];

            return $answer ?? (str_starts_with((string) parse_url($url, \PHP_URL_PATH), '/order/') ? new MockResponse('', ['http_code' => 204]) : new MockResponse('{"success":true}', ['http_code' => 202]));
        });

        return (new JustEatPlatformFactory($http))->create($options + ['api_key' => 'flyt-key', 'restaurant' => 'AKZ12', 'webhook_key' => 'our-key', 'webhook_secret' => 'key', 'currency' => 'GBP', 'timezone' => 'Europe/London']);
    }

    /** The Receive Order example of JET Connect's reference, "Delivery by partner". */
    private static function orderBody(): string
    {
        return (string) json_encode([
            'id' => '38bbeb45-f520-4438-a44f-0fcdbb29e166', 'third_party_order_reference' => '22721763', 'type' => 'delivery-by-delivery-partner', 'posLocationId' => 'AKZ12',
            'location' => ['id' => 1296, 'timezone' => 'Europe/London'], 'menu_reference' => '',
            'items' => [['name' => 'Cheeseburger', 'description' => '', 'plu' => 'M2', 'price' => 1700, 'notes' => '', 'unitDepositAmount' => 0, 'substitution' => ['preference' => 'bestmatch'],
                'children' => [['name' => 'Extra Sauce', 'description' => '', 'plu' => 'R3', 'price' => 100, 'unitDepositAmount' => 40]]]],
            'created_at' => '1606780145', 'channel' => ['name' => 'Just Eat', 'id' => 32], 'collect_at' => '1606780980', 'collection_notes' => 'Driver will be wearing a blue shirt', 'kitchen_notes' => '',
            'payment_method' => 'CARD', 'tender_type' => 'Just Eat',
            'payment' => ['items_in_cart' => ['inc_tax' => 2160, 'tax' => 360], 'adjustments' => [['name' => 'deliveryFee', 'price' => ['inc_tax' => 240, 'tax' => 40]], ['name' => 'discount', 'price' => ['inc_tax' => -300, 'tax' => 0]]], 'final' => ['inc_tax' => 2100, 'tax' => 360], 'deposit' => 40],
            'driver' => ['first_name' => 'John', 'last_name' => 'Smith', 'phone_number' => '555-111-3344'],
            'delivery' => ['first_name' => '****', 'last_name' => '****', 'phone_number' => '55555 113 000', 'phone_masking_code' => '', 'line_one' => '****', 'city' => '*****', 'postcode' => '*****', 'email' => 'customer@email.hidden'],
            'extras' => ['justEatCustomerId' => '44346314687', 'justEatOrderReference' => 'snflsetqm0m8puqcpqm0yq', 'asap' => 'true'],
            'promotions' => [], 'total' => 2160,
        ]);
    }

    /** @return array<string, string> */
    private static function signed(string $body, string $secret = 'key'): array
    {
        return ['Authorization' => 'our-key', 'X-JET-Connect-Hash' => 'HMAC-SHA256 t=1673428038618,signature='.base64_encode(hash_hmac('sha256', $body, $secret, true))];
    }

    public function testTheSignatureIsJetsDocumentedExample(): void
    {
        // "Given the following secret of `key` and a body of `example`, the following hash will be generated".
        self::assertSame('FGwot7AqiDIthEv6TippJm35DaRpRac5NSLd/wSp9go=', base64_encode(hash_hmac('sha256', 'example', 'key', true)));
    }

    public function testAnOrderPushedToTheRestaurantIsReadWhole(): void
    {
        $body = self::orderBody();
        $notification = $this->platform()->notify($body, self::signed($body));

        self::assertSame('order.received', $notification->event);
        self::assertSame(NotificationSubject::ORDER, $notification->subject);
        self::assertSame(OrderStatus::NEW, $notification->status);
        self::assertSame('38bbeb45-f520-4438-a44f-0fcdbb29e166:received', $notification->id);
        $order = $notification->order;
        self::assertSame('38bbeb45-f520-4438-a44f-0fcdbb29e166', $order->reference);
        self::assertSame('22721763', $order->displayId);
        self::assertSame(OrderType::DELIVERY, $order->type);
        self::assertSame('AKZ12', $order->store);
        self::assertSame('Cheeseburger', $order->lines[0]->name);
        self::assertSame('M2', $order->lines[0]->posRef);
        self::assertTrue(Money::of(1700, 'GBP')->equals($order->lines[0]->unitPrice));
        self::assertSame('R3', $order->lines[0]->modifiers[0]->posRef);
        self::assertSame(100, $order->lines[0]->modifiers[0]->price->amount);
        self::assertSame(2100, $order->total->amount);
        self::assertSame(2160, $order->subtotal->amount);
        self::assertSame(240, $order->fees->amount);
        self::assertSame(300, $order->discount->amount);
        self::assertSame(360, $order->tax->amount);
        self::assertSame('GBP', $order->total->currency);
        self::assertEquals(new \DateTimeImmutable('@1606780145'), $order->placedAt);
        self::assertEquals(new \DateTimeImmutable('@1606780980'), $order->pickupAt);
        self::assertSame('John Smith', $order->courier->name);
        self::assertSame('Driver will be wearing a blue shirt', $order->note);
        self::assertNull($order->customer->address, 'JET delivers: the address is not the restaurant\'s business');
    }

    public function testAPickupAndADeliveryByTheRestaurant(): void
    {
        $platform = $this->platform();
        $pickup = $platform->toOrder(['id' => 'p', 'type' => 'collection-by-customer', 'items' => [], 'collector' => ['first_name' => 'John', 'last_name' => 'Doe', 'phone_number' => '020 7946 0504', 'phone_masking_code' => '1234567890'], 'total' => 1000]);
        self::assertSame(OrderType::PICKUP, $pickup->type);
        self::assertSame('John Doe', $pickup->customer->name);
        self::assertSame('1234567890', $pickup->customer->phoneCode);
        self::assertSame(1000, $pickup->total->amount);

        $own = $platform->toOrder(['id' => 'd', 'type' => 'delivery-by-merchant', 'items' => [], 'delivery' => ['first_name' => 'John', 'last_name' => 'Doe', 'line_one' => '1234 Spicy Street', 'city' => 'Winnipeg', 'postcode' => 'R3B 0P4'], 'deliver_at' => '1606780980', 'total' => 600]);
        self::assertSame(OrderType::DELIVERY, $own->type);
        self::assertSame('1234 Spicy Street, R3B 0P4 Winnipeg', $own->customer->address);
        self::assertEquals(new \DateTimeImmutable('@1606780980'), $own->deliverAt);
    }

    public function testAWebhookNotSignedOrNotOursIsRefused(): void
    {
        $body = self::orderBody();
        $platform = $this->platform();

        foreach ([
            'another key' => ['Authorization' => 'theirs'] + self::signed($body),
            'another secret' => self::signed($body, 'other'),
            'no signature on an order' => ['Authorization' => 'our-key'],
            'the body changed' => self::signed($body.' '),
        ] as $case => $headers) {
            try {
                $platform->notify($body, $headers);
                self::fail($case.': refused.');
            } catch (InvalidSignatureException $e) {
                self::assertSame('justeat', $e->platform, $case);
            }
        }

        $this->expectException(InvalidConfigException::class);
        $this->platform(['webhook_key' => null, 'webhook_secret' => null])->notify($body, []);
    }

    public function testTheOtherWebhooks(): void
    {
        $platform = $this->platform(['webhook_secret' => null]);
        $key = ['authorization' => 'our-key'];

        $cancelled = $platform->notify('{"orderID":"38bb","reason":{"code":"custCancelledMadeMistake"},"happenedAt":"2022-08-15T10:12:56.371917Z"}', $key);
        self::assertSame(['order.cancelled', OrderStatus::CANCELLED, '38bb', '38bb:cancelled'], [$cancelled->event, $cancelled->status, $cancelled->reference, $cancelled->id]);
        self::assertEquals(new \DateTimeImmutable('2022-08-15T10:12:56.371917Z'), $cancelled->occurredAt);

        $driver = $platform->notify('{"orderID":"38bb","driverStatus":{"code":"onItsWay"},"happenedAt":"2022-08-15T10:12:56Z"}', $key);
        self::assertSame(['driver.onItsWay', NotificationSubject::COURIER, OrderStatus::PICKED_UP], [$driver->event, $driver->subject, $driver->status]);
        self::assertNull($platform->notify('{"orderID":"38bb","driverStatus":{"code":"driverAtRestaurant"}}', $key)->status);

        $offline = $platform->notify('{"restaurantId":"38bb","lastChangedTimeStampUtc":"2022-08-15T10:12:56Z","delivery":{"isOffline":true}}', $key);
        self::assertSame(['restaurant.temp_offline', NotificationSubject::STORE, '38bb'], [$offline->event, $offline->subject, $offline->store]);
        self::assertTrue($offline->raw['delivery']['isOffline']);

        $backup = $platform->notify('{"validationError":"MENU_ERROR","order":{"orderId":"o-9"}}', $key);
        self::assertSame(['order.backup_flow', 'o-9'], [$backup->event, $backup->reference]);
        self::assertSame(NotificationSubject::OTHER, $platform->notify('{"something":"new"}', $key)->subject);
    }

    public function testAnOrderTakenInLaterOrNot(): void
    {
        $platform = $this->platform();
        $platform->accept('38bb');
        $platform->deny('38bc', DenyReason::ITEM_UNAVAILABLE, 'Items out of stock');

        [$method, $path, $headers, $body] = $this->calls[0];
        self::assertSame(['POST', '/order/38bb/sent-to-pos-success', 'flyt-key', []], [$method, $path, $headers['x-flyt-api-key'], $body]);
        [, $path, , $body] = $this->calls[1];
        self::assertSame('/order/38bc/sent-to-pos-failed', $path);
        self::assertSame('MENU_ERROR', $body['errorCode']);
        self::assertSame('Items out of stock', $body['errorMessage']);
        self::assertArrayHasKey('happenedAt', $body);
    }

    public function testWhatJetConnectDoesNotPublish(): void
    {
        $platform = $this->platform();
        foreach ([
            fn () => $platform->order('x'),
            fn () => $platform->orders(),
            fn () => $platform->ready('x'),
            fn () => $platform->cancel('x', DenyReason::TOO_BUSY),
            fn () => $platform->status(),
        ] as $call) {
            try {
                $call();
                self::fail('Not published by JET Connect.');
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testTheMenuInJetsShape(): void
    {
        $platform = $this->platform();
        $sauce = new ModifierGroup('sauce', 'Sauce', [new Modifier('soy', 'Soy'), new Modifier('ponzu', 'Ponzu', Money::of(50, 'GBP'))], 1, 1);
        $extras = new ModifierGroup('extras', 'Extras', [new Modifier('egg', 'Egg', Money::of(150, 'GBP'))]);
        $menu = new Menu('Dinner', [new Category('mains', 'Mains', [
            new Item('ramen', 'Shoyu ramen', Money::of(1400, 'GBP'), 'Chicken broth', 20.0, [Allergen::GLUTEN, Allergen::EGGS, Allergen::SESAME], 'https://site.example/ramen.jpg', [$sauce, $extras], true, ['CEREAL_WHEAT']),
            new Item('salad', 'Seaweed salad', Money::of(600, 'GBP'), null, 5.0, [Allergen::NUTS], available: false, labels: ['vegan']),
        ])], 'GBP', new Hours([1 => [['11:30', '14:30'], ['18:00', '22:00']]]), 'dinner');

        $platform->pushMenu($menu);

        [$method, $path, , $body] = $this->calls[0];
        self::assertSame(['POST', '/menus'], [$method, $path]);
        self::assertSame(['AKZ12'], $body['restaurants']);
        $sent = $body['menus'][0];
        self::assertSame(['Dinner', 'dinner', 'DELIVERY'], [$sent['name'], $sent['reference'], $sent['type']]);
        self::assertSame(['11:30 - 14:30', '18:00 - 22:00'], $sent['availability']['monday']);
        self::assertSame([], $sent['availability']['sunday']);
        self::assertSame(['name' => 'Mains', 'description' => '', 'type' => 'root'], array_diff_key($sent['categories'][0], ['items' => 1]));
        [$ramen, $salad] = $sent['categories'][0]['items'];
        self::assertSame('ramen', $ramen['plu']);
        self::assertSame(1400, $ramen['price']);
        self::assertSame('STANDARD_RATE', $ramen['tax_category']);
        self::assertSame(['CEREAL_WHEAT', 'EGGS', 'SESAME_SEEDS'], $ramen['allergens'], 'the cereal the item names');
        self::assertSame([['url' => 'https://site.example/ramen.jpg']], $ramen['gallery']);
        self::assertSame(['pick_same_option' => false, 'exactly' => 1], $ramen['modifiers'][0]['pick']);
        self::assertSame(['pick_same_option' => false, 'range' => ['from' => 0, 'to' => 1]], $ramen['modifiers'][1]['pick']);
        self::assertSame(['name' => 'Ponzu', 'plu' => 'ponzu', 'price' => 50, 'out_of_stock' => false], $ramen['modifiers'][0]['options'][1]);
        self::assertSame(JustEatPlatform::NUTS, $salad['allergens'], 'every nut, when none is named');
        self::assertSame(['VEGAN'], $salad['dietary_restrictions']);
        self::assertSame('REDUCED_RATE', $salad['tax_category']);
        self::assertTrue($salad['out_of_stock']);
    }

    public function testAMenuTheValidatorRefusesSendsNothing(): void
    {
        $this->expectException(InvalidMenuException::class);
        try {
            $this->platform()->pushMenu(new Menu('Dinner', [new Category('mains', 'Mains', [new Item('', 'Ramen', Money::of(1400, 'GBP'))])], 'GBP'));
        } finally {
            self::assertSame([], $this->calls);
        }
    }

    public function testAnItemOutOfStockUntilTomorrow(): void
    {
        $platform = $this->platform();
        $platform->setAvailability('DRINKS-023', false, new \DateTimeImmutable('2026-10-05T11:00:00+01:00'));
        $platform->setAvailability('DRINKS-023', true);

        self::assertSame(['event' => 'UNAVAILABLE', 'itemReferences' => ['DRINKS-023'], 'restaurant' => 'AKZ12', 'happenedAt' => $this->calls[0][3]['happenedAt'], 'nextAvailableAt' => '2026-10-05T11:00:00+01:00'], $this->calls[0][3]);
        self::assertSame('/item-availability', $this->calls[0][1]);
        self::assertSame('AVAILABLE', $this->calls[1][3]['event']);
        self::assertArrayNotHasKey('nextAvailableAt', $this->calls[1][3]);
    }

    public function testTheRestaurantOfflineOnlineAndItsHours(): void
    {
        $platform = $this->platform();
        $platform->pause(new \DateTimeImmutable('2026-10-04T18:30:00+00:00'));
        $platform->pause();
        $platform->resume();
        $platform->setHours(new Hours([1 => [['10:00', '18:00'], ['20:00', '23:00']], 2 => [['10:00', '18:00']]]));

        self::assertSame(['PUT', '/restaurants/AKZ12/offline', ['onlineAt' => '2026-10-04T19:30:00']], [$this->calls[0][0], $this->calls[0][1], $this->calls[0][3]], 'local time, no zone');
        self::assertSame([], $this->calls[1][3], 'offline until said otherwise');
        self::assertSame(['PUT', '/restaurants/AKZ12/online', null], [$this->calls[2][0], $this->calls[2][1], $this->calls[2][3]]);
        $hours = $this->calls[3][3];
        self::assertSame('/restaurants/AKZ12/servicetimes', $this->calls[3][1]);
        self::assertSame('Europe/London', $hours['timezone']);
        self::assertSame(['Delivery', 'Collection'], array_column($hours['serviceTimes'], 'serviceType'));
        self::assertSame([['openingTime' => '10:00', 'closingTime' => '18:00'], ['openingTime' => '20:00', 'closingTime' => '23:00']], $hours['serviceTimes'][0]['openingTimes']['monday']);
        self::assertSame([], $hours['serviceTimes'][1]['openingTimes']['sunday']);
    }

    public function testErrorsAreMapped(): void
    {
        try {
            $this->platform([], new MockResponse('{"success":false,"error_message":"Restaurant not registered"}', ['http_code' => 403]))->resume();
            self::fail('Forbidden.');
        } catch (UnauthorizedException $e) {
            self::assertSame('[justeat] Restaurant not registered', $e->getMessage());
            self::assertSame(403, $e->status);
        }
        try {
            $this->platform([], new MockResponse('{"message":"order 60298a19 not found"}', ['http_code' => 400]))->accept('60298a19');
            self::fail('Not pending any more.');
        } catch (ProviderException $e) {
            self::assertNotInstanceOf(UnauthorizedException::class, $e);
            self::assertSame('[justeat] order 60298a19 not found', $e->getMessage());
        }
    }

    public function testWithoutKeysOnlyWhatNeedsNoneWorks(): void
    {
        $platform = (new JustEatPlatformFactory(new MockHttpClient()))->create(['restaurant' => 'AKZ12']);

        self::assertSame('justeat', $platform->getName());
        self::assertTrue($platform->capabilities()->takes(OrderType::PICKUP));
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "justeat" platform needs: api_key.');
        $platform->setAvailability('x', false);
    }
}
