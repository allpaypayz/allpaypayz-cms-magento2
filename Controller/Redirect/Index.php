<?php

declare(strict_types=1);

namespace Allpaypayz\Magento2\Controller\Redirect;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Allpaypayz\Magento2\Model\Payment\Allpaypayz as AllpaypayzMethod;
use Psr\Log\LoggerInterface;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ManagerInterface $messageManager,
        private readonly AllpaypayzMethod $allpaypayzMethod,
        private readonly LoggerInterface $logger,
        private readonly RequestInterface $request,
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $orderId = (int) $this->checkoutSession->getLastOrderId();
        if ($orderId <= 0) {
            $this->messageManager->addErrorMessage(__('Allpaypayz: order not found in session.'));
            return $redirect->setPath('checkout/cart');
        }
        try {
            $order = $this->orderRepository->get($orderId);
            $url = $this->allpaypayzMethod->createRedirect($order);
        } catch (\Throwable $e) {
            $this->logger->error('allpaypayz.redirect', ['err' => $e->getMessage()]);
            $this->messageManager->addErrorMessage(__('Allpaypayz: %1', $e->getMessage()));
            return $redirect->setPath('checkout/cart');
        }
        return $redirect->setUrl($url);
    }
}
