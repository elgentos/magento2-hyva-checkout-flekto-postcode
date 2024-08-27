<?php

declare(strict_types=1);

namespace Elgentos\HyvaCheckoutFlektoPostcode\ViewModel;

use Flekto\Postcode\Helper\StoreConfigHelper;
use Flekto\Postcode\Model\Config\Source\NlInputBehavior;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class AutoComplete implements ArgumentInterface
{
    public const ADDRESS_AUTO_COMPLETE_SERVICE_ADAPTER = 'hyva_themes_checkout/developer/address_auto_complete/service_adapter';

    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    public function isPostcodeNLEnabled(): bool
    {
        return $this->scopeConfig->getValue(
            self::ADDRESS_AUTO_COMPLETE_SERVICE_ADAPTER,
            ScopeInterface::SCOPE_STORE
        ) === 'postcode_nl';
    }

    public function isDutchPostCodeHouseNumberEnabled(): bool
    {
        return (string)$this->scopeConfig->getValue(StoreConfigHelper::PATH['nl_input_behavior'], ScopeInterface::SCOPE_STORE) === NlInputBehavior::ZIP_HOUSE;
    }
}
