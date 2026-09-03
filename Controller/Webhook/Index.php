<?php

declare(strict_types=1);

namespace Allpaypayz\Magento2\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Allpaypayz\Exception\WebhookException;
use Allpaypayz\Webhooks;
use Psr\Log\LoggerInterface;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly JsonFactory $jsonFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): ResultInterface
    {
        $signKey = $this->encryptor->decrypt(
            (string) $this->scopeConfig->getValue('payment/allpaypayz/sign_key', ScopeInterface::SCOPE_STORE),
        );
        if ($signKey === '') {
            $r = $this->rawFactory->create();
            $r->setHttpResponseCode(500)->setContents('sign_key_unconfigured');
            return $r;
        }
        $rawBody = (string) $this->request->getContent();
        $sigHeader = (string) $this->request->getHeader('Callback-Signature');
        try {
            $event = Webhooks::verify(rawBody: $rawBody, signatureHeader: $sigHeader, signKey: $signKey);
        } catch (WebhookException $e) {
            $r = $this->rawFactory->create();
            $r->setHttpResponseCode(400)->setContents($e->errorCode);
            return $r;
        }
        $this->applyEvent($event);
        return $this->jsonFactory->create()->setData([]);
    }

    /** @param array<string, mixed> $event */
    private function applyEvent(array $event): void
    {
        $resource = $event['resource'] ?? null;
        $reference = is_array($resource) ? ($resource['merchant_reference'] ?? null) : null;
        if (!is_string($reference) || !preg_match('/^M2-(.+)$/', $reference, $m)) {
            return;
        }
        $incrementId = $m[1];

        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('increment_id', $incrementId)->setPageSize(1);
        /** @var Order|null $order */
        $order = $collection->getFirstItem();
        if (!$order->getId()) {
            return;
        }
        $type = (string) ($event['type'] ?? '');
        if (in_array($type, ['payment.succeeded', 'order.completed'], true)) {
            if (!$order->canInvoice()) {
                return;
            }
            $order->setState(Order::STATE_PROCESSING)
                  ->setStatus(Order::STATE_PROCESSING)
                  ->addStatusHistoryComment(__('Allpaypayz: payment confirmed via webhook.')->render());
            $this->orderRepository->save($order);
            return;
        }
        if (in_array($type, ['payment.failed', 'payment.cancelled', 'order.cancelled', 'order.expired'], true)) {
            $order->cancel();
            $order->addStatusHistoryComment((string) __('Allpaypayz: %1', $type));
            $this->orderRepository->save($order);
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true; // signature header takes the place of CSRF
    }
}
