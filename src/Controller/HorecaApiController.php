<?php

namespace Horeca\MiddlewareClientBundle\Controller;

use Horeca\MiddlewareClientBundle\DependencyInjection\Repository\OrderNotificationRepositoryDI;
use Horeca\MiddlewareClientBundle\DependencyInjection\Repository\TenantRepositoryDI;
use Horeca\MiddlewareClientBundle\DependencyInjection\Service\ProtocolActionsServiceDI;
use Horeca\MiddlewareClientBundle\DependencyInjection\Service\ProviderApiDI;
use Horeca\MiddlewareClientBundle\DependencyInjection\Service\TenantApiServiceDI;
use Horeca\MiddlewareClientBundle\DependencyInjection\Service\TenantServiceDI;
use Horeca\MiddlewareClientBundle\Entity\OrderNotification;
use Horeca\MiddlewareClientBundle\Enum\MappingNotificationSource;
use Horeca\MiddlewareClientBundle\Enum\MappingNotificationStatus;
use Horeca\MiddlewareClientBundle\Enum\OrderNotificationType;
use Horeca\MiddlewareClientBundle\Enum\SerializationGroups;
use Horeca\MiddlewareClientBundle\Event\TenantOrderEvent;
use Horeca\MiddlewareClientBundle\Exception\ApiException;
use Horeca\MiddlewareClientBundle\Message\MapTenantOrderAndSendToProviderMessage;
use Horeca\MiddlewareClientBundle\Message\MapTenantOrderAndSendUpdateToProviderMessage;
use Horeca\MiddlewareClientBundle\Repository\OrderNotificationRepository;
use Horeca\MiddlewareClientBundle\Service\InitializeShopApiInterface;
use Horeca\MiddlewareClientBundle\Service\RequestDeliveryApiInterface;
use Horeca\MiddlewareClientBundle\Service\SyncAndExportShopProductsApiInterface;
use Horeca\MiddlewareClientBundle\Service\UpdateShopApiInterface;
use Horeca\MiddlewareClientBundle\Service\UpdateShopAvailabilityApiInterface;
use Horeca\MiddlewareClientBundle\VO\Api\OrderNotificationResponseDataDto;
use Horeca\MiddlewareClientBundle\VO\Horeca\HorecaInitializeShopBody;
use Horeca\MiddlewareClientBundle\VO\Horeca\HorecaReceiveOrderBody;
use Horeca\MiddlewareClientBundle\VO\Horeca\HorecaReceiveOrderUpdateBody;
use Horeca\MiddlewareClientBundle\VO\Horeca\HorecaRequestDeliveryBody;
use Horeca\MiddlewareClientBundle\VO\Horeca\HorecaSendOrderBody;
use Horeca\MiddlewareClientBundle\VO\Horeca\HorecaSyncAndExportShopProductsBody;
use Horeca\MiddlewareClientBundle\VO\Horeca\HorecaUpdateShopAvailabilityBody;
use Horeca\MiddlewareClientBundle\VO\Horeca\HorecaUpdateShopBody;
use Horeca\MiddlewareCommonLib\Constants\ShoppingCartUpdateEvents;
use Horeca\MiddlewareCommonLib\Exception\HorecaException;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class HorecaApiController extends AbstractController
{
    use OrderNotificationRepositoryDI;
    use TenantRepositoryDI;
    use ProviderApiDI;
    use TenantApiServiceDI;
    use ProtocolActionsServiceDI;
    use TenantServiceDI;

    public function __construct(protected SerializerInterface      $serializer,
                                protected LoggerInterface          $logger,
                                protected ValidatorInterface       $validator,
                                protected TranslatorInterface      $translator,
                                protected EventDispatcherInterface $eventDispatcher)
    {
    }

    public function requestDelivery(Request $request): Response
    {
        try {
            /** @var HorecaRequestDeliveryBody $body */
            $body = $this->deserializeRequestBodyAndValidate($request, HorecaRequestDeliveryBody::class);
            $tenant = $this->protocolActionsService->authorizeTenant($request);
            $credentials = $this->tenantService->compileTenantCredentials($tenant, $body->providerCredentials);

            $errors = $this->validateObject($body->form);
            if (count($errors) > 0) {
                return new Response(json_encode($errors));
            }

            if ($this->providerApi instanceof RequestDeliveryApiInterface) {
                if (!$this->providerApi->requestDelivery($tenant, $body, $credentials)) {
                    return new JsonResponse(['success' => false], Response::HTTP_BAD_REQUEST);
                }
            } else {
                $this->logger->error(sprintf('[%s] Provider API does not support delivery request', __METHOD__));
                return new JsonResponse(['success' => false], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    public function viewNotification(Request $request): Response
    {
        try {
            if (!$notificationId = $request->query->get('id')) {
                throw new HorecaException('Missing notification ID');
            }

            if (!$notification = $this->orderNotificationRepository->find($notificationId)) {
                throw new HorecaException('Notification not found');
            }

            if ($url = $this->providerApi->generateNotificationViewUrl($notification)) {
                return $this->redirect($url);
            }

            return new JsonResponse([
                'status' => $notification->getStatus()
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }


    /**
     * @deprecated
     */
    public function sendOrder(Request                     $request,
                              MessageBusInterface         $messageBus,
                              OrderNotificationRepository $orderNotificationRepository): Response
    {
        return $this->receiveOrder($request, $messageBus, $orderNotificationRepository);
    }

    public function receiveOrder(Request                     $request,
                                 MessageBusInterface         $messageBus,
                                 OrderNotificationRepository $orderNotificationRepository): Response
    {
        try {
            $tenant = $this->protocolActionsService->authorizeTenant($request);

            try {
                // this perform validate and is not the case for updates
//                /** @var HorecaSendOrderBody $body */
//                $body = $this->deserializeRequestBodyAndValidate($request, HorecaSendOrderBody::class);

                /** @var HorecaReceiveOrderBody $body */
                $body = $this->deserializeObject($request->getContent(), HorecaReceiveOrderBody::class);

            } catch (\Throwable $e) {
                if ($e instanceof ApiException) {
                    throw $e;
                } else {
                    $this->logger->error(sprintf('[%s] %s', __METHOD__, $e->getMessage()));
                    throw new ApiException('Invalid request body');
                }
            }


            $order = $orderNotificationRepository->findOneByTenantOrderId($tenant, $body->cart->getId());
            $dispatchMessage = false;
            if (!$order) {
                $this->logger->info(sprintf('[%s.%d] New order received: %s', __METHOD__, __LINE__, $body->cart->getId()));

                $order = new OrderNotification();
                $order->setType(OrderNotificationType::NewOrder);
                $order->setSource(MappingNotificationSource::Tenant);

                $dispatchMessage = true;
            } elseif (!$order->getProviderObjectId()) {
                // not accepted by the provider: a failed or stale one needs a new-order retry, an in-flight one is left alone
                if (
                    MappingNotificationStatus::Failed === $order->getStatus()
                    || $order->getCreatedAt() <= new \DateTime('-5 minutes')
                ) {
                    $order->setType(OrderNotificationType::NewOrder);

                    $dispatchMessage = true;
                }
            } else {
                $order->setType(OrderNotificationType::OrderUpdate);
                $order->setEventType(ShoppingCartUpdateEvents::GENERIC_UPDATE);
            }

            $order->setTenant($tenant);
            $order->setTenantObjectId($body->cart->getId());
            $order->setTenantPayloadString($this->serializer->serialize($body->cart, 'json'));
            $order->setTenantShopId($body->cart->getRestaurant()->getId());

            if ($body->providerCredentials) {
                $order->setServiceCredentials($body->providerCredentials);
            }

            $this->orderNotificationRepository->save($order);

            $order->setViewUrl(
                $this->generateUrl('horeca_api_notification_view', ['id' => $order->getId()], UrlGeneratorInterface::ABSOLUTE_URL)
            );
            $this->orderNotificationRepository->save($order);

            $this->eventDispatcher->dispatch(new TenantOrderEvent($order), TenantOrderEvent::ORDER_RECEIVED);

            if ($dispatchMessage) {
                $messageBus->dispatch(new MapTenantOrderAndSendToProviderMessage($order));
            }

            $context = SerializationContext::create()->setGroups([SerializationGroups::TenantOrderNotificationView]);
            $data = $this->serializer->serialize(new OrderNotificationResponseDataDto($order), 'json', $context);

            return new JsonResponse($data, 200, [], true);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function receiveOrderUpdate(Request             $request,
                                       MessageBusInterface $messageBus): Response
    {
        try {
            $tenant = $this->protocolActionsService->authorizeTenant($request);

            try {
                // this perform validate and is not the case for updates
//                $body = $this->deserializeRequestBody($request, HorecaReceiveOrderUpdateBody::class);

                /** @var HorecaReceiveOrderUpdateBody $body */
                $body = $this->deserializeObject($request->getContent(), HorecaReceiveOrderUpdateBody::class);
            } catch (\Throwable $e) {
                if ($e instanceof ApiException) {
                    throw $e;
                } else {
                    $this->logger->error(sprintf('[%s] %s', __METHOD__, $e->getMessage()));
                    throw new ApiException('Invalid request body');
                }
            }


            $this->logger->info(sprintf('[%s.%d] New order received: %s', __METHOD__, __LINE__, $body->cart->getId()));

            $order = new OrderNotification();
            $order->setType(OrderNotificationType::OrderUpdate);
            $order->setSource(MappingNotificationSource::Tenant);
            $order->setEventType($body->eventType);


            $order->setTenant($tenant);
            $order->setTenantObjectId($body->cart->getId());
            if ($body->cart->getExternalProviderId()) {
                $order->setProviderObjectId($body->cart->getExternalProviderId());
            }
            $order->setTenantPayloadString($this->serializer->serialize($body->cart, 'json'));
            $order->setTenantShopId($body->cart->getRestaurant()->getId());

            if ($body->providerCredentials) {
                $order->setServiceCredentials($body->providerCredentials);
            }

            $this->orderNotificationRepository->save($order);

            $order->setViewUrl(
                $this->generateUrl('horeca_api_notification_view', ['id' => $order->getId()], UrlGeneratorInterface::ABSOLUTE_URL)
            );
            $this->orderNotificationRepository->save($order);

            $this->eventDispatcher->dispatch(new TenantOrderEvent($order), TenantOrderEvent::ORDER_UPDATE_RECEIVED);

            $messageBus->dispatch(new MapTenantOrderAndSendUpdateToProviderMessage($order));

            $context = SerializationContext::create()->setGroups([SerializationGroups::TenantOrderNotificationView]);
            $data = $this->serializer->serialize(new OrderNotificationResponseDataDto($order), 'json', $context);

            return new JsonResponse($data, 200, [], true);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function initializeShop(Request $request): Response
    {
        try {
            /** @var HorecaInitializeShopBody $body */
            $body = $this->deserializeRequestBodyAndValidate($request, HorecaInitializeShopBody::class);
            $tenant = $this->protocolActionsService->authorizeTenant($request);

            if ($this->tenantApiService instanceof InitializeShopApiInterface) {
                if (!$this->tenantApiService->initializeShop($tenant, $body->tenantShopId, $body->providerShopId, $body->shopName)) {
                    return new JsonResponse(['success' => false], Response::HTTP_BAD_REQUEST);
                }
            } else {
                $this->logger->error(sprintf('[%s] Tenant API does not support shop initialization', __METHOD__));
                return new JsonResponse(['success' => false], Response::HTTP_BAD_REQUEST);
            }
            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function syncAndExport(Request $request): Response
    {
        try {
            /** @var HorecaSyncAndExportShopProductsBody $body */
            $body = $this->deserializeRequestBodyAndValidate($request, HorecaSyncAndExportShopProductsBody::class);
            $tenant = $this->protocolActionsService->authorizeTenant($request);

            if ($this->tenantApiService instanceof SyncAndExportShopProductsApiInterface) {
                if (!$this->tenantApiService->syncAndExportProducts($tenant, $body->tenantShopId)) {
                    return new JsonResponse(['success' => false], Response::HTTP_BAD_REQUEST);
                }
            } else {
                $this->logger->error(sprintf('[%s] Tenant API does not support shop syncAndExport', __METHOD__));
                return new JsonResponse(['success' => false], Response::HTTP_BAD_REQUEST);
            }
            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function updateShopAvailability(Request $request): Response
    {
        try {
            /** @var HorecaUpdateShopAvailabilityBody $body */
            $body = $this->deserializeRequestBodyAndValidate($request, HorecaUpdateShopAvailabilityBody::class);
            $tenant = $this->protocolActionsService->authorizeTenant($request);

            if ($this->providerApi instanceof UpdateShopAvailabilityApiInterface) {
                if (!$this->providerApi->updateShopAvailability($tenant, $body->tenantShopId, $body->open)) {
                    return new JsonResponse(['success' => false], Response::HTTP_BAD_REQUEST);
                }
            } else {
                $this->logger->error(sprintf('[%s] Tenant API does not support shop updateShopAvailability', __METHOD__));
                return new JsonResponse(['success' => false], Response::HTTP_BAD_REQUEST);
            }
            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function updateShop(Request $request): Response
    {
        try {
            /** @var HorecaUpdateShopBody $body */
            $body = $this->deserializeRequestBodyAndValidate($request, HorecaUpdateShopBody::class);
            $tenant = $this->protocolActionsService->authorizeTenant($request);

            if ($this->providerApi instanceof UpdateShopApiInterface) {
                if (!$this->providerApi->updateShop($tenant, $body->tenantShopId, $body->shop)) {
                    return new JsonResponse(['success' => false], Response::HTTP_BAD_REQUEST);
                }
            } else {
                $this->logger->error(sprintf('[%s] Tenant API does not support shop updateShopAvailability', __METHOD__));
                return new JsonResponse(['success' => false], Response::HTTP_BAD_REQUEST);
            }
            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * @throws ApiException
     */
    private function deserializeRequestBodyAndValidate(Request $request, string $type): object
    {
        $body = $this->deserializeObject($request->getContent(), $type);

        $errors = $this->validateObject($body);
        if (count($errors) > 0) {
            throw new ApiException(json_encode($errors));
        }

        return $body;
    }

    private function deserializeObject(string $json, string $type): object
    {
        return $this->serializer->deserialize($json, $type, 'json');
    }

    private function validateObject(object $object): array
    {
        $errorMessages = [];
        $errors = $this->validator->validate($object);
        if (count($errors) > 0) {

            foreach ($errors as $violation) {
                $errorMessages[] = $this->translator->trans($violation->getMessage(), [], 'validators');
            }
        }

        return $errorMessages;
    }

    private function handleException(\Throwable $e): JsonResponse
    {
        $this->logger->error(sprintf('[%s] %s', __METHOD__, $e->getMessage()));
        $this->logger->error(sprintf('[%s] %s', __METHOD__, $e->getTraceAsString()));

        $data = [
            'success' => false,
            'message' => ($e instanceof HorecaException || $e instanceof ApiException) ? $e->getMessage() : 'Internal Server Error',
        ];

        $env = $this->getParameter('kernel.environment');
        if ($env === 'test') {
            $data['trace'] = $e->getTraceAsString();
        }

        $code = ($e instanceof HorecaException || $e instanceof ApiException)
            ? Response::HTTP_BAD_REQUEST
            : Response::HTTP_INTERNAL_SERVER_ERROR;

        return new JsonResponse($data, $code);
    }
}
