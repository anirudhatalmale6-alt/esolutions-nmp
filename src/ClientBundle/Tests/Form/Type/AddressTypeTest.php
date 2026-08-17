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

namespace SolidInvoice\ClientBundle\Tests\Form\Type;

use Faker\Factory;
use SolidInvoice\ClientBundle\Entity\Address;
use SolidInvoice\ClientBundle\Form\Type\AddressType;
use SolidInvoice\CoreBundle\Tests\FormTestCase;

final class AddressTypeTest extends FormTestCase
{
    public function testSubmit(): void
    {
        $faker = Factory::create();

        $street1 = $faker->buildingNumber . ' ' . $faker->streetName;
        $city = $faker->city;
        $countryCode = $faker->countryCode;

        $formData = [
            'street1' => $street1,
            'city' => $city,
            'country' => $countryCode,
        ];

        $entity = new Address()
            ->setStreet1($street1)
            ->setCity($city)
            ->setCountry($countryCode);

        $this->assertFormData(AddressType::class, $formData, $entity);
    }

    public function testItNoLongerAsksForWhatNobodyFillsIn(): void
    {
        $form = $this->factory->create(AddressType::class);

        // Still columns on the entity, and anything already stored still prints
        // - they are simply not asked for. There are no postal codes in the UAE
        // and "state" means nothing to an emirate.
        self::assertFalse($form->has('street2'));
        self::assertFalse($form->has('state'));
        self::assertFalse($form->has('zip'));

        self::assertTrue($form->has('street1'));
        self::assertTrue($form->has('city'));
        self::assertTrue($form->has('country'));
    }
}
