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

namespace SolidInvoice\CoreBundle\Form\Transformer;

use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidInvoice\CoreBundle\Repository\StockModelRepository;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Uid\Ulid;
use function is_string;
use function trim;

/**
 * Moves a stock item between the entity and the hidden id the model picker
 * writes into the line.
 *
 * A missing or unknown id becomes null rather than an error: the picker is a
 * convenience, and a line whose stock item was since deleted must still save.
 * The lookup goes through the repository so the company filter applies - an id
 * belonging to another company resolves to nothing.
 *
 * @implements DataTransformerInterface<StockModel|null, string>
 */
final class StockModelTransformer implements DataTransformerInterface
{
    public function __construct(
        private readonly StockModelRepository $stockModelRepository,
    ) {
    }

    public function transform(mixed $value): string
    {
        return $value instanceof StockModel ? (string) $value->getId() : '';
    }

    public function reverseTransform(mixed $value): ?StockModel
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! Ulid::isValid($value)) {
            return null;
        }

        return $this->stockModelRepository->find(Ulid::fromString($value));
    }
}
