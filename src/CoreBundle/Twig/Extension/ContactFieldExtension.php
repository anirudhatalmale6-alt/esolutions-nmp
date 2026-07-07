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

namespace SolidInvoice\CoreBundle\Twig\Extension;

use SolidInvoice\ClientBundle\Entity\Contact;
use SolidInvoice\CoreBundle\Enum\CustomFieldTarget;
use SolidInvoice\CoreBundle\Repository\CustomFieldValueRepository;
use Symfony\Component\Uid\Ulid;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use function preg_match;
use function trim;

/**
 * Exposes a contact's custom-field values (phone / mobile / contact number)
 * to templates, so invoices and quotes can print the client's contact details.
 */
final class ContactFieldExtension extends AbstractExtension
{
    public function __construct(
        private readonly CustomFieldValueRepository $values,
    ) {
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('contact_custom_fields', $this->contactCustomFields(...)),
        ];
    }

    /**
     * Returns the populated phone/number custom fields for a contact.
     *
     * @return list<array{label: string, value: string}>
     */
    public function contactCustomFields(Contact $contact): array
    {
        $id = $contact->getId();

        if (! $id instanceof Ulid) {
            return [];
        }

        $out = [];

        foreach ($this->values->findForRecord(CustomFieldTarget::CONTACT, $id) as $value) {
            $field = $value->getField();
            $raw = $value->getValue();

            if ($field === null || $raw === null || trim($raw) === '') {
                continue;
            }

            $label = (string) $field->getLabel();

            // Only surface phone-style fields on documents; e-mail is printed separately.
            if (preg_match('/phone|mobile|cell|tel|number|contact|whats/i', $label) !== 1) {
                continue;
            }

            $out[] = ['label' => $label, 'value' => trim($raw)];
        }

        return $out;
    }
}
