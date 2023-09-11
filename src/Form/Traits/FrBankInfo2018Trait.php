<?php

namespace Wexample\SymfonyAccounting\Form\Traits;

use Symfony\Component\Form\FormBuilderInterface;
use Wexample\SymfonyHelpers\Form\FloatType;
use Wexample\SymfonyHelpers\Form\TextType;
use Wexample\SymfonyHelpers\Helper\IconMaterialHelper;

trait FrBankInfo2018Trait
{
    public function buildFrBankInfo2018(FormBuilderInterface $builder): static
    {
        $builder->add(
            'bank_owner',
            TextType::class,
            [
                self::FIELD_OPTION_NAME_REQUIRED => false,
                self::FIELD_OPTION_NAME_ICON => IconMaterialHelper::ICON_PERM_IDENTITY,
            ]
        )
            ->add(
                'bank_iban',
                TextType::class,
                [
                    self::FIELD_OPTION_NAME_REQUIRED => false,
                    self::FIELD_OPTION_NAME_ICON => IconMaterialHelper::ICON_FILTER_1,
                ]
            )
            ->add(
                'bank_bic',
                TextType::class,
                [
                    self::FIELD_OPTION_NAME_REQUIRED => false,
                    self::FIELD_OPTION_NAME_ICON => IconMaterialHelper::ICON_FILTER_2,
                ]
            )
            ->add(
                'bank_location',
                TextType::class,
                [
                    self::FIELD_OPTION_NAME_REQUIRED => false,
                    self::FIELD_OPTION_NAME_ICON => IconMaterialHelper::ICON_PLACE,
                ]
            )
            ->add(
                'bank_rib_bank',
                TextType::class,
                [
                    self::FIELD_OPTION_NAME_REQUIRED => false,
                    self::FIELD_OPTION_NAME_ICON => IconMaterialHelper::ICON_FILTER_3,
                ]
            )
            ->add(
                'bank_rib_agency',
                TextType::class,
                [
                    self::FIELD_OPTION_NAME_REQUIRED => false,
                ]
            )
            ->add(
                'bank_rib_account',
                TextType::class,
                [
                    self::FIELD_OPTION_NAME_REQUIRED => false,
                ]
            )
            ->add(
                'bank_rib_key',
                FloatType::class,
                [
                    self::FIELD_OPTION_NAME_REQUIRED => false,
                    'attr' => [
                        'min' => 0,
                        'max' => 97,
                        'step' => 1,
                    ],
                ]
            );

        return $this;
    }
}
