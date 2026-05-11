<?php

declare(strict_types=1);

namespace BlueMedia\HyvaPayment\Observer;

use BlueMedia\BluePayment\Model\ConfigProvider;
use BlueMedia\BluePayment\Model\Gateway;
use BlueMedia\BluePayment\Model\Payment;
use BlueMedia\HyvaPayment\Magewire\Payment\Method\BluePaymentFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Layout;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class LayoutGenerateBlocksAfter implements ObserverInterface
{
    private const PARENT_BLOCK = 'checkout.payment.methods';
    private const BASE_BLOCK = 'checkout.payment.method.bluepayment';
    private const TEMPLATE_BLOCK = 'checkout.payment.method.bluepayment.separated.template';
    private const ICON_CLASS = 'autopay-hyva-fallback-icon';

    private ConfigProvider $configProvider;

    private BluePaymentFactory $bluePaymentFactory;

    private StoreManagerInterface $storeManager;

    private LoggerInterface $logger;

    public function __construct(
        ConfigProvider $configProvider,
        BluePaymentFactory $bluePaymentFactory,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->configProvider = $configProvider;
        $this->bluePaymentFactory = $bluePaymentFactory;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        $layout = $observer->getEvent()->getLayout();

        if (!$layout instanceof Layout) {
            return;
        }

        $parentBlock = $layout->getBlock(self::PARENT_BLOCK);
        $templateBlock = $layout->getBlock(self::TEMPLATE_BLOCK);

        if (!$parentBlock || !$templateBlock) {
            return;
        }

        try {
            $separatedGateways = $this->configProvider->getSeparatedGateways();

            $baseBlock = $layout->getBlock(self::BASE_BLOCK);
            if ($baseBlock) {
                $baseBlock->setData('payment_method_code', Payment::METHOD_CODE);
                $baseBlock->setData('metadata', $this->buildMetadata([], Payment::METHOD_CODE));
            }

            if (!is_array($separatedGateways)) {
                return;
            }

            $templateClass = get_class($templateBlock);
            $templateData = $templateBlock->getData();
            unset($templateData['magewire']);

            foreach ($separatedGateways as $gateway) {
                $gatewayData = $this->prepareGatewayData($gateway);

                if ($gatewayData === [] || empty($gatewayData['gateway_id'])) {
                    continue;
                }

                $methodCode = Payment::SEPARATED_PREFIX_CODE . (int) $gatewayData['gateway_id'];
                $blockName = 'checkout.payment.method.' . $methodCode;
                $block = $layout->getBlock($blockName);

                if (!$block) {
                    $block = $layout->createBlock($templateClass, $blockName, ['data' => $templateData]);
                    $block->setTemplate($templateBlock->getTemplate());
                    $parentBlock->setChild($methodCode, $block);
                }

                $block->setData('magewire', $this->bluePaymentFactory->create());
                $block->setData('payment_gateway', $gatewayData);
                $block->setData('payment_method_code', $methodCode);
                $block->setData('metadata', $this->buildMetadata($gatewayData, $methodCode));
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to generate Autopay Hyva payment blocks: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $gateway
     * @return array<string, mixed>
     */
    private function buildMetadata(array $gateway, string $methodCode): array
    {
        $metadata = [];
        $icon = $this->resolveIcon($gateway, $methodCode);
        $iconModifier = $this->resolveIconModifier($methodCode);
        $gatewayId = (int) ($gateway['gateway_id'] ?? 0);
        $iconClass = self::ICON_CLASS . ' ' . self::ICON_CLASS . '--' . $iconModifier;

        if ($gatewayId > 0) {
            $iconClass .= ' ' . self::ICON_CLASS . '--gateway-id-' . $gatewayId;
        }

        if ($icon !== null) {
            $metadata['icon'] = [
                'src' => $icon,
                'attributes' => [
                    'alt' => (string) ($gateway['name'] ?? 'Autopay'),
                    'class' => $iconClass,
                    'width' => '88',
                    'height' => '32',
                    'loading' => 'eager',
                    'decoding' => 'async',
                ],
            ];
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $gateway
     */
    private function resolveIcon(array $gateway, string $methodCode): ?string
    {
        return match (true) {
            $methodCode === Payment::METHOD_CODE => 'BlueMedia_HyvaPayment::images/payment/bluepayment.svg',
            default => $this->resolveGatewayIcon($gateway),
        };
    }

    /**
     * @param array<string, mixed> $gateway
     */
    private function resolveGatewayIcon(array $gateway): ?string
    {
        $resolved = trim((string) ($gateway['logo_url'] ?? ''));

        if ($resolved === '') {
            return null;
        }

        return $resolved;
    }

    private function resolveIconModifier(string $methodCode): string
    {
        return $methodCode === Payment::METHOD_CODE ? 'base' : 'gateway';
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareGatewayData($gateway): array
    {
        if (is_array($gateway)) {
            return $gateway;
        }

        if (!$gateway instanceof Gateway) {
            return [];
        }

        $logoUrl = $gateway->shouldUseOwnLogo()
            ? $this->normalizeGatewayLogoLocation((string) $gateway->getLogoPath())
            : $this->normalizeGatewayLogoLocation((string) $gateway->getLogoUrl());

        return [
            'gateway_id' => $gateway->getGatewayId(),
            'name' => $gateway->getName(),
            'bank' => $gateway->getBankName(),
            'short_description' => $gateway->getShortDescription(),
            'description' => $gateway->getDescription(),
            'sort_order' => $gateway->getSortOrder(),
            'type' => $gateway->getType(),
            'logo_url' => $logoUrl,
            'is_separated_method' => $gateway->isSeparatedMethod(),
        ];
    }

    private function normalizeGatewayLogoLocation(string $logoPath): string
    {
        $logoPath = trim($logoPath);

        if ($logoPath === '') {
            return '';
        }

        if (preg_match('#^(?:https?:)?//#i', $logoPath) || str_starts_with($logoPath, 'data:')) {
            return $logoPath;
        }

        $normalizedPath = ltrim($logoPath, '/');

        if (str_starts_with($normalizedPath, 'pub/media/')) {
            $normalizedPath = substr($normalizedPath, strlen('pub/media/'));
        }

        try {
            $store = $this->storeManager->getStore();

            if (str_starts_with($normalizedPath, 'media/')) {
                return $store->getBaseUrl(UrlInterface::URL_TYPE_WEB) . $normalizedPath;
            }

            return $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . $normalizedPath;
        } catch (NoSuchEntityException) {
            return $logoPath;
        }
    }
}
