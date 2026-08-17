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

namespace SolidInvoice\ClientBundle\Form\Type;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * @see \SolidInvoice\ClientBundle\Tests\Form\Type\AddressTypeTest
 * @extends AbstractType<array{street1: mixed, street2: mixed, city: mixed, state: mixed, zip: mixed, country: mixed}>
 */
class AddressType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Street 2, State and Zip are gone from the form. They are still columns
        // on the entity, and anything already stored in them is untouched and
        // still prints on an invoice - they are simply not asked for any more.
        //
        // This is a UAE phone wholesaler's address book: there are no postal
        // codes here, "state" means nothing to an emirate, and a second street
        // line is a box people tab past. Five boxes where two would do is why
        // adding a customer felt like paperwork.
        $builder->add('street1', null, ['label' => 'Address', 'required' => false]);
        $builder->add('city', null, ['required' => false]);
        $builder->add(
            'country',
            CountryType::class,
            [
                'placeholder' => 'client.address.country.select',
                'required' => false,
            ]
        );
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'address';
    }
}
