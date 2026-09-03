<?php

declare(strict_types=1);

namespace Allpaypayz\Magento2\Model\Payment;

use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Model\Order;
use Allpaypayz\Exception\AllpaypayzException;
use Allpaypayz\Allpaypayz as AllpaypayzClient;
use Psr\Log\LoggerInterface;

/**
 * Allpaypayz redirect-style payment method. After the customer places the order
 * Magento routes them to ``Controller\Redirect\Index`` which calls Allpaypayz and
 * 302s to the hosted checkout URL.
 */
class Allpaypayz extends AbstractMethod
{
    protected $_code = 'allpaypayz';
    protected $_isOffline = false;
    protected $_canRefund = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = false;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttrFactory,
        PaymentHelper $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        private readonly UrlInterface $urlBuilder,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $appLogger,
        array $data = [],
    ) {
        parent::__construct(
            $context, $registry, $extensionFactory, $customAttrFactory,
            $paymentData, $scopeConfig, $logger,
            null, null, $data,
        );
    }

    public function getOrderPlaceRedirectUrl(): string
    {
        return $this->urlBuilder->getUrl('allpaypayz/redirect');
    }

    public function isAvailable(?CartInterface $quote = null): bool
    {
        return parent::isAvailable($quote);
    }

    /** Issues an SDK call to Allpaypayz and returns the hosted checkout URL. */
    public function createRedirect(Order $order): string
    {
        $apiKey = $this->encryptor->decrypt((string) $this->getConfigData('api_key'));
        $baseUrl = (string) $this->getConfigData('base_url');
        $client = new AllpaypayzClient(apiKey: $apiKey, baseUrl: $baseUrl);

        try {
            $payment = $client->payments->createRedirect([
                'merchant_reference' => 'M2-' . $order->getIncrementId(),
                'amount' => [
                    'amount_minor' => (int) round((float) $order->getGrandTotal() * 100),
                    'currency'     => $order->getOrderCurrencyCode(),
                ],
                'description'    => 'Magento order #' . $order->getIncrementId(),
                'payment_method' => (string) $this->getConfigData('payment_method'),
                'customer' => [
                    'name'  => trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname()),
                    'email' => (string) $order->getCustomerEmail(),
                ],
                'urls' => [
                    'success'  => $this->urlBuilder->getUrl('checkout/onepage/success'),
                    'error'    => $this->urlBuilder->getUrl('checkout/onepage/failure'),
                    'callback' => $this->urlBuilder->getUrl('allpaypayz/webhook'),
                ],
                'extra_data' => [
                    'magento_order_id'        => (string) $order->getId(),
                    'magento_increment_id'    => (string) $order->getIncrementId(),
                ],
            ]);
        } catch (AllpaypayzException $e) {
            $this->appLogger->error('allpaypayz.createRedirect', ['error' => $e->errorCode, 'message' => $e->getMessage()]);
            throw new \Magento\Framework\Exception\LocalizedException(__('Allpaypayz payment initiation failed.'));
        }

        $checkoutUrl = $payment['checkout_url'] ?? null;
        if (!is_string($checkoutUrl)) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Allpaypayz returned no checkout URL.'));
        }
        $order->setExtOrderId((string) ($payment['id'] ?? ''));
        $order->save();
        return $checkoutUrl;
    }

    /** @param float|string $amount */
    public function refund(InfoInterface $payment, $amount): self
    {
        $order = $payment->getOrder();
        $apiKey = $this->encryptor->decrypt((string) $this->getConfigData('api_key'));
        $baseUrl = (string) $this->getConfigData('base_url');
        $paymentId = (string) $order->getExtOrderId();
        if ($paymentId === '') {
            throw new \Magento\Framework\Exception\LocalizedException(__('No Allpaypayz payment id stored on order.'));
        }
        try {
            (new AllpaypayzClient(apiKey: $apiKey, baseUrl: $baseUrl))
                ->payments->createRefund($paymentId, [
                    'amount' => [
                        'amount_minor' => (int) round((float) $amount * 100),
                        'currency'     => $order->getOrderCurrencyCode(),
                    ],
                ]);
        } catch (AllpaypayzException $e) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Allpaypayz refund failed: %1', $e->errorCode));
        }
        return $this;
    }
}
