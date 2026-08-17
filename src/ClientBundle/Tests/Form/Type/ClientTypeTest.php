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

use Doctrine\ORM\EntityManagerInterface;
use Mockery as M;
use Money\Currency;
use Override;
use SolidInvoice\ClientBundle\Entity\Client;
use SolidInvoice\ClientBundle\Form\Type\ClientType;
use SolidInvoice\ClientBundle\Form\Type\ContactType;
use SolidInvoice\CoreBundle\Enum\CustomFieldTarget;
use SolidInvoice\CoreBundle\Form\Type\CustomFieldValueCollectionType;
use SolidInvoice\CoreBundle\Repository\CustomFieldRepository;
use SolidInvoice\CoreBundle\Repository\CustomFieldValueRepository;
use SolidInvoice\CoreBundle\Service\CustomField\CustomFieldTypeResolver;
use SolidInvoice\CoreBundle\Tests\FormTestCase;
use SolidInvoice\MoneyBundle\Form\Type\CurrencyType;
use SolidInvoice\SettingsBundle\SystemConfig;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureGate;
use Symfony\Component\Form\PreloadedExtension;

final class ClientTypeTest extends FormTestCase
{
    /**
     * Mutable list of feature keys that should report as DISABLED — every other key is enabled.
     *
     * @var list<string>
     */
    private array $disabledFeatures = [];

    public function testSubmit(): void
    {
        $this->disabledFeatures = [];

        $company = $this->faker->company;
        $currencyCode = 'USD';

        $formData = [
            'name' => $company,
            'currencyCode' => $currencyCode,
            'contacts' => [],
            'addresses' => [],
        ];

        $object = new Client();
        $object->setName($company);
        $object->setCurrencyCode($currencyCode);

        $this->assertFormData(ClientType::class, $formData, $object);
    }

    public function testTheFormOnlyAsksForWhatIsUsed(): void
    {
        $this->disabledFeatures = ['multi_currency'];

        $form = $this->factory->create(ClientType::class, new Client());

        // Website was dropped outright, and the currency box is not rendered at
        // all when multi-currency is off - it used to sit there permanently
        // greyed out with an upgrade advert beneath it.
        self::assertFalse($form->has('website'));
        self::assertFalse($form->has('currencyCode'));
        self::assertTrue($form->has('whatsapp'));
    }

    public function testTheCurrencyIsStillSetWhenTheFieldIsNotShown(): void
    {
        $this->disabledFeatures = ['multi_currency'];

        $object = new Client();
        $object->setName($this->faker->company);
        $object->setCurrencyCode('EUR');

        $form = $this->factory->create(ClientType::class, $object);

        // No currency field to submit any more, but the SUBMIT listener still
        // puts the company default on the entity - what gets saved is unchanged.
        $form->submit([
            'name' => $object->getName(),
            'contacts' => [],
            'addresses' => [],
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame('USD', $object->getCurrencyCode());
    }

    /**
     * @return PreloadedExtension[]
     */
    #[Override]
    protected function getExtensions(): array
    {
        $featureGate = M::mock(FeatureGate::class);
        $featureGate->shouldReceive('isEnabled')
            ->andReturnUsing(fn (string $key): bool => ! in_array($key, $this->disabledFeatures, true));

        $systemConfig = M::mock(SystemConfig::class);
        $systemConfig->shouldReceive('getCurrency')->andReturn(new Currency('USD'));

        $fieldRepo = M::mock(CustomFieldRepository::class);
        $fieldRepo->shouldReceive('findByTargetOrdered')
            ->with(M::type(CustomFieldTarget::class))
            ->andReturn([]);

        $valueRepo = M::mock(CustomFieldValueRepository::class);
        $em = M::mock(EntityManagerInterface::class);
        $em->shouldReceive('contains')->zeroOrMoreTimes()->andReturn(false);

        return [
            // register the type instances with the PreloadedExtension
            new PreloadedExtension([
                new ClientType($featureGate, $systemConfig),
                new ContactType($featureGate),
                new CustomFieldValueCollectionType($fieldRepo, $valueRepo, new CustomFieldTypeResolver(), $em),
                new CurrencyType('en'),
            ], []),
        ];
    }
}
