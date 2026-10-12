<?php

namespace Horeca\MiddlewareClientBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Horeca\MiddlewareClientBundle\DependencyInjection\Framework\EventDispatcherDI;
use Horeca\MiddlewareClientBundle\DependencyInjection\Repository\OrderNotificationRepositoryDI;
use Horeca\MiddlewareClientBundle\DependencyInjection\Service\MappingLoggerDI;
use Horeca\MiddlewareClientBundle\DependencyInjection\Service\ProtocolActionsServiceDI;
use Horeca\MiddlewareClientBundle\DependencyInjection\Service\TenantApiServiceDI;
use Horeca\MiddlewareClientBundle\Entity\OrderNotification;
use Horeca\MiddlewareClientBundle\Enum\MappingNotificationEventName;
use Horeca\MiddlewareClientBundle\Enum\MappingNotificationSource;
use Horeca\MiddlewareClientBundle\Enum\MappingNotificationStatus;
use Horeca\MiddlewareClientBundle\Enum\OrderNotificationType;
use Horeca\MiddlewareClientBundle\Event\ProviderOrderEvent;
use Horeca\MiddlewareClientBundle\Event\TenantOrderEvent;
use Horeca\MiddlewareClientBundle\Exception\OrderMappingException;
use Horeca\MiddlewareClientBundle\Exception\ProviderApiException;
use Horeca\MiddlewareClientBundle\Message\MappingNotificationMessage;
use Horeca\MiddlewareClientBundle\Message\MapProviderOrderToTenantMessage;
use Horeca\MiddlewareClientBundle\Message\MapProviderOrderToTenantSyncMessage;
use Horeca\MiddlewareClientBundle\Message\MapTenantOrderAndSendToProviderMessage;
use Horeca\MiddlewareClientBundle\Message\MapTenantOrderAndSendUpdateToProviderMessage;
use Horeca\MiddlewareClientBundle\Message\MapTenantOrderToProviderMessage;
use Horeca\MiddlewareClientBundle\Message\MapTenantOrderToProviderSyncMessage;
use Horeca\MiddlewareClientBundle\Message\MessageTransports;
use Horeca\MiddlewareClientBundle\Message\MessageTransportsSync;
use Horeca\MiddlewareClientBundle\Message\Order\SendProviderOrderUpdateToTenantMessage;
use Horeca\MiddlewareClientBundle\Message\Order\SendProviderOrderUpdateToTenantSyncMessage;
use Horeca\MiddlewareClientBundle\Message\OrderNotificationEventMessage;
use Horeca\MiddlewareClientBundle\Message\SendProviderOrderToTenantMessage;
use Horeca\MiddlewareClientBundle\Message\SendProviderOrderToTenantSyncMessage;
use Horeca\MiddlewareClientBundle\Message\SendTenantOrderToProviderMessage;
use Horeca\MiddlewareClientBundle\Message\SendTenantOrderToProviderSyncMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles order notifications processing
 * 
 * This handler uses the #[AsMessageHandler] attribute on each method for Symfony 5.4+ compatibility.
 * The attributes work with PHP 8+ and are supported in Symfony 5.4, 6.x, and 7.x.
 */
class OrderNotificationMessageHandler
{
    use MappingLoggerDI;
    use OrderNotificationRepositoryDI;
    use ProtocolActionsServiceDI;
    use TenantApiServiceDI;
    use EventDispatcherDI;

    public function __construct(protected MessageBusInterface    $messageBus,
                                protected EntityManagerInterface $entityManager)
    {
    }

    /// Map Tenant Order To Provider [START]
    protected function handleMapTenantOrderToProviderMessageBase(MappingNotificationMessage $message): OrderNotification
    {
        $this->mappingLogger->logMemoryUsage();
        $notification = $this->getMessageOrderNotification($message);

        try {
            if ($notification->getSource() !== MappingNotificationSource::Tenant) {
                throw new OrderMappingException('You can map to provider only tenant sourced orders.');
            }
            $notification->changeStatus(MappingNotificationStatus::MappingStarted);
            $this->orderNotificationRepository->save($notification);

            $this->protocolActionsService->mapTenantOrderToProviderOrder($notification);

            if ($notification->getTenant()->isSubscribedToEvent(MappingNotificationEventName::MAPPING_COMPLETED)) {
                $this->messageBus->dispatch(new OrderNotificationEventMessage(MappingNotificationEventName::MAPPING_COMPLETED, $notification));
            }


        } catch (\Throwable $e) {
            $this->onOrderNotificationException($notification, $e);

            if ($notification->getTenant()->isSubscribedToEvent(MappingNotificationEventName::MAPPING_FAILED)) {
                $this->messageBus->dispatch(new OrderNotificationEventMessage(MappingNotificationEventName::MAPPING_FAILED, $notification));
            }
        } finally {
            $this->mappingLogger->logMemoryUsage();
            $this->mappingLogger->saveTo($notification, 'OrderNotificationMessageHandler::handleMapTenantOrderToProviderMessage');
        }
        return $notification;
    }

    #[AsMessageHandler(fromTransport: MessageTransports::MAP_TENANT_ORDER_TO_PROVIDER)]
    public function handleMapTenantOrderToProviderMessage(MapTenantOrderToProviderMessage $message): void
    {
        $this->handleMapTenantOrderToProviderMessageBase($message);
    }

    #[AsMessageHandler(fromTransport: MessageTransportsSync::SYNC)]
    public function handleMapTenantOrderToProviderSyncMessage(MapTenantOrderToProviderSyncMessage $message): void
    {
        $this->handleMapTenantOrderToProviderMessageBase($message);
    }

    #[AsMessageHandler(fromTransport: MessageTransports::MAP_TENANT_ORDER_AND_SEND_TO_PROVIDER)]
    public function handleMapTenantOrderAndSendToProviderMessage(MapTenantOrderAndSendToProviderMessage $message): void
    {
        if ($this->isNewOrderAlreadyAccepted($this->getMessageOrderNotification($message))) {
            return;
        }

        $notification = $this->handleMapTenantOrderToProviderMessageBase($message);

        $this->eventDispatcher->dispatch(new TenantOrderEvent($notification), TenantOrderEvent::ORDER_MAPPED);

        $this->messageBus->dispatch(new SendTenantOrderToProviderMessage($notification));

    }

    #[AsMessageHandler(fromTransport: MessageTransports::MAP_TENANT_ORDER_AND_SEND_UPDATE_TO_PROVIDER)]
    public function handleMapTenantOrderAndSendUpdateToProviderMessage(MapTenantOrderAndSendUpdateToProviderMessage $message): void
    {
        $notification = $this->handleMapTenantOrderToProviderMessageBase($message);

        $this->eventDispatcher->dispatch(new TenantOrderEvent($notification), TenantOrderEvent::ORDER_UPDATE_MAPPED);

        $this->messageBus->dispatch(new SendTenantOrderToProviderMessage($notification));

    }
    /// Map Tenant Order To Provider [END]


    /// Map Provider Order To Tenant [START]
    protected function handleMapProviderOrderToTenantMessageBase(MappingNotificationMessage $message, ?bool $sync = false): void
    {
        $this->mappingLogger->logMemoryUsage();
        $notification = $this->getMessageOrderNotification($message);

        try {
            if ($notification->getSource() !== MappingNotificationSource::Provider) {
                throw new OrderMappingException('You can map to tenant only provider sourced orders.');
            }

            $notification->changeStatus(MappingNotificationStatus::MappingStarted);
            $this->orderNotificationRepository->save($notification);

            $this->protocolActionsService->mapProviderOrderToTenantOrder($notification);
            //todo send event to provider if subscribed

//            if ($notification->getTenant()->isSubscribedToEvent(MappingNotificationEventName::MAPPING_COMPLETED)) {
//                $this->messageBus->dispatch(new OrderNotificationEventMessage(MappingNotificationEventName::MAPPING_COMPLETED, $notification));
//            }
            if (!$sync) {
                $this->messageBus->dispatch(new SendProviderOrderToTenantMessage($notification));
            }
        } catch (\Throwable $e) {
            $this->onOrderNotificationException($notification, $e);
            //todo send event to provider if subscribed

//            if ($notification->getTenant()->isSubscribedToEvent(MappingNotificationEventName::MAPPING_FAILED)) {
//                $this->messageBus->dispatch(new OrderNotificationEventMessage(MappingNotificationEventName::MAPPING_FAILED, $notification));
//            }
        } finally {
            $this->mappingLogger->logMemoryUsage();
            $this->mappingLogger->saveTo($notification, 'OrderNotificationMessageHandler::handleMapProviderOrderToTenantMessage');
        }
    }

    #[AsMessageHandler(fromTransport: MessageTransports::MAP_PROVIDER_ORDER_TO_TENANT)]
    public function handleMapProviderOrderToTenantMessage(MapProviderOrderToTenantMessage $message): void
    {
        $this->handleMapProviderOrderToTenantMessageBase($message);
    }

    #[AsMessageHandler(fromTransport: MessageTransportsSync::SYNC)]
    public function handleMapProviderOrderToTenantSyncMessage(MapProviderOrderToTenantSyncMessage $message): void
    {
        $this->handleMapProviderOrderToTenantMessageBase($message, true);
    }
    /// Map Provider Order To Tenant [END]


    /// Send Tenant Order To Provider [START]
    protected function handleSendTenantOrderToProviderMessageBase(MappingNotificationMessage $message, ?bool $sync = false): void
    {
        $this->mappingLogger->logMemoryUsage();
        $notification = $this->getMessageOrderNotification($message);

        if ($this->isNewOrderAlreadyAccepted($notification)) {
            return;
        }

        try {
            if (!$notification->hasStatus(MappingNotificationStatus::Mapped) || empty($notification->getProviderPayload())) {
                $this->mappingLogger->info(__METHOD__, __LINE__, sprintf('Notification %s status is not *mapped*. Action aborted.', $notification->getId()));

                throw new OrderMappingException('Order is not sent to provider.');
            }

            if ($notification->getStatus() !== MappingNotificationStatus::SendingNotification) {
                $notification->changeStatus(MappingNotificationStatus::SendingNotification);
                $this->orderNotificationRepository->save($notification);
            }

            $this->mappingLogger->info(__METHOD__, __LINE__, 'Sending order to provider...');
            $this->protocolActionsService->sendTenantOrderToProvider($notification);
            $this->mappingLogger->info(__METHOD__, __LINE__, sprintf('Order %s sent to provider with id %s', $notification->getId(), $notification->getProviderObjectId()));

            if ($notification->getTenant()->isSubscribedToEvent(MappingNotificationEventName::PROVIDER_NOTIFIED)) {
                if (!$sync) {
                    $this->messageBus->dispatch(new OrderNotificationEventMessage(MappingNotificationEventName::PROVIDER_NOTIFIED, $notification));
                }
            }
        } catch (\Throwable $e) {
            $this->onOrderNotificationException($notification, $e);

            if ($notification->getTenant()->isSubscribedToEvent(MappingNotificationEventName::PROVIDER_NOTIFICATION_FAILED)) {
                $this->messageBus->dispatch(new OrderNotificationEventMessage(MappingNotificationEventName::PROVIDER_NOTIFICATION_FAILED, $notification , 'handleSendTenantOrderToProviderMessageBase'));
            }
        } finally {
            $this->mappingLogger->logMemoryUsage();
            $this->mappingLogger->saveTo($notification, 'OrderNotificationMessageHandler::handleSendTenantOrderToProviderMessage');
        }
    }

    #[AsMessageHandler(fromTransport: MessageTransports::SEND_TENANT_ORDER_TO_PROVIDER)]
    public function handleSendTenantOrderToProviderMessage(SendTenantOrderToProviderMessage $message): void
    {
        $this->handleSendTenantOrderToProviderMessageBase($message);
    }

    #[AsMessageHandler(fromTransport: MessageTransportsSync::SYNC)]
    public function handleSendTenantOrderToProviderSyncMessage(SendTenantOrderToProviderSyncMessage $message): void
    {
        $this->handleSendTenantOrderToProviderMessageBase($message, true);
    }
    /// Send Tenant Order To Provider [END]


    /// Send Order Notification Event [START]
    protected function handleOrderNotificationEventMessageBase(OrderNotificationEventMessage $message, ?bool $sync = false): void
    {
        $this->mappingLogger->logMemoryUsage();
        $notification = $this->getMessageOrderNotification($message);

        try {
            if ($notification->getSource() === MappingNotificationSource::Tenant && $notification->getTenant()->isSubscribedToEvent($message->getEvent())) {
                $this->tenantApiService->sendOrderNotificationEvent($message->getEvent(), $notification);
            }

            if ($notification->getStatus() !== MappingNotificationStatus::Confirmed && in_array($message->getEvent(), [
                    MappingNotificationEventName::PROVIDER_NOTIFIED,
                    MappingNotificationEventName::TENANT_NOTIFIED
                ])) {
                $notification->changeStatus(MappingNotificationStatus::Confirmed);
                $this->entityManager->flush();
            }

            // todo: send event to provider if needed, for the other order source
        } catch (\Throwable $e) {
            $this->onOrderNotificationException($notification, $e);
        } finally {
            $this->mappingLogger->logMemoryUsage();
            $this->mappingLogger->saveTo($notification, 'OrderNotificationMessageHandler::handleOrderNotificationEventMessage');
        }
    }

    #[AsMessageHandler(fromTransport: MessageTransports::ORDER_NOTIFICATION_EVENT)]
    public function handleOrderNotificationEventMessage(OrderNotificationEventMessage $message): void
    {
        $this->handleOrderNotificationEventMessageBase($message);
    }

    #[AsMessageHandler(fromTransport: MessageTransportsSync::SYNC)]
    public function handleOrderNotificationEventSyncMessage(OrderNotificationEventMessage $message): void
    {
        $this->handleOrderNotificationEventMessageBase($message, true);
    }
    /// Send Order Notification Event [END]


    /// Send Provider Order To Tenant [START]
    protected function handleSendProviderOrderToTenantMessageBase(MappingNotificationMessage $message, ?bool $sync = false): void
    {
        $this->mappingLogger->logMemoryUsage();
        $notification = $this->getMessageOrderNotification($message);

        try {
            $notification->changeStatus(MappingNotificationStatus::SendingNotification);
            $this->orderNotificationRepository->save($notification);

            $response = $this->protocolActionsService->sendProviderOrderToTenant($notification);

            if ($notification->getTenant()->isSubscribedToEvent(MappingNotificationEventName::TENANT_NOTIFIED)) {
                $this->messageBus->dispatch(new OrderNotificationEventMessage(MappingNotificationEventName::TENANT_NOTIFIED, $notification));
            }
            $this->eventDispatcher->dispatch(new ProviderOrderEvent($notification), ProviderOrderEvent::TENANT_NOTIFIED);

        } catch (\Throwable $e) {
            $this->onOrderNotificationException($notification, $e);
        } finally {
            $this->mappingLogger->logMemoryUsage();
            $this->mappingLogger->saveTo($notification, 'OrderNotificationMessageHandler::handleSendProviderOrderToTenantMessage');
        }
    }

    #[AsMessageHandler(fromTransport: MessageTransports::SEND_PROVIDER_ORDER_TO_TENANT)]
    public function handleSendProviderOrderToTenantMessage(SendProviderOrderToTenantMessage $message): void
    {
        $this->handleSendProviderOrderToTenantMessageBase($message);
    }

    #[AsMessageHandler(fromTransport: MessageTransportsSync::SYNC)]
    public function handleSendProviderOrderToTenantSyncMessage(SendProviderOrderToTenantSyncMessage $message): void
    {
        $this->handleSendProviderOrderToTenantMessageBase($message, true);
    }
    /// Send Provider Order To Tenant [END]


    /// Send Provider Order To Tenant [START]
    protected function handleSendProviderOrderUpdateToTenantMessageBase(MappingNotificationMessage $message, ?bool $sync = false): void
    {
        $this->mappingLogger->logMemoryUsage();
        $notification = $this->getMessageOrderNotification($message);

        $be = null;
        try {
            $notification->changeStatus(MappingNotificationStatus::SendingNotification);
            $this->orderNotificationRepository->save($notification);

            $this->protocolActionsService->sendProviderOrderUpdateToTenant($notification);

            $this->eventDispatcher->dispatch(new ProviderOrderEvent($notification), ProviderOrderEvent::TENANT_NOTIFIED);

        } catch (\Throwable $e) {
            $this->onOrderNotificationException($notification, $e);
            $be = $e;
        } finally {
            $this->mappingLogger->logMemoryUsage();
            $this->mappingLogger->saveTo($notification, 'OrderNotificationMessageHandler::handleSendProviderOrderUpdateToTenantMessageBase');
        }

        if($be){
            throw $be;
        }
    }

    #[AsMessageHandler(fromTransport: MessageTransports::SEND_PROVIDER_ORDER_UPDATE_TO_TENANT)]
    public function handleSendProviderOrderUpdateToTenantMessage(SendProviderOrderUpdateToTenantMessage $message): void
    {
        $this->handleSendProviderOrderUpdateToTenantMessageBase($message);
    }

    #[AsMessageHandler(fromTransport: MessageTransportsSync::SYNC)]
    public function handleSendProviderOrderUpdateToTenantSyncMessage(SendProviderOrderUpdateToTenantSyncMessage $message): void
    {
        $this->handleSendProviderOrderUpdateToTenantMessageBase($message, true);
    }

    /// Send Provider Order To Tenant [END]


    protected function onOrderNotificationException(OrderNotification $notification, \Throwable $e): void
    {
        $this->mappingLogger->error(__METHOD__, __LINE__, $e->getMessage());
        $this->mappingLogger->debug(__METHOD__, __LINE__, $e->getTraceAsString());

        $notification->changeStatus(MappingNotificationStatus::Failed);
        $notification->setErrorMessage($e->getMessage());

        if ($e instanceof ProviderApiException) {
            $notification->setResponsePayloadString($e->getResponseContent());
        }

        $this->orderNotificationRepository->save($notification);
    }

    /**
     * Duplicate new-order messages must not send twice. Updates legitimately carry a provider id, so only new orders count.
     */
    protected function isNewOrderAlreadyAccepted(OrderNotification $notification): bool
    {
        // reload: a concurrent handler may have stored the provider id after this entity was loaded
        $this->entityManager->refresh($notification);

        return $notification->isType(OrderNotificationType::NewOrder) && !empty($notification->getProviderObjectId());
    }

    protected function getMessageOrderNotification(MappingNotificationMessage $message): OrderNotification
    {
        $notification = $this->orderNotificationRepository->find($message->getNotificationId());

        if (!$notification) {
            throw new UnrecoverableMessageHandlingException(sprintf('OrderNotification with id %s not found', $message->getNotificationId()));
        }

        return $notification;
    }
}
