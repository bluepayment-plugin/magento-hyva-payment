<?php

declare(strict_types=1);

namespace BlueMedia\HyvaPayment\Plugin;

use BlueMedia\BluePayment\Model\Payment;
use BlueMedia\BluePayment\Observer\DataAssignObserver;
use Hyva\Checkout\Magewire\Checkout\Payment\MethodList;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;

class NormalizeMethodListStatePlugin
{
    private const DEFAULT_METHOD_CODE = '__default';

    private CheckoutSession $checkoutSession;

    private CartRepositoryInterface $cartRepository;

    private PaymentMethodManagementInterface $paymentMethodManagement;

    public function __construct(
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $cartRepository,
        PaymentMethodManagementInterface $paymentMethodManagement
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->cartRepository = $cartRepository;
        $this->paymentMethodManagement = $paymentMethodManagement;
    }

    public function afterBoot(MethodList $subject): void
    {
        $normalized = $this->resolvePreferredMethod($subject->method);

        if ($normalized === $subject->method) {
            return;
        }

        $subject->method = $normalized;
        $this->persistQuoteMethod($normalized);
    }

    public function beforeEvaluateCompletion(MethodList $subject): void
    {
        $normalized = $this->resolvePreferredMethod($subject->method);

        if ($normalized === $subject->method) {
            return;
        }

        $subject->method = $normalized;
        $this->persistQuoteMethod($normalized);
    }

    public function beforeUpdatedMethod(MethodList $subject, string $value): array
    {
        return [$this->normalizeCode($value) ?? $value];
    }

    private function resolvePreferredMethod(?string $method): ?string
    {
        $quote = $this->getActiveQuote();
        $currentMethod = $this->normalizeCode($method);
        $availableMethods = $this->getAvailableMethodCodes($quote ? (int) $quote->getId() : 0);
        $quoteMethod = $quote ? $this->normalizeCode((string) $quote->getPayment()->getMethod()) : null;
        $derivedQuoteMethod = $quote ? $this->deriveFrontendMethodCode($quote) : null;

        if ($currentMethod !== null
            && $currentMethod !== Payment::METHOD_CODE
            && in_array($currentMethod, $availableMethods, true)
        ) {
            return $currentMethod;
        }

        if ($quoteMethod !== null
            && $quoteMethod !== Payment::METHOD_CODE
            && in_array($quoteMethod, $availableMethods, true)
        ) {
            return $quoteMethod;
        }

        if ($currentMethod === Payment::METHOD_CODE && in_array(Payment::METHOD_CODE, $availableMethods, true)) {
            return Payment::METHOD_CODE;
        }

        if ($quoteMethod === Payment::METHOD_CODE && in_array(Payment::METHOD_CODE, $availableMethods, true)) {
            return Payment::METHOD_CODE;
        }

        if ($currentMethod === null
            && $derivedQuoteMethod !== null
            && $derivedQuoteMethod !== Payment::METHOD_CODE
            && in_array($derivedQuoteMethod, $availableMethods, true)
        ) {
            return $derivedQuoteMethod;
        }

        return $currentMethod;
    }

    /**
     * @return string[]
     */
    private function getAvailableMethodCodes(int $quoteId): array
    {
        if ($quoteId <= 0) {
            return [];
        }

        try {
            $methods = $this->paymentMethodManagement->getList($quoteId);
        } catch (LocalizedException) {
            return [];
        }

        $codes = array_map(
            fn ($method): ?string => $this->normalizeCode((string) $method->getCode()),
            $methods
        );

        $codes = array_filter($codes, static fn (?string $code): bool => $code !== null && $code !== '');

        return array_values(array_unique($codes));
    }

    private function normalizeCode(?string $method): ?string
    {
        if (!is_string($method) || $method === '') {
            return null;
        }

        if ($method === self::DEFAULT_METHOD_CODE) {
            return null;
        }

        return $method;
    }

    private function deriveFrontendMethodCode(CartInterface $quote): ?string
    {
        $payment = $quote->getPayment();
        $method = $this->normalizeCode((string) $payment->getMethod());

        if ($method === null) {
            return null;
        }

        if (strpos($method, Payment::SEPARATED_PREFIX_CODE) === 0) {
            return $method;
        }

        if ($method !== Payment::METHOD_CODE) {
            return $method;
        }

        $gatewayId = (int) $payment->getAdditionalInformation(DataAssignObserver::GATEWAY_ID);

        if ($gatewayId <= 0) {
            return Payment::METHOD_CODE;
        }

        return Payment::SEPARATED_PREFIX_CODE . $gatewayId;
    }

    private function persistQuoteMethod(?string $method): void
    {
        if ($method === null) {
            return;
        }

        $quote = $this->getActiveQuote();

        if ($quote === null) {
            return;
        }

        $quote->getPayment()->setMethod($method);
        $this->cartRepository->save($quote);
    }

    private function getActiveQuote(): ?CartInterface
    {
        $quoteId = (int) $this->checkoutSession->getQuoteId();

        if ($quoteId <= 0) {
            try {
                $quoteId = (int) $this->checkoutSession->getQuote()->getId();
            } catch (LocalizedException) {
                return null;
            }
        }

        if ($quoteId <= 0) {
            return null;
        }

        try {
            return $this->cartRepository->getActive($quoteId);
        } catch (LocalizedException) {
            return null;
        }
    }
}
