<?php

declare(strict_types=1);

namespace BlueMedia\HyvaPayment\Magewire\Payment\Method;

use BlueMedia\BluePayment\Model\ConfigProvider;
use BlueMedia\BluePayment\Model\Payment;
use BlueMedia\BluePayment\Observer\DataAssignObserver;
use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magewirephp\Magewire\Component\Form;
use Rakit\Validation\Validator;

class BluePayment extends Form implements EvaluationInterface
{
    private const CARD_INDEX = 'card_index';
    private const BLIK_CODE = 'blik_code';
    private const ONE_CLICK_AGREEMENT = 'one_click_agreement';

    public ?int $selectedGatewayId = null;

    public string $blikCode = '';

    /** @var string[] */
    public array $selectedAgreementIds = [];

    /** @var string[] */
    public array $requiredAgreementIds = [];

    public ?int $selectedCardIndex = null;

    public bool $acceptedOneClickAgreement = false;

    private CheckoutSession $checkoutSession;

    private CartRepositoryInterface $quoteRepository;

    private ConfigProvider $configProvider;

    private UrlInterface $urlBuilder;

    private LayoutInterface $layout;

    /** @var array<string, mixed>|null */
    private ?array $frontendConfig = null;

    /** @var array<string, mixed>|null */
    private ?array $gateway = null;

    private ?string $paymentMethodCode = null;

    public function __construct(
        Validator $validator,
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        ConfigProvider $configProvider,
        UrlInterface $urlBuilder,
        LayoutInterface $layout
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->configProvider = $configProvider;
        $this->urlBuilder = $urlBuilder;
        $this->layout = $layout;

        parent::__construct($validator);
    }

    public function mount(): void
    {
        $this->initializeContext();

        $payment = $this->checkoutSession->getQuote()->getPayment();
        $selectedGatewayId = $this->normalizeNullableInt(
            $payment->getAdditionalInformation(DataAssignObserver::GATEWAY_ID)
        );

        $this->selectedGatewayId = $this->isSeparatedMethod()
            ? $this->getConfiguredGatewayId()
            : $selectedGatewayId;

        if (!$this->isSeparatedMethod()
            && $this->selectedGatewayId !== null
            && !$this->hasGateway($this->selectedGatewayId)
        ) {
            $this->selectedGatewayId = null;
        }

        $this->selectedAgreementIds = $this->normalizeIdList(
            $payment->getAdditionalInformation(DataAssignObserver::AGREEMENTS_IDS)
        );
        $this->blikCode = $this->normalizeBlikCode($payment->getAdditionalInformation(self::BLIK_CODE));
        $this->selectedCardIndex = $this->normalizeNullableInt(
            $payment->getAdditionalInformation(self::CARD_INDEX)
        );
        $this->acceptedOneClickAgreement = (bool) $payment->getAdditionalInformation(self::ONE_CLICK_AGREEMENT);
    }

    /**
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function getFrontendConfig(): array
    {
        if ($this->frontendConfig !== null) {
            return $this->frontendConfig;
        }

        $this->initializeContext();
        $paymentConfig = $this->sanitizeValue($this->configProvider->getPaymentConfig());
        $gateway = $this->getGateway();

        $this->frontendConfig = array_merge($paymentConfig, [
            'create_url' => $this->urlBuilder->getUrl('bluepayment/processing/create'),
            'agreements_url' => $this->urlBuilder->getUrl('bluepayment/processing/agreements'),
            'google_pay_url' => $this->urlBuilder->getUrl('bluepayment/processing/googlepay'),
            'payment_status_url' => $this->urlBuilder->getUrl('bluepayment/processing/paymentstatus'),
            'back_url' => $this->urlBuilder->getUrl('bluepayment/processing/back'),
            'gateways_ids' => [
                'blik' => ConfigProvider::BLIK_GATEWAY_ID,
                'blik_bnpl' => ConfigProvider::BLIK_BNPL_GATEWAY_ID,
                'card' => ConfigProvider::CARD_GATEWAY_ID,
                'one_click' => ConfigProvider::ONECLICK_GATEWAY_ID,
                'google_pay' => ConfigProvider::GPAY_GATEWAY_ID,
                'apple_pay' => ConfigProvider::APPLE_PAY_GATEWAY_ID,
            ],
            'selected_gateway_id' => $this->selectedGatewayId,
            'selected_agreement_ids' => $this->selectedAgreementIds,
            'selected_card_index' => $this->selectedCardIndex,
            'accepted_one_click_agreement' => $this->acceptedOneClickAgreement,
            'blik_code' => $this->blikCode,
            'grand_total' => number_format((float) $this->checkoutSession->getQuote()->getGrandTotal(), 2, '.', ''),
            'currency_code' => $this->checkoutSession->getQuote()->getQuoteCurrencyCode(),
            'payment_method_code' => $this->getPaymentMethodCode(),
            'fixed_gateway_id' => $this->isSeparatedMethod() ? $this->getConfiguredGatewayId() : null,
            'gateway' => $gateway,
        ]);

        return $this->frontendConfig;
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws LocalizedException
     */
    public function getGateways(): array
    {
        if ($this->isSeparatedMethod()) {
            return [];
        }

        $config = $this->getFrontendConfig();
        $options = $config['options'] ?? [];

        return is_array($options) ? array_values(array_filter($options, 'is_array')) : [];
    }

    /**
     * @throws LocalizedException
     */
    public function requiresGatewaySelection(): bool
    {
        return !$this->isSeparatedMethod() && count($this->getGateways()) > 0;
    }

    /**
     * @throws LocalizedException
     */
    public function hasGateway(int $gatewayId): bool
    {
        if ($this->isSeparatedMethod()) {
            return $this->getConfiguredGatewayId() === $gatewayId;
        }

        foreach ($this->getGateways() as $gateway) {
            if ((int) ($gateway['gateway_id'] ?? 0) === $gatewayId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws LocalizedException
     */
    public function hasOneClickAgreement(): bool
    {
        return trim((string) ($this->getFrontendConfig()['one_click_agreement'] ?? '')) !== '';
    }

    /**
     * @param int|string|null $gatewayId
     */
    public function selectGateway($gatewayId): void
    {
        if ($this->isSeparatedMethod()) {
            $this->selectedGatewayId = $this->getConfiguredGatewayId();
            $this->persistState();

            return;
        }

        $this->selectedGatewayId = $this->normalizeNullableInt($gatewayId);
        $this->selectedAgreementIds = [];
        $this->requiredAgreementIds = [];

        if ($this->selectedGatewayId !== ConfigProvider::BLIK_GATEWAY_ID) {
            $this->blikCode = '';
        }

        if ($this->selectedGatewayId !== ConfigProvider::ONECLICK_GATEWAY_ID) {
            $this->selectedCardIndex = null;
            $this->acceptedOneClickAgreement = false;
        }

        $this->persistState();
    }

    /**
     * @param array<int|string, mixed> $selectedAgreementIds
     * @param array<int|string, mixed> $requiredAgreementIds
     */
    public function syncAgreements(array $selectedAgreementIds = [], array $requiredAgreementIds = []): void
    {
        $this->selectedAgreementIds = $this->normalizeIdList($selectedAgreementIds);
        $this->requiredAgreementIds = $this->normalizeIdList($requiredAgreementIds);

        $this->persistState();
    }

    public function setBlikCode(string $blikCode): void
    {
        $this->blikCode = $this->normalizeBlikCode($blikCode);
        $this->persistState();
    }

    /**
     * @param int|string|null $cardIndex
     */
    public function setSelectedCardIndex($cardIndex): void
    {
        $this->selectedCardIndex = $this->normalizeNullableInt($cardIndex);
        $this->persistState();
    }

    /**
     * @param bool|string|int|null $accepted
     */
    public function setAcceptedOneClickAgreement($accepted): void
    {
        $this->acceptedOneClickAgreement = filter_var($accepted, FILTER_VALIDATE_BOOLEAN);
        $this->persistState();
    }

    /**
     * @throws LocalizedException
     */
    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        if ($this->checkoutSession->getQuote()->getPayment()->getMethod() !== $this->getPaymentMethodCode()) {
            return $resultFactory->createSuccess();
        }

        $gatewayId = $this->getActiveGatewayId();

        if ($this->requiresGatewaySelection() && $gatewayId === null) {
            return $resultFactory->createErrorMessageEvent()
                ->withMessage(__('Choose the way you want to pay.')->render())
                ->withCustomEvent('payment:method:error');
        }

        if ($gatewayId !== null && !$this->hasGateway($gatewayId)) {
            return $resultFactory->createErrorMessageEvent()
                ->withMessage(__('The selected payment channel is no longer available.')->render())
                ->withCustomEvent('payment:method:error');
        }

        if ($gatewayId === ConfigProvider::BLIK_GATEWAY_ID
            && !empty($this->getFrontendConfig()['blik_zero_enabled'])
            && strlen($this->blikCode) !== 6
        ) {
            return $resultFactory->createErrorMessageEvent()
                ->withMessage(__('Invalid BLIK code.')->render())
                ->withCustomEvent('payment:method:error');
        }

        if ($gatewayId === ConfigProvider::ONECLICK_GATEWAY_ID) {
            if ($this->selectedCardIndex === null) {
                return $resultFactory->createErrorMessageEvent()
                    ->withMessage(__('You have to select card.')->render())
                    ->withCustomEvent('payment:method:error');
            }

            if ($this->selectedCardIndex === -1
                && $this->hasOneClickAgreement()
                && !$this->acceptedOneClickAgreement
            ) {
                return $resultFactory->createErrorMessageEvent()
                    ->withMessage(__('You have to agree with terms.')->render())
                    ->withCustomEvent('payment:method:error');
            }
        }

        $this->persistState();

        return $resultFactory->createSuccess();
    }

    private function persistState(): void
    {
        $this->initializeContext();

        $quote = $this->checkoutSession->getQuote();
        $payment = $quote->getPayment();
        $gatewayId = $this->getActiveGatewayId();

        $payment->setMethod($this->getPaymentMethodCode());
        $payment->setAdditionalInformation(DataAssignObserver::GATEWAY_ID, $gatewayId);
        $payment->setAdditionalInformation(
            DataAssignObserver::AGREEMENTS_IDS,
            $this->selectedAgreementIds ? implode(',', $this->selectedAgreementIds) : null
        );
        $payment->setAdditionalInformation(self::BLIK_CODE, $this->blikCode ?: null);
        $payment->setAdditionalInformation(self::CARD_INDEX, $this->selectedCardIndex);
        $payment->setAdditionalInformation(self::ONE_CLICK_AGREEMENT, $this->acceptedOneClickAgreement ? 1 : 0);

        $this->quoteRepository->save($quote);
        $this->frontendConfig = null;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeIdList($value): array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $value = array_map(
            static fn ($item): string => (string) $item,
            array_filter($value, static fn ($item): bool => $item !== null && $item !== '')
        );

        return array_values(array_unique($value));
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param mixed $value
     */
    private function normalizeBlikCode($value): string
    {
        $value = preg_replace('/\D+/', '', (string) $value) ?? '';

        return substr($value, 0, 6);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeValue($value)
    {
        if ($value instanceof Phrase) {
            return (string) $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->sanitizeValue($item);
            }
        }

        return $value;
    }

    private function initializeContext(): void
    {
        if ($this->paymentMethodCode !== null) {
            return;
        }

        $block = $this->layout->getBlock($this->getName());
        $this->paymentMethodCode = (string) ($block ? $block->getData('payment_method_code') : Payment::METHOD_CODE)
            ?: Payment::METHOD_CODE;

        $gateway = $block ? $block->getData('payment_gateway') : null;
        $this->gateway = is_array($gateway) ? $gateway : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getGateway(): ?array
    {
        $this->initializeContext();

        return $this->gateway;
    }

    private function getPaymentMethodCode(): string
    {
        $this->initializeContext();

        return $this->paymentMethodCode ?: Payment::METHOD_CODE;
    }

    private function isSeparatedMethod(): bool
    {
        return $this->getConfiguredGatewayId() !== null;
    }

    private function getConfiguredGatewayId(): ?int
    {
        $gateway = $this->getGateway();

        if (!is_array($gateway) || !isset($gateway['gateway_id'])) {
            return null;
        }

        return (int) $gateway['gateway_id'];
    }

    private function getActiveGatewayId(): ?int
    {
        return $this->isSeparatedMethod() ? $this->getConfiguredGatewayId() : $this->selectedGatewayId;
    }
}
