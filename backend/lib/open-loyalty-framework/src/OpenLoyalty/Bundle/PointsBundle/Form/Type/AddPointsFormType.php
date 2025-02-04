<?php
/**
 * Copyright © 2017 Divante, Inc. All rights reserved.
 * See LICENSE for license details.
 */
namespace OpenLoyalty\Bundle\PointsBundle\Form\Type;

use Broadway\ReadModel\Repository;
use OpenLoyalty\Component\Customer\Domain\ReadModel\CustomerDetails;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Class AddPointsFormType.
 */
class AddPointsFormType extends AbstractType
{
    /**
     * @var Repository
     */
    protected $customerDetailsRepository;

    /**
     * AddPointsFormType constructor.
     *
     * @param Repository $customerDetailsRepository
     */
    public function __construct(Repository $customerDetailsRepository)
    {
        $this->customerDetailsRepository = $customerDetailsRepository;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $customers = $this->customerDetailsRepository->findAll();
        $customerChoices = [];
        /** @var CustomerDetails $customer */
        foreach ($customers as $customer) {
            $customerChoices[$customer->getId()] = $customer->getId();
        }

        $builder->add('customer', ChoiceType::class, [
            'required' => true,
            'constraints' => [new NotBlank()],
            'choices' => $customerChoices,
        ]);

        $builder->add('points', NumberType::class, [
            'attr' => ['min' => 1],
            'scale' => 2,
            'constraints' => [
                new NotBlank(),
                new Range(['min' => 1]),
            ],
        ]);

        $builder->add('validityDuration', NumberType::class, [
            'scale' => 2,
            'constraints' => [
                new Range(['min' => 1]),
            ],
        ]);

        $builder->add('comment', TextType::class, [
            'required' => false,
        ]);
    }
}
