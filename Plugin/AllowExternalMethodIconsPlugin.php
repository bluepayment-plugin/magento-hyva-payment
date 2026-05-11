<?php

declare(strict_types=1);

namespace BlueMedia\HyvaPayment\Plugin;

use Hyva\Checkout\Model\MethodMetaData\IconRenderer;
use Magento\Framework\Escaper;

class AllowExternalMethodIconsPlugin
{
    private Escaper $escaper;

    public function __construct(Escaper $escaper)
    {
        $this->escaper = $escaper;
    }

    /**
     * @param array<string, scalar|null> $attributes
     */
    public function aroundRenderAsImage(
        IconRenderer $subject,
        callable $proceed,
        string $url,
        array $attributes = []
    ): string {
        if (!$this->isExternalUrl($url)) {
            return $proceed($url, $attributes);
        }

        $html = '<img src="' . $this->escaper->escapeUrl($url) . '"';

        foreach ($attributes as $name => $value) {
            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            $html .= ' '
                . $this->escaper->escapeHtml((string) $name)
                . '="'
                . $this->escaper->escapeHtmlAttr((string) ($value ?? ''))
                . '"';
        }

        $html .= '/>';

        return $html;
    }

    private function isExternalUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        return preg_match('#^(?:https?:)?//#i', $url) === 1
            || str_starts_with($url, 'data:');
    }
}
