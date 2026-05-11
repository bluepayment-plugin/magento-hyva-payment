<?php

declare(strict_types=1);

namespace BlueMedia\HyvaPayment\Observer;

use BlueMedia\BluePayment\Model\Payment;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\CartRepositoryInterface;

class NormalizeQuotePaymentMethod implements ObserverInterface
{
    private CheckoutSession $checkoutSession;

    private CartRepositoryInterface $cartRepository;

    public function __construct(
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $cartRepository
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->cartRepository = $cartRepository;
    }

    public function execute(Observer $observer): void
    {
        $quote = $this->checkoutSession->getQuote();
        $method = (string) $quote->getPayment()->getMethod();

        if (strpos($method, Payment::SEPARATED_PREFIX_CODE) !== 0) {
            return;
        }

        $quote->getPayment()->setMethod(Payment::METHOD_CODE);
        $this->cartRepository->save($quote);
    }
}
