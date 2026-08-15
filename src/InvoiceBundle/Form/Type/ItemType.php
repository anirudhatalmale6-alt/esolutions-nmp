<?php

declare(strict_types=1);

/*
 * This file is part of SolidInvoice project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace SolidInvoice\InvoiceBundle\Form\Type;

use Doctrine\Persistence\ManagerRegistry;
use Money\Currency;
use Override;
use SolidInvoice\CoreBundle\Form\Transformer\GradeSplitTransformer;
use SolidInvoice\CoreBundle\Form\Transformer\StockGradeTransformer;
use SolidInvoice\CoreBundle\Form\Transformer\StockModelTransformer;
use SolidInvoice\InvoiceBundle\Entity\Line;
use SolidInvoice\TaxBundle\Entity\Tax;
use SolidInvoice\TaxBundle\Form\Type\LineTaxType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\LiveComponent\Form\Type\LiveCollectionType;

/**
 * @see \SolidInvoice\InvoiceBundle\Tests\Form\Type\ItemTypeTest
 * @extends AbstractType<Line>
 */
class ItemType extends AbstractType
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly StockModelTransformer $stockModelTransformer,
        private readonly StockGradeTransformer $stockGradeTransformer,
        private readonly GradeSplitTransformer $gradeSplitTransformer,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            'description',
            TextareaType::class,
            [
                'attr' => [
                    'class' => 'input-medium invoice-item-name',
                ],
            ]
        );

        $builder->add(
            'price',
            MoneyType::class,
            [
                'attr' => [
                    'class' => 'input-small invoice-item-price',
                ],
                'currency' => $options['currency'],
            ]
        );

        $builder->add(
            'qty',
            NumberType::class,
            [
                'empty_data' => 1,
                'attr' => [
                    'class' => 'input-mini invoice-item-qty',
                ],
            ]
        );

        // The stock item this line sells, set by the model picker on the
        // description box. Hidden: the user sees the model name they typed, and
        // this carries the hard link behind it so stock can move on the right
        // item instead of on a name match. Empty = a non-stock line.
        $builder->add(
            'stockModel',
            HiddenType::class,
            [
                'required' => false,
                'attr' => [
                    'data-stock-model-field' => true,
                ],
            ]
        )->get('stockModel')->addModelTransformer($this->stockModelTransformer);

        // Which grade of that item. Set by the same picker, which offers one
        // suggestion per grade - the owner sells "S22 Grade A", not "S22", and
        // the quantity has to come out of the grade that was actually sold.
        $builder->add(
            'stockGrade',
            HiddenType::class,
            [
                'required' => false,
                'attr' => [
                    'data-stock-grade-field' => true,
                ],
            ]
        )->get('stockGrade')->addModelTransformer($this->stockGradeTransformer);

        // Where the line is a mix of grades sold as one line. Hidden, and never
        // rendered on anything the customer sees - the whole point of a mix is
        // that the customer is buying "a hundred handsets", not "sixty of one
        // grade and forty of another".
        $builder->add(
            'gradeSplit',
            HiddenType::class,
            [
                'required' => false,
                'attr' => [
                    'data-grade-split-field' => true,
                ],
            ]
        )->get('gradeSplit')->addModelTransformer($this->gradeSplitTransformer);

        // Internal IMEI capture for this line (comma-separated, entered via the
        // per-line IMEI popup). Hidden input; never shown to the customer.
        $builder->add(
            'imei',
            HiddenType::class,
            [
                'required' => false,
                'attr' => [
                    'data-imei-field' => true,
                ],
            ]
        );

        if ($this->registry->getManager()->getRepository(Tax::class)->taxRatesConfigured()) {
            $builder->add(
                'taxes',
                LiveCollectionType::class,
                [
                    'entry_type' => LineTaxType::class,
                    'allow_add' => true,
                    'allow_delete' => true,
                    'required' => false,
                    'by_reference' => false,
                    'label' => false,
                    'attr' => [
                        'data-controller' => 'line-tax',
                    ],
                ]
            );
        }
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'invoice_item';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefault('data_class', Line::class)
            ->setRequired('currency')
            ->setAllowedTypes('currency', [Currency::class]);
    }
}
