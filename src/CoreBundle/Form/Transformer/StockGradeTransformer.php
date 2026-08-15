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

use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Entity\StockGrade;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Uid\Ulid;
use Throwable;
use function is_string;
use function trim;

/**
 * Moves a stock grade between the entity and the hidden id the model picker
 * writes into the line.
 *
 * A missing or unknown id becomes null rather than an error: the picker is a
 * convenience, and a line whose grade was since emptied must still save.
 *
 * Scoped through the item's company rather than trusting the id, so a grade
 * belonging to another business on the portal resolves to nothing.
 *
 * @implements DataTransformerInterface<StockGrade|null, string>
 */
final class StockGradeTransformer implements DataTransformerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function transform(mixed $value): string
    {
        return $value instanceof StockGrade ? (string) $value->getId() : '';
    }

    public function reverseTransform(mixed $value): ?StockGrade
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! Ulid::isValid($value)) {
            return null;
        }

        try {
            // Joined to the item so the company filter on StockModel applies -
            // StockGrade carries no company of its own.
            return $this->entityManager
                ->createQuery(
                    'SELECT g FROM ' . StockGrade::class . ' g JOIN g.stockModel m WHERE g.id = :id'
                )
                ->setParameter('id', Ulid::fromString($value), UlidType::NAME)
                ->getOneOrNullResult();
        } catch (Throwable) {
            return null;
        }
    }
}
