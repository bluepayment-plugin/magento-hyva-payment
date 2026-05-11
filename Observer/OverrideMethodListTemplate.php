<?php

declare(strict_types=1);

namespace BlueMedia\HyvaPayment\Observer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\Layout;
use Magento\Store\Model\ScopeInterface;

class OverrideMethodListTemplate implements ObserverInterface
{
    private const CONFIG_PATH_OVERRIDE_TEMPLATE = 'payment/bluepayment/hyva/override_template';
    private const METHOD_LIST_BLOCK = 'checkout.payment.methods';
    private const TEMPLATE = 'BlueMedia_HyvaPayment::checkout/payment/method-list.phtml';

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function execute(Observer $observer): void
    {
        if (!$this->scopeConfig->isSetFlag(self::CONFIG_PATH_OVERRIDE_TEMPLATE, ScopeInterface::SCOPE_STORE)) {
            return;
        }

        $layout = $observer->getEvent()->getLayout();

        if (!$layout instanceof Layout) {
            return;
        }

        $block = $layout->getBlock(self::METHOD_LIST_BLOCK);
        if (!$block) {
            return;
        }

        if ($block->getTemplate() !== self::TEMPLATE) {
            $block->setTemplate(self::TEMPLATE);
        }
    }
}
