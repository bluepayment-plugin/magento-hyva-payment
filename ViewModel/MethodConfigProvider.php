<?php

declare(strict_types=1);

namespace BlueMedia\HyvaPayment\ViewModel;

use BlueMedia\BluePayment\Model\ConfigProvider as PaymentConfigProvider;
use BlueMedia\BluePayment\Model\Payment;
use BlueMedia\HyvaPayment\Magewire\Payment\Method\BluePayment as BluePaymentComponent;
use BlueMedia\BluePayment\Model\ResourceModel\Gateway\CollectionFactory as GatewayCollectionFactory;
use BlueMedia\BluePayment\Api\Data\GatewayInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class MethodConfigProvider implements ArgumentInterface
{
    private const ICON_EXTENSIONS = ['svg', 'png', 'webp', 'jpg', 'jpeg', 'gif'];
    private const JSON_HEX_FLAGS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

    private Repository $assetRepository;

    private PaymentConfigProvider $paymentConfigProvider;

    private GatewayCollectionFactory $gatewayCollectionFactory;

    private StoreManagerInterface $storeManager;

    private LoggerInterface $logger;

    private string $modulePath;

    public function __construct(
        Repository $assetRepository,
        PaymentConfigProvider $paymentConfigProvider,
        ComponentRegistrarInterface $componentRegistrar,
        LoggerInterface $logger
    ) {
        $objectManager = ObjectManager::getInstance();

        $this->assetRepository = $assetRepository;
        $this->paymentConfigProvider = $paymentConfigProvider;
        $this->logger = $logger;
        $this->gatewayCollectionFactory = $objectManager->get(GatewayCollectionFactory::class);
        $this->storeManager = $objectManager->get(StoreManagerInterface::class);
        $this->modulePath = (string) $componentRegistrar->getPath(ComponentRegistrar::MODULE, 'BlueMedia_HyvaPayment');
    }

    public function isAutopayMethod(string $methodCode): bool
    {
        return $methodCode === Payment::METHOD_CODE || str_starts_with($methodCode, Payment::SEPARATED_PREFIX_CODE);
    }

    /**
     * @return array<string, mixed>
     */
    public function getGateway($methodBlock): array
    {
        return $this->getGatewayForMethod('', $methodBlock);
    }

    /**
     * @return array<string, mixed>
     */
    public function getGatewayForMethod(string $methodCode, $methodBlock): array
    {
        $methodBlock = $this->normalizeMethodBlock($methodBlock);

        if ($methodBlock !== null) {
            $gateway = $methodBlock->getData('payment_gateway');

            if (is_array($gateway) && !empty($gateway)) {
                return $gateway;
            }
        }

        return $this->resolveGatewayByMethodCode($methodCode);
    }

    public function getMethodIconUrl(string $methodCode, $methodBlock): ?string
    {
        $gateway = $this->getGatewayForMethod($methodCode, $methodBlock);
        if ($gateway) {
            return $this->resolveGatewayIcon($gateway, $methodCode);
        }

        $override = $this->resolveLocalIcon($methodCode);
        if ($override !== null) {
            return $this->assetRepository->getUrl($override);
        }

        if ($methodCode === Payment::METHOD_CODE) {
            return $this->assetRepository->getUrl('BlueMedia_BluePayment::images/logo.svg');
        }

        return null;
    }

    public function getMethodDescription(string $methodCode, $methodBlock): string
    {
        $gateway = $this->getGatewayForMethod($methodCode, $methodBlock);

        if (!empty($gateway['short_description'])) {
            return trim(strip_tags((string) $gateway['short_description']));
        }

        if ($methodCode === Payment::METHOD_CODE) {
            return (string) __('You will be redirected to the page of the selected bank.');
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getBluepaymentTemplateData(BluePaymentComponent $magewire): array
    {
        $config = $magewire->getFrontendConfig();

        if (isset($config['cards']) && is_array($config['cards'])) {
            $config['cards'] = $this->deduplicateCards($config['cards']);
        }

        $gateway = is_array($config['gateway'] ?? null) ? $config['gateway'] : null;
        $methodCode = (string) ($config['payment_method_code'] ?? Payment::METHOD_CODE);
        $gatewayId = $this->toNullableInt($gateway['gateway_id'] ?? null);
        $blikGatewayId = $this->toNullableInt($config['gateways_ids']['blik'] ?? null);
        $oneClickGatewayId = $this->toNullableInt($config['gateways_ids']['one_click'] ?? null);
        $cardGatewayId = $this->toNullableInt($config['gateways_ids']['card'] ?? null);
        $hasGatewayDescription = $gateway !== null && !empty($gateway['description']);
        $hasBlikSection = $gatewayId !== null
            && $blikGatewayId !== null
            && $gatewayId === $blikGatewayId
            && !empty($config['blik_zero_enabled']);
        $hasSavedCardsSection = $gatewayId !== null
            && $oneClickGatewayId !== null
            && $gatewayId === $oneClickGatewayId
            && !empty($config['cards']);
        $hasWidgetSection = !empty($config['iframe_enabled'])
            && $gatewayId !== null
            && (($cardGatewayId !== null && $gatewayId === $cardGatewayId)
                || ($oneClickGatewayId !== null && $gatewayId === $oneClickGatewayId));
        $requiresGatewaySelection = $magewire->requiresGatewaySelection();

        return [
            'config' => $config,
            'gateway' => $gateway,
            'gateways' => $magewire->getGateways(),
            'state' => [
                'accepted_one_click_agreement' => (bool) ($config['accepted_one_click_agreement'] ?? false),
                'has_gateway_description' => $hasGatewayDescription,
                'has_visible_content' => $requiresGatewaySelection
                    || $hasGatewayDescription
                    || $hasBlikSection
                    || $hasSavedCardsSection
                    || $hasWidgetSection,
                'requires_gateway_selection' => $requiresGatewaySelection,
                'selected_card_index' => $this->resolveSelectedCardIndex($config),
                'selected_gateway_id' => $this->resolveSelectedGatewayId($config),
            ],
            'ui' => [
                'blik_input_id' => 'bluepayment-blik-code-' . $this->sanitizeIdentifier($methodCode, '-'),
                'card_input_name' => 'bluepayment_card_index_' . $this->sanitizeIdentifier($methodCode, '_'),
                'config_json' => (string) json_encode($config, self::JSON_HEX_FLAGS),
                'iframe_id' => 'bluepayment-widget-frame-' . $this->sanitizeIdentifier($methodCode, '-'),
                'method_code' => $methodCode,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $gateway
     */
    public function resolveGatewayIcon(array $gateway, string $methodCode = ''): ?string
    {
        $gatewayId = isset($gateway['gateway_id']) ? (string) $gateway['gateway_id'] : '';

        foreach (array_filter([$gatewayId, $methodCode]) as $identifier) {
            $override = $this->resolveLocalIcon($identifier);
            if ($override !== null) {
                return $this->assetRepository->getUrl($override);
            }
        }

        $logoUrl = trim((string) ($gateway['logo_url'] ?? ''));

        return $logoUrl !== '' ? $logoUrl : null;
    }

    private function resolveLocalIcon(string $identifier): ?string
    {
        if ($identifier === '' || $this->modulePath === '') {
            return null;
        }

        foreach (self::ICON_EXTENSIONS as $extension) {
            $relativeFile = 'view/frontend/web/images/payment/' . $identifier . '.' . $extension;
            $absoluteFile = $this->modulePath . DIRECTORY_SEPARATOR . $relativeFile;

            if (is_file($absoluteFile)) {
                return 'BlueMedia_HyvaPayment::images/payment/' . $identifier . '.' . $extension;
            }
        }

        return null;
    }

    private function normalizeMethodBlock($methodBlock): ?Template
    {
        return $methodBlock instanceof Template ? $methodBlock : null;
    }

    /**
     * @param array<int|string, mixed> $cards
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateCards(array $cards): array
    {
        $uniqueCards = [];
        $seenIndexes = [];

        foreach ($cards as $card) {
            if (!is_array($card) || !array_key_exists('index', $card)) {
                continue;
            }

            $cardIndex = (string) $card['index'];

            if (isset($seenIndexes[$cardIndex])) {
                continue;
            }

            $seenIndexes[$cardIndex] = true;
            $uniqueCards[] = $card;
        }

        return $uniqueCards;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveGatewayByMethodCode(string $methodCode): array
    {
        if (!$this->isAutopayMethod($methodCode) || $methodCode === Payment::METHOD_CODE) {
            return [];
        }

        $gatewayId = (int) str_replace(Payment::SEPARATED_PREFIX_CODE, '', $methodCode);

        if ($gatewayId <= 0) {
            return [];
        }

        try {
            $config = $this->paymentConfigProvider->getPaymentConfig();
        } catch (\Throwable $exception) {
            $this->logger->warning(
                sprintf('Unable to resolve Autopay gateway config for method "%s".', $methodCode),
                ['exception' => $exception]
            );

            return [];
        }

        foreach (($config['separated'] ?? []) as $gateway) {
            if (is_array($gateway) && (int) ($gateway['gateway_id'] ?? 0) === $gatewayId) {
                return $gateway;
            }
        }

        return $this->resolveGatewayFromCollection($gatewayId);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveGatewayFromCollection(int $gatewayId): array
    {
        if ($gatewayId <= 0) {
            return [];
        }

        try {
            $collection = $this->gatewayCollectionFactory->create();
            $collection->addFieldToFilter(GatewayInterface::GATEWAY_ID, $gatewayId);
            $collection->setOrder(GatewayInterface::STATUS, 'DESC');
            $collection->setPageSize(1);

            $gateway = $collection->getFirstItem();

            if (!$gateway || !$gateway->getId()) {
                return [];
            }

            $logoUrl = $gateway->shouldUseOwnLogo()
                ? $this->normalizeGatewayLogoLocation($gateway->getLogoPath())
                : $this->normalizeGatewayLogoLocation($gateway->getLogoUrl());

            return [
                'gateway_id' => $gateway->getGatewayId(),
                'name' => $gateway->getName(),
                'description' => $gateway->getDescription(),
                'short_description' => $gateway->getShortDescription(),
                'logo_url' => $logoUrl,
            ];
        } catch (\Throwable $exception) {
            $this->logger->warning(
                sprintf('Unable to resolve Autopay gateway record for gateway "%d".', $gatewayId),
                ['exception' => $exception]
            );
        }

        return [];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveSelectedGatewayId(array $config): ?int
    {
        return $this->toNullableInt($config['fixed_gateway_id'] ?? $config['selected_gateway_id'] ?? null);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveSelectedCardIndex(array $config): ?int
    {
        return $this->toNullableInt($config['selected_card_index'] ?? null);
    }

    private function sanitizeIdentifier(string $value, string $separator): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]+/', $separator, $value) ?? '';
    }

    private function toNullableInt($value): ?int
    {
        return $value !== null && $value !== '' ? (int) $value : null;
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
        } catch (\Throwable) {
            return $logoPath;
        }
    }
}
