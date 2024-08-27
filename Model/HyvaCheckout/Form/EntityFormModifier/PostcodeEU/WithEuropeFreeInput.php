<?php

declare(strict_types=1);

namespace Elgentos\HyvaCheckoutFlektoPostcode\Model\HyvaCheckout\Form\EntityFormModifier\PostcodeEU;

use Flekto\Postcode\Helper\StoreConfigHelper;
use Flekto\Postcode\Model\Config\Source\NlInputBehavior;
use Hyva\Checkout\Exception\FormException;
use Hyva\Checkout\Model\AvailableRegions;
use Hyva\Checkout\Model\Form\AbstractEntityForm;
use Hyva\Checkout\Model\Form\EntityField\EavEntityAddress\StreetAttributeField;
use Hyva\Checkout\Model\Form\EntityFieldInterface;
use Hyva\Checkout\Model\Form\EntityFormElementInterface;
use Hyva\Checkout\Model\Form\EntityFormInterface;
use Hyva\Checkout\Model\Form\EntityFormModifierInterface;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Customer\Api\Data\AddressInterface;

class WithEuropeFreeInput implements EntityFormModifierInterface
{
    public const AAC_MANUAL_COMPLETION = 'aac_manual_completion';
    public const AAC_QUERY = 'aac_query';

    private StoreConfigHelper $configHelper;
    private SessionCheckout $sessionCheckout;
    private AvailableRegions $availableRegions;

    public function __construct(
        StoreConfigHelper $configHelper,
        SessionCheckout $sessionCheckout,
        AvailableRegions $availableRegions
    ) {
        $this->configHelper = $configHelper;
        $this->sessionCheckout = $sessionCheckout;
        $this->availableRegions = $availableRegions;
    }

    /**
     * @throws FormException
     */
    public function apply(EntityFormInterface $form): EntityFormInterface
    {
        $form->registerModificationListener(
            "postcode.eu.form.boot",
            'form:boot',
            function (AbstractEntityForm $form) {
                $manualCompletionField = $form->getField(self::AAC_MANUAL_COMPLETION);
                $manualCompletionField->setValue($this->sessionCheckout->getManualCompletionToggle());

                $this->hideAutoComplete($form);
                $this->toggleFields($form);

                return $form;
            }
        );

        $form->registerModificationListener(
            'postcode.eu.form.populate',
            'form:populate',
            function (AbstractEntityForm $form) {
                $country = $form->getField('country_id');
                $countrySortOrder = $country->getSortOrder();

                $form->addField(
                    $form->createField(
                        self::AAC_QUERY,
                        'text',
                        [
                            'data' => [
                                'position' => $countrySortOrder,
                                'label' => 'Address',
                                'is_auto_save' => false,
                                'auto_complete' => 'off',
                                'is_required' => false
                            ]
                        ]
                    )
                );

                $form->addField(
                    $form->createField(
                        self::AAC_MANUAL_COMPLETION,
                        'checkbox',
                        [
                            'data' => [
                                'position' => $form->getField(self::AAC_QUERY)->getSortOrder(),
                                'label' => __('Manually enter address')->render(),
                                'is_auto_save' => false,
                                'class_element' => ['cursor-pointer']
                            ]
                        ]
                    )
                );

                return $form;
            }
        );

        $form->registerModificationListener(
            "postcode.eu.build.magewire",
            'form:build:magewire',
            function (AbstractEntityForm $form) {
                $checkbox = $form->getField(self::AAC_MANUAL_COMPLETION);

                if ($checkbox) {
                    $checkbox->replaceAttribute('wire:model.defer', 'wire:model');
                }

                $this->hideAutoComplete($form);
                $this->toggleFieldAndRelativesClasses($form);

                return $form;
            }
        );

        $form->registerModificationListener(
            'postcode.eu.form.updated',
            'form:updated',
            function (AbstractEntityForm $form, $formComponent) {
                $manualCompletionField = $form->getField(self::AAC_MANUAL_COMPLETION);
                $manualCompletionValue = $manualCompletionField->getValue();

                if ($manualCompletionValue !== $this->sessionCheckout->getManualCompletionToggle()) {
                    $this->sessionCheckout->setManualCompletionToggle($manualCompletionValue);
                }

                if($form->getNamespace() === 'shipping') {
                    $countryId = $form->getField(AddressInterface::COUNTRY_ID);
                    $postcode = $form->getField(AddressInterface::POSTCODE);

                    $formComponent->dispatchBrowserEvent('postcode:updated', ['postcode' => $postcode->getValue(), 'country' => $countryId->getValue()]);
                }

                // Map & update the address details only when the manual completion is disabled.
                if (!$manualCompletionValue) {
                    $addressQuery = json_decode((string)$form->getField(self::AAC_QUERY)->getValue());

                    if ($addressQuery) {
                        $this->mapAddressDetailsFormFields($form, $addressQuery);
                    }
                }

                $this->hideAutoComplete($form);
                $this->toggleFields($form);

                return $form;
            }
        );

        return $form;
    }

    private function mapAddressDetailsFormFields(AbstractEntityForm $form, $addressValue): void
    {
        $countryId = $form->getField(AddressInterface::COUNTRY_ID);

        if ($countryId->getValue() === 'NL') {
            return;
        }

        // Validate input and set values if valid
        if ($addressValue) {
            // Check if street_number property is set before accessing it

            // If there is a region select field present, We need to map the String coming from the PostcodeNL API to the Option value Int.
            $regionOptions = $this->getRegionOptions($form);

            if ($regionOptions) {
                $addressValue->province = $this->mapRegionToId($addressValue->province, $regionOptions);
            }

            $streetField = $form->getField(AddressInterface::STREET);

            $houseNumberField = $streetField->getRelatives()[1] ?? null;
            $additionField = $streetField->getRelatives()[2] ?? null;

            $houseNumberField?->setValue((string)$addressValue->buildingNumber);
            $additionField?->setValue((string)$addressValue->buildingNumberAddition);

            // Set field values based on JSON properties
            foreach ($this->getAddressFieldsMapping() as $field => $property) {
                // Check if the property exists before accessing it
                if ($form->hasField($field)) {
                    $form->getField($field)->setValue($addressValue->$property ?? '');
                }
            }
        }
    }

    private function isCountryEnabled(string $countryCode, string $inputBehaviour): bool
    {
        // Get the list of enabled countries
        $enabledCountries = $this->configHelper->getEnabledCountries();

        // If the input behavior is ZIP_HOUSE, remove NL (Netherlands) from the list
        if ($inputBehaviour === NlInputBehavior::ZIP_HOUSE) {
            $enabledCountries = array_diff($enabledCountries, ['NL']);
        }

        return in_array($countryCode, $enabledCountries);
    }

    private function toggleFields(AbstractEntityForm $form): void
    {
        $manualCompletionToggle = $this->sessionCheckout->getManualCompletionToggle();
        $fieldsToToggle = [
            self::AAC_QUERY => !$manualCompletionToggle,
        ];

        foreach ($this->getFieldsToToggle() as $fieldToToggle) {
            $fieldsToToggle[$fieldToToggle] = $manualCompletionToggle;
        }

        foreach ($fieldsToToggle as $fieldName => $toggle) {
            if ($form->hasField($fieldName)) {
                /** @var EntityFieldInterface $field */
                $field = $form->getField($fieldName);
                $toggle ? $field->enable() : $field->disable();
                $this->toggleFieldRelatives($field, $toggle);
            }
        }
    }

    private function toggleFieldRelatives(EntityFieldInterface $field, ?bool $toggle): void
    {
        $relatives = $field->getRelatives();
        foreach ($relatives as $relative) {
            $toggle ? $relative->enable() : $relative->disable();
        }
    }

    private function hideAutoComplete(AbstractEntityForm $form): void
    {
        // Get country value and input behavior configuration
        $countryCode = $form->getField(AddressInterface::COUNTRY_ID)->getValue();
        $inputBehaviour = $this->configHelper->getValue(StoreConfigHelper::PATH['nl_input_behavior']) ?? NlInputBehavior::ZIP_HOUSE;

        // Determine enabled status of the country
        $isCountryEnabled = $this->isCountryEnabled($countryCode, $inputBehaviour);

        if (!$isCountryEnabled) {
            $form->getField(self::AAC_QUERY)->hide();
            $form->getField(self::AAC_MANUAL_COMPLETION)->hide();
            $this->sessionCheckout->setManualCompletionToggle(true);
        } else {
            $form->getField(self::AAC_QUERY)->show();
            $form->getField(self::AAC_MANUAL_COMPLETION)->show();
        }
    }

    private function getRegionOptions(AbstractEntityForm $form): ?array
    {
        $countryField = $form->getField(AddressInterface::COUNTRY_ID);
        $regionField = $form->getField(AddressInterface::REGION);

        if (!$regionField || !$countryField || $countryField->getValue() === null) {
            return [];
        }

        $availableRegionOptions = $this->availableRegions->getAvailableRegions((string) $countryField->getValue());

        if ($availableRegionOptions === null || count($availableRegionOptions) === 0) {
            return [];
        }

        return (array_map(
            fn($region) => [
            'value' => $region->getId(),
            'label' => $region->getName()
            ],
            $availableRegionOptions
        ));
    }

    private function mapRegionToId(string $region, array $regionOptions): int
    {
        // Convert region name to lowercase for case-insensitive comparison
        $regionLower = strtolower($region);

        // Loop through region options
        foreach ($regionOptions as $option) {
            $optionLower = strtolower($option['label']);
            if ($regionLower === $optionLower) {
                return $option['value'];
            }
        }

        return 0;
    }

    private function getAddressFieldsMapping(): array
    {
        return [
            AddressInterface::STREET => 'street',
            AddressInterface::POSTCODE => 'postcode',
            AddressInterface::CITY => 'locality',
            AddressInterface::REGION => 'province',
            AddressInterface::COMPANY => 'company'
        ];
    }

    private function getFieldsToToggle(): array
    {
        return [
            AddressInterface::STREET,
            AddressInterface::POSTCODE,
            AddressInterface::CITY,
            AddressInterface::REGION
        ];
    }

    private function toggleFieldAndRelativesClasses(AbstractEntityForm $form): void
    {
        $manualCompletionToggle = $this->sessionCheckout->getManualCompletionToggle();
        $fieldsToToggle = [
            self::AAC_QUERY => !$manualCompletionToggle,
        ];

        foreach ($this->getFieldsToToggle() as $fieldToToggle) {
            $fieldsToToggle[$fieldToToggle] = $manualCompletionToggle;
        }

        foreach ($fieldsToToggle as $fieldName => $toggle) {
            if ($form->hasField($fieldName)) {
                /** @var EntityFieldInterface $field */
                $field = $form->getField($fieldName);
                $this->toggleClasses($field, $toggle);
                $relatives = $field->getRelatives();
                foreach ($relatives as $relative) {
                    $this->toggleClasses($relative, $toggle);
                }
            }
        }
    }

    private function toggleClasses(EntityFieldInterface $element, ?bool $toggle): void
    {
        $existingClasses = $element->getData(EntityFormElementInterface::CLASS_ELEMENT);
        $newClasses = $toggle ? [] : ['opacity-50'];

        $element->setData(EntityFormElementInterface::CLASS_ELEMENT, is_array($existingClasses) ? array_merge($newClasses, $existingClasses) : $newClasses);
    }
}
