<?php

declare(strict_types=1);

namespace Elgentos\HyvaCheckoutFlektoPostcode\Model\HyvaCheckout\Form\EntityFormModifier\PostcodeNL;

use Flekto\Postcode\Helper\StoreConfigHelper;
use Flekto\Postcode\Model\Config\Source\NlInputBehavior;
use Flekto\Postcode\Model\PostcodeModel;
use Hyva\Checkout\Magewire\Checkout\AddressView\BillingDetails;
use Hyva\Checkout\Magewire\Checkout\AddressView\MagewireAddressFormInterface;
use Hyva\Checkout\Magewire\Checkout\AddressView\ShippingDetails\AddressForm;
use Hyva\Checkout\Magewire\Checkout\GuestDetails;
use Hyva\Checkout\Model\Form\AbstractEntityForm;
use Hyva\Checkout\Model\Form\EntityField\EavEntityAddress\StreetAttributeField;
use Hyva\Checkout\Model\Form\EntityFormElementInterface;
use Hyva\Checkout\Model\Form\EntityFormInterface;
use Hyva\Checkout\Model\Form\EntityFormModifierInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Magewirephp\Magewire\Component;
use Magento\Customer\Api\Data\AddressInterface;

class WithDutchPostcodeHouseNumberCombination implements EntityFormModifierInterface
{
    private const KEY_MANUAL = 'postcode_manual';

    public function __construct(
        private readonly StoreConfigHelper $configHelper,
        private PostcodeModel $postcodeModel,
        private EvaluationResultFactory $evaluationResultFactory
    ) {
    }

    public function apply(EntityFormInterface $form): EntityFormInterface
    {
        // Only zip code and house number (default)
        $inputBehaviour = $this->configHelper->getValue(StoreConfigHelper::PATH['nl_input_behavior']) ?? NlInputBehavior::ZIP_HOUSE;

        // Check if we need to halt code execution here based on the 'nl_input_behavior' configuration value in the admin settings.
        // By default, Dutch address field behavior is enforced upon installation. However, users have the flexibility to switch to free-form address input.
        // This condition determines whether to revert to default behavior or continue with free-form input, as handled by the `WithFreeInput` class.
        if ($inputBehaviour !== NlInputBehavior::ZIP_HOUSE) {
            return $form;
        }

        $form->registerModificationListener(
            'explodeStreetRows',
            'form:build',
            [$this, 'explodeStreetRows']
        );

        $form->registerModificationListener(
            'initPostcodeCheckFields',
            'form:build',
            [$this, 'initPostcodeCheckFields']
        );
        
        $form->registerModificationListener(
            'manualModeUpdated',
            'form:build:magewire',
            [$this, 'validatePostcode']
        );

        $form->registerModificationListener(
            'postcodenlShippingUpdated',
            'form:updated',
            [$this, 'postcodeCheck']
        );

        $form->registerModificationListener(
            'removeAutoSave',
            'form:build:magewire',
            [$this, 'removeAutoSave']
        );

        $form->registerModificationListener(
            'applyMyShippingPostcodeModifications',
            'form:shipping:country_id:updated',
            function (AbstractEntityForm $form, $formComponent) {
                $countryId = $form->getField(AddressInterface::COUNTRY_ID);

                $postcode = $form->getField(AddressInterface::POSTCODE);
                $street = $form->getField(AddressInterface::STREET);
                $houseNumber = $street->getRelatives()[1] ?? null;
                $addition = $street->getRelatives()[2] ?? null;
                $city = $form->getField(AddressInterface::CITY);

                if(!empty($postcode?->getValue())){
                    $postcode?->setValue('');
                }

                if(!empty($street?->getValue())) {
                    $street?->setValue('');
                }

                if(!empty($houseNumber?->getValue())) {
                    $houseNumber?->setValue('');
                }

                if(!empty($addition?->getValue())) {
                    $addition?->setValue('');
                }

                if(!empty($city?->getValue())) {
                    $city?->setValue('');
                }

                $formComponent->dispatchBrowserEvent('postcode:updated', ['postcode' => $postcode?->getValue(), 'country' => $countryId->getValue()]);

                return $form;
            }
        );

        return $form;
    }

    public function initPostcodeCheckFields(EntityFormInterface $form): void
    {
        $manual = $form->getField(self::KEY_MANUAL);
        $country = $form->getField(AddressInterface::COUNTRY_ID)->getValue();

        $postcode = $form->getField(AddressInterface::POSTCODE);
        $street = $form->getField(AddressInterface::STREET);
        $houseNumber = $street->getRelatives()[1] ?? null;
        $addition = $street->getRelatives()[2] ?? null;
        $city = $form->getField(AddressInterface::CITY);

        $houseNumber?->setAttribute('autocomplete', 'address-line2');
        $addition?->setAttribute('autocomplete', 'address-line3');

        if ($country !== 'NL') {
            if ($manual) {
                $form->removeField($manual);
            }
        } else {
            if ($manual && $manual->getValue()) {
                $street->enable();
                $city->enable();
                $addition?->unsetData('options');
            } else {
                $street->disable();
                $city->disable();
            }
        }

        foreach ([$postcode, $houseNumber, $addition, $manual] as $field) {
            if ($field) {
                $field->setAttribute('@change.capture', '$wire.save()');
            }
        }
    }

    public function validatePostcode(EntityFormInterface $form, $component): ?array
    {
        $countryId = $form->getField(AddressInterface::COUNTRY_ID);
        if ($countryId->getValue() !== 'NL') {
            return null;
        }

        $manual = $form->getField(self::KEY_MANUAL);
        if (!$manual || $manual->getValue()) {
            return null;
        }

        $postcode = $form->getField(AddressInterface::POSTCODE);
        $street = $form->getField(AddressInterface::STREET);
        $housenumber = $street->getRelatives()[1] ?? null;
        $addition = $street->getRelatives()[2] ?? null;

        if (empty($postcode->getValue()) || empty($housenumber->getValue())) {
            return null;
        }

        $postcodeCheck = $this->postcodeModel->getNlAddress($postcode->getValue() ?? '', ($housenumber->getValue() ?? '') . ($addition->getValue() ?? ''));

        if (isset($postcodeCheck[0]['error']) && $postcodeCheck[0]['error']) {
            $error = __("Combination does not exist.");

            if (!$manual->getData('postcode_error')) {
                $manual->setData('postcode_error', $error->render());
            }

            return null;
        }

        if ($postcodeCheck[0]['status'] === 'notFound') {
            $error = __("Combination does not exist.");

            if (!$manual->getData('postcode_error')) {
                $manual->setData('postcode_error', $error->render());
            }

            return null;
        }

        if (isset($postcodeCheck[0]['address']) && !empty($postcodeCheck[0]['address']['houseNumberAdditions'])) {
            $houseNumberAdditionsOptions = [];

            foreach ($postcodeCheck[0]['address']['houseNumberAdditions'] as $additions) {
                $houseNumberAdditionsOptions[$additions["houseNumberAddition"]] = $additions["houseNumberAddition"];
            }

            if (!empty($houseNumberAdditionsOptions)) {
                $addition?->setOptions($houseNumberAdditionsOptions);
            }
        }

        return $postcodeCheck;
    }

    public function removeAutoSave(EntityFormInterface $form): void
    {
        $postcode = $form->getField(AddressInterface::POSTCODE);
        $street = $form->getField(AddressInterface::STREET);
        $housenumber = $street->getRelatives()[1] ?? null;
        $addition = $street->getRelatives()[2] ?? null;

        foreach ([$postcode, $housenumber, $addition] as $field) {
            if ($field) {
                $field->removeAttribute('data-autosave');
            }
        }
    }

    public function postcodeCheck(EntityFormInterface $form, $formComponent): void
    {
        $countryId = $form->getField(AddressInterface::COUNTRY_ID);

        $postcode = $form->getField(AddressInterface::POSTCODE);
        $street = $form->getField(AddressInterface::STREET);
        $city = $form->getField(AddressInterface::CITY);
        $housenumber = $street->getRelatives()[1] ?? null;
        $addition = $street->getRelatives()[2] ?? null;

        if($form->getNamespace() === 'shipping' && !empty($postcode->getValue()) && !empty($housenumber->getValue())) {
            $formComponent->dispatchBrowserEvent('postcode:updated', ['postcode' => $postcode->getValue(), 'country' => $countryId->getValue()]);
        }

        if ($countryId->getValue() !== 'NL') {
            return;
        }

        $manual = $form->getField(self::KEY_MANUAL);
        if (!$manual || $manual->getValue()) {
            return;
        }

        $apiResponse = $this->validatePostcode($form, $formComponent);

        if (!$apiResponse) {
            $street->setValue('');
            $city->setValue('');

            return;
        }

        $postcode->setValue((string) $apiResponse[0]['address']['postcode']);
        $street->setValue((string) $apiResponse[0]['address']['street']);
        $city->setValue((string) $apiResponse[0]['address']['city']);
        $housenumber?->setValue((string) $apiResponse[0]['address']['houseNumber']);
        $addition?->setValue((string) $apiResponse[0]['address']['houseNumberAddition']);

        if($form->getNamespace() === 'shipping') {
            $formComponent->dispatchBrowserEvent('postcode:updated', ['postcode' => $postcode->getValue(), 'country' => $countryId->getValue()]);
        }

        return;
    }

    public function explodeStreetRows(EntityFormInterface $form): void
    {
        $streetField = $form->getField(AddressInterface::STREET);

        foreach ($streetField->getRelatives() as $relative) {
            // Change id so the field can coexist with the original street field
            $relative->setData('id', "{$streetField->getName()}.{$relative->getPosition()}");
            $form->addField($relative);
        }
    }
}
