<?php

namespace Horeca\MiddlewareClientBundle\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Horeca\MiddlewareClientBundle\Controller\HorecaApiController;
use Horeca\MiddlewareClientBundle\Entity\OrderNotification;
use Horeca\MiddlewareClientBundle\Entity\Tenant;
use Horeca\MiddlewareClientBundle\Enum\MappingNotificationSource;
use Horeca\MiddlewareClientBundle\Enum\MappingNotificationStatus;
use Horeca\MiddlewareClientBundle\Enum\OrderNotificationType;
use Horeca\MiddlewareClientBundle\Event\TenantOrderEvent;
use Horeca\MiddlewareClientBundle\Message\MapTenantOrderAndSendToProviderMessage;
use Horeca\MiddlewareClientBundle\Message\MapTenantOrderAndSendUpdateToProviderMessage;
use Horeca\MiddlewareClientBundle\Message\SendTenantOrderToProviderMessage;
use Horeca\MiddlewareClientBundle\MessageHandler\OrderNotificationMessageHandler;
use Horeca\MiddlewareClientBundle\Repository\OrderNotificationRepository;
use Horeca\MiddlewareClientBundle\Service\MappingLogger;
use Horeca\MiddlewareClientBundle\Service\ProtocolActionsService;
use Horeca\MiddlewareClientBundle\VO\Horeca\HorecaReceiveOrderBody;
use Horeca\MiddlewareClientBundle\VO\Provider\BaseProviderOrderResponse;
use Horeca\MiddlewareCommonLib\Constants\ShoppingCartUpdateEvents;
use Horeca\MiddlewareCommonLib\Model\Cart\ShoppingCart;
use Horeca\MiddlewareCommonLib\Model\Restaurant\Restaurant;
use Symfony\Component\Serializer\SerializerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Re-sending an order whose record exists but was never accepted by the provider must be processed again.
 * Everything is in memory: one record store, a queueing message bus and a counting provider send.
 */
class ReceiveOrderRetryTest extends TestCase
{
    private const CART_ID = 'cart-1';

    /** @var OrderNotification[] */
    private array $store = [];
    private int $nextId = 1;

    /** @var object[] messages waiting to be handled, in dispatch order */
    private array $queue = [];
    /** @var object[] every message ever dispatched */
    private array $dispatched = [];
    /** @var string[] event names dispatched by the controller */
    private array $events = [];
    /** @var string[] type of the record at every provider send */
    private array $sends = [];
    private int $maps = 0;

    private Tenant $tenant;
    private HorecaApiController $controller;
    private OrderNotificationMessageHandler $handler;
    private MessageBusInterface $bus;
    private OrderNotificationRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();

        $this->repository = $repository = $this->createMock(OrderNotificationRepository::class);
        $repository->method('findOneByTenantOrderId')->willReturnCallback(
            fn(Tenant $tenant, string $id) => $this->findByTenantObjectId($id)
        );
        $repository->method('find')->willReturnCallback(fn($id) => $this->store[$id] ?? null);
        $repository->method('save')->willReturnCallback(function (OrderNotification $order): void {
            if ($order->getId() === null) {
                $this->assignId($order, 'n' . $this->nextId++);
            }
            $this->store[$order->getId()] = $order;
        });

        $this->bus = new class($this->queue, $this->dispatched) implements MessageBusInterface {
            public function __construct(private array &$queue, private array &$dispatched)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->queue[] = $message;
                $this->dispatched[] = $message;

                return new Envelope($message);
            }
        };

        $protocol = $this->createMock(ProtocolActionsService::class);
        $protocol->method('authorizeTenant')->willReturn($this->tenant);
        $protocol->method('mapTenantOrderToProviderOrder')->willReturnCallback(function (OrderNotification $n): void {
            $this->maps++;
            $n->setProviderPayload(['mapped' => true]);
            $n->changeStatus(MappingNotificationStatus::Mapped);
        });
        $protocol->method('sendTenantOrderToProvider')->willReturnCallback(function (OrderNotification $n) {
            $this->sends[] = $n->getType();
            $n->setProviderObjectId('provider-' . count($this->sends));
            $n->changeStatus(MappingNotificationStatus::Notified);

            return new BaseProviderOrderResponse($n->getProviderObjectId());
        });

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event, ?string $name = null) {
            $this->events[] = $name;

            return $event;
        });

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('deserialize')->willReturnCallback(fn() => $this->body());
        $serializer->method('serialize')->willReturn('{}');

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('https://example.test/notification');
        $container = new Container();
        $container->set('router', $router);

        $this->controller = new HorecaApiController(
            $serializer,
            new NullLogger(),
            $this->createMock(ValidatorInterface::class),
            $this->createMock(TranslatorInterface::class),
            $dispatcher
        );
        $this->controller->setContainer($container);
        $this->controller->setProtocolActionsService($protocol);
        $this->controller->setOrderNotificationRepository($repository);

        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->handler = new OrderNotificationMessageHandler($this->bus, $this->entityManager);
        $this->handler->setMappingLogger($this->createMock(MappingLogger::class));
        $this->handler->setOrderNotificationRepository($repository);
        $this->handler->setProtocolActionsService($protocol);
        $this->handler->setEventDispatcher($dispatcher);
    }

    public function test_rereceive_unaccepted_order_is_processed(): void
    {
        // e.g. created earlier by a status update, or its processing message was lost
        $existing = $this->storedRecord(OrderNotificationType::OrderUpdate);
        $existing->setEventType(ShoppingCartUpdateEvents::GENERIC_UPDATE);

        $response = $this->receiveOrder();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->store);
        $this->assertSame(OrderNotificationType::NewOrder, $existing->getType());
        $this->assertCount(1, $this->messagesOf(MapTenantOrderAndSendToProviderMessage::class));
        $this->assertSame($existing->getId(), $this->dispatched[0]->getNotificationId());

        $this->drainQueue();

        $this->assertSame([OrderNotificationType::NewOrder], $this->sends);
        $this->assertSame('provider-1', $existing->getProviderObjectId());
    }

    public function test_rereceive_accepted_order_is_update_only(): void
    {
        $existing = $this->storedRecord(OrderNotificationType::NewOrder);
        $existing->setProviderObjectId('provider-0');
        $existing->changeStatus(MappingNotificationStatus::Notified);

        $response = $this->receiveOrder();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->store);
        $this->assertSame(OrderNotificationType::OrderUpdate, $existing->getType());
        $this->assertSame(ShoppingCartUpdateEvents::GENERIC_UPDATE, $existing->getEventType());
        $this->assertSame('provider-0', $existing->getProviderObjectId());
        $this->assertSame([], $this->dispatched);
        $this->assertContains(TenantOrderEvent::ORDER_RECEIVED, $this->events);
    }

    public function test_duplicate_processing_message_sends_once(): void
    {
        $existing = $this->storedRecord(OrderNotificationType::NewOrder);

        // two quick re-receives before any worker ran
        $this->receiveOrder();
        $this->receiveOrder();
        $this->assertCount(2, $this->messagesOf(MapTenantOrderAndSendToProviderMessage::class));

        // the state is reloaded before it is checked, so a concurrent handler's write is seen
        $this->entityManager->expects($this->atLeastOnce())->method('refresh');
        $this->drainQueue();

        $this->assertSame([OrderNotificationType::NewOrder], $this->sends);
        $this->assertCount(2, $this->messagesOf(SendTenantOrderToProviderMessage::class));

        // a late duplicate neither re-maps nor regresses an accepted record
        $mapsBefore = $this->maps;
        $this->queue[] = new MapTenantOrderAndSendToProviderMessage($existing);
        $this->drainQueue();

        $this->assertSame($mapsBefore, $this->maps);
        $this->assertSame([OrderNotificationType::NewOrder], $this->sends);
        $this->assertSame(MappingNotificationStatus::Notified, $existing->getStatus());

        // the update path shares the send step and must still reach the provider
        $existing->setType(OrderNotificationType::OrderUpdate);
        $this->queue[] = new MapTenantOrderAndSendUpdateToProviderMessage($existing);
        $this->drainQueue();

        $this->assertSame([OrderNotificationType::NewOrder, OrderNotificationType::OrderUpdate], $this->sends);
    }

    public function test_new_order_flow_unchanged(): void
    {
        $response = $this->receiveOrder();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->store);
        $order = reset($this->store);
        $this->assertSame(OrderNotificationType::NewOrder, $order->getType());
        $this->assertSame(MappingNotificationSource::Tenant, $order->getSource());
        $this->assertNull($order->getEventType());
        $this->assertSame(self::CART_ID, $order->getTenantObjectId());
        $this->assertSame([TenantOrderEvent::ORDER_RECEIVED], $this->events);
        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(MapTenantOrderAndSendToProviderMessage::class, $this->dispatched[0]);

        $this->drainQueue();

        $this->assertSame([OrderNotificationType::NewOrder], $this->sends);
        $this->assertSame(1, $this->maps);
        $this->assertSame('provider-1', $order->getProviderObjectId());
        $this->assertSame(MappingNotificationStatus::Notified, $order->getStatus());
    }

    private function receiveOrder(): \Symfony\Component\HttpFoundation\Response
    {
        return $this->controller->receiveOrder(new Request(content: '{}'), $this->bus, $this->repository);
    }

    private function body(): HorecaReceiveOrderBody
    {
        $restaurant = $this->createMock(Restaurant::class);
        $restaurant->method('getId')->willReturn('shop-1');
        $cart = $this->createMock(ShoppingCart::class);
        $cart->method('getId')->willReturn(self::CART_ID);
        $cart->method('getRestaurant')->willReturn($restaurant);

        $body = new HorecaReceiveOrderBody();
        $body->cart = $cart;

        return $body;
    }

    private function storedRecord(string $type): OrderNotification
    {
        $order = new OrderNotification();
        $order->setType($type);
        $order->setSource(MappingNotificationSource::Tenant);
        $order->setTenant($this->tenant);
        $order->setTenantObjectId(self::CART_ID);
        $this->assignId($order, 'n' . $this->nextId++);
        $this->store[$order->getId()] = $order;

        return $order;
    }

    private function assignId(OrderNotification $order, string $id): void
    {
        (new \ReflectionProperty($order, 'id'))->setValue($order, $id);
    }

    private function findByTenantObjectId(string $id): ?OrderNotification
    {
        foreach ($this->store as $order) {
            if ($order->getTenantObjectId() === $id) {
                return $order;
            }
        }

        return null;
    }

    private function messagesOf(string $class): array
    {
        return array_values(array_filter($this->dispatched, fn($m) => $m instanceof $class));
    }

    /** Handles queued messages in FIFO order, like a single worker; handlers may enqueue more. */
    private function drainQueue(): void
    {
        $guard = 0;
        while ($this->queue && $guard++ < 50) {
            $message = array_shift($this->queue);
            match (true) {
                $message instanceof MapTenantOrderAndSendToProviderMessage => $this->handler->handleMapTenantOrderAndSendToProviderMessage($message),
                $message instanceof MapTenantOrderAndSendUpdateToProviderMessage => $this->handler->handleMapTenantOrderAndSendUpdateToProviderMessage($message),
                $message instanceof SendTenantOrderToProviderMessage => $this->handler->handleSendTenantOrderToProviderMessage($message),
                default => null,
            };
        }
    }
}
