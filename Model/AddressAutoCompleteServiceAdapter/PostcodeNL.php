<?php

declare(strict_types=1);

namespace Elgentos\HyvaCheckoutFlektoPostcode\Model\AddressAutoCompleteServiceAdapter;

use Flekto\Postcode\Helper\ApiClientHelper;
use Flekto\Postcode\Helper\StoreConfigHelper;
use Hyva\Checkout\Model\Form\AbstractEntityForm;
use Hyva\Checkout\Model\Form\EntityFormModifierInterface;
use Hyva\CheckoutAutoComplete\Model\AddressAutoCompleteServiceAdapter\AbstractServiceAdapter;

class PostcodeNL extends AbstractServiceAdapter
{
    private ApiClientHelper $apiClientHelper;
    private StoreConfigHelper $storeConfigHelperPostcodeNL;
    private array $entityFormModifiers;

    public function __construct(
        ApiClientHelper $apiClientHelper,
        StoreConfigHelper $storeConfigHelperPostcodeNL,
        array $entityFormModifiers = []
    ) {
        $this->apiClientHelper = $apiClientHelper;
        $this->storeConfigHelperPostcodeNL = $storeConfigHelperPostcodeNL;
        $this->entityFormModifiers = $entityFormModifiers;
    }

    public function getServiceName(): string
    {
        return 'Flekto Postcode.eu';
    }

    public function accessServiceApi(): ApiClientHelper
    {
        return $this->apiClientHelper;
    }

    public function canApplyEntityFormModifications(): bool
    {
        return $this->storeConfigHelperPostcodeNL->hasCredentials();
    }

    public function modifyEntityForm(AbstractEntityForm $form): void
    {
        /** @var EntityFormModifierInterface $modifier */
        foreach ($this->entityFormModifiers as $modifier) {
            $modifier->apply($form);
        }
    }
}
