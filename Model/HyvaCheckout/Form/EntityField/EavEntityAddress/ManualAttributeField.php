<?php

namespace Elgentos\HyvaCheckoutFlektoPostcode\Model\HyvaCheckout\Form\EntityField\EavEntityAddress;

use Hyva\Checkout\Model\Form\EntityField\EavAttributeField;

class ManualAttributeField extends EavAttributeField
{
    public function getFrontendInput(): string
    {
        return 'checkbox';
    }
}
