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

namespace SolidInvoice\DataGridBundle\Filter;

use Doctrine\ORM\QueryBuilder;
use SolidInvoice\DataGridBundle\Source\ORMSource;

/**
 * @see \SolidInvoice\DataGridBundle\Tests\Filter\SearchFilterTest
 */
final readonly class SearchFilter implements FilterInterface
{
    /**
     * @param string[] $searchFields
     */
    public function __construct(
        private array $searchFields
    ) {
    }

    public function filter(QueryBuilder $queryBuilder, mixed $value): void
    {
        if (! $value || $this->searchFields === []) {
            return;
        }

        // Every alias already on the builder. Joining one twice makes Doctrine
        // throw, which once surfaced as a 500 when searching the invoice grid by
        // client name, and other filters (sorting, choice filters) may have
        // joined the same relation before we got here.
        $joined = [];

        foreach ($queryBuilder->getDQLPart('join') as $joins) {
            foreach ($joins as $join) {
                $joined[$join->getAlias()] = true;
            }
        }

        $conditions = [];

        foreach ($this->searchFields as $field) {
            $segments = explode('.', $field);
            $property = array_pop($segments);
            $alias = ORMSource::ALIAS;

            /*
             * Walk the relation path a segment at a time, so a field two
             * relations away works as well as one - an invoice searched by its
             * client's CONTACT name is "client.contacts.firstName", and the
             * previous version of this took only the first two segments and
             * built "client.contacts LIKE :q", which is not a field and is a
             * fatal query error rather than a missing result.
             *
             * The alias is the path joined with underscores, so "client" stays
             * "client" (what every existing searchField already relies on) and
             * the second hop becomes "client_contacts" - two different paths
             * can never collide on one name.
             */
            $path = [];

            foreach ($segments as $relation) {
                $path[] = $relation;
                $joinAlias = implode('_', $path);

                if (! isset($joined[$joinAlias])) {
                    // LEFT, so a row with nothing on the far end (an invoice with
                    // no client, a client with no contacts yet) is not silently
                    // dropped from the results.
                    $queryBuilder->leftJoin($alias . '.' . $relation, $joinAlias);
                    $joined[$joinAlias] = true;
                }

                $alias = $joinAlias;
            }

            $conditions[] = sprintf('%s.%s LIKE :q', $alias, $property);
        }

        $queryBuilder->andWhere($queryBuilder->expr()->orX(...$conditions));
        $queryBuilder->setParameter('q', '%' . $value . '%');
    }
}
