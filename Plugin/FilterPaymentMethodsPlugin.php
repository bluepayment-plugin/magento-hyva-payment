<?php

declare(strict_types=1);

namespace BlueMedia\HyvaPayment\Plugin;

use Hyva\Checkout\ViewModel\Checkout\Payment\MethodList;
use Magento\Payment\Model\MethodInterface;

class FilterPaymentMethodsPlugin
{
    /**
     * @param MethodInterface[]|null $result
     * @return MethodInterface[]|null
     */
    public function afterGetList(MethodList $subject, ?array $result): ?array
    {
        return $result;
    }
}
