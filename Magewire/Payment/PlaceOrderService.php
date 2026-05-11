<?php

declare(strict_types=1);

namespace BlueMedia\HyvaPayment\Magewire\Payment;

use BlueMedia\BluePayment\Model\Payment;
use Hyva\Checkout\Model\Magewire\Payment\AbstractPlaceOrderService;

class PlaceOrderService extends AbstractPlaceOrderService
{
    public function canRedirect(): bool
    {
        return false;
    }

    public function canHandle(string $code): bool
    {
        return $code === Payment::METHOD_CODE
            || strpos($code, Payment::SEPARATED_PREFIX_CODE) === 0;
    }
}
