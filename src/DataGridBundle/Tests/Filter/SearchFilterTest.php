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

namespace SolidInvoice\DataGridBundle\Tests\Filter;

use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SolidInvoice\DataGridBundle\Filter\SearchFilter;

#[CoversClass(SearchFilter::class)]
final class SearchFilterTest extends TestCase
{
    public function testFilterAddsCorrectConditionsWhenQueryIsNotEmpty(): void
    {
        $queryBuilder = $this->queryBuilder();
        $queryBuilder->expects($this->once())->method('expr')->willReturn(new Expr());
        $queryBuilder->expects($this->once())->method('andWhere')->with(new Expr()->orX('d.field1 LIKE :q', 'd.field2 LIKE :q'));
        $queryBuilder->expects($this->once())->method('setParameter')->with('q', '%query%');
        $queryBuilder->expects($this->never())->method('leftJoin');

        $filter = new SearchFilter(['field1', 'field2']);
        $filter->filter($queryBuilder, 'query');
    }

    public function testFilterDoesNotAddConditionsWhenQueryIsEmpty(): void
    {
        $queryBuilder = $this->queryBuilder();
        $queryBuilder->expects($this->never())->method('andWhere');
        $queryBuilder->expects($this->never())->method('setParameter');
        $queryBuilder->expects($this->never())->method('leftJoin');

        $filter = new SearchFilter(['field1', 'field2']);
        $filter->filter($queryBuilder, '');
    }

    public function testFilterHandlesFieldsWithAliasCorrectly(): void
    {
        $queryBuilder = $this->queryBuilder();
        $queryBuilder->expects($this->once())->method('expr')->willReturn(new Expr());
        $queryBuilder->expects($this->once())->method('andWhere')->with(new Expr()->orX('b.field1 LIKE :q', 'd.field2 LIKE :q'));
        $queryBuilder->expects($this->once())->method('setParameter')->with('q', '%query%');
        $queryBuilder->expects($this->once())->method('leftJoin')->with('d.b', 'b');

        $filter = new SearchFilter(['b.field1', 'field2']);
        $filter->filter($queryBuilder, 'query');
    }

    /**
     * Searching an invoice by the name of the person at the client, which is two
     * relations away. The previous implementation kept only the first two
     * segments of the path and asked for "client.contacts LIKE :q" - not a
     * field, and a fatal query error rather than a missing result.
     */
    public function testFilterWalksARelationPathMoreThanOneDeep(): void
    {
        $joins = [];
        $queryBuilder = $this->queryBuilder();
        $queryBuilder->method('expr')->willReturn(new Expr());
        $queryBuilder->expects($this->once())->method('andWhere')->with(new Expr()->orX('client_contacts.firstName LIKE :q'));
        $queryBuilder->expects($this->exactly(2))
            ->method('leftJoin')
            ->willReturnCallback(static function (string $join, string $alias) use (&$joins, $queryBuilder): QueryBuilder {
                $joins[] = [$join, $alias];

                return $queryBuilder;
            });

        $filter = new SearchFilter(['client.contacts.firstName']);
        $filter->filter($queryBuilder, 'Ahmed');

        self::assertSame([['d.client', 'client'], ['client.contacts', 'client_contacts']], $joins);
    }

    public function testFilterJoinsEachRelationOnlyOnce(): void
    {
        $joins = [];
        $queryBuilder = $this->queryBuilder();
        $queryBuilder->method('expr')->willReturn(new Expr());
        $queryBuilder->method('leftJoin')
            ->willReturnCallback(static function (string $join, string $alias) use (&$joins, $queryBuilder): QueryBuilder {
                $joins[] = [$join, $alias];

                return $queryBuilder;
            });

        $filter = new SearchFilter(['client.name', 'client.contacts.firstName', 'client.contacts.lastName']);
        $filter->filter($queryBuilder, 'Ahmed');

        self::assertSame([['d.client', 'client'], ['client.contacts', 'client_contacts']], $joins);
    }

    /**
     * Sorting and the choice filters run before this one and may have joined the
     * same relation already. Joining an alias twice makes Doctrine throw, which
     * is a 500 on a page that was only searched.
     */
    public function testFilterDoesNotJoinARelationAnotherFilterAlreadyJoined(): void
    {
        $queryBuilder = $this->queryBuilder([
            new Expr\Join(Expr\Join::LEFT_JOIN, 'd.client', 'client'),
        ]);
        $queryBuilder->method('expr')->willReturn(new Expr());
        $queryBuilder->expects($this->never())->method('leftJoin');
        $queryBuilder->expects($this->once())->method('andWhere')->with(new Expr()->orX('client.name LIKE :q'));

        $filter = new SearchFilter(['client.name']);
        $filter->filter($queryBuilder, 'Gulf');
    }

    /**
     * @param list<Expr\Join> $existingJoins
     * @return QueryBuilder&MockObject
     */
    private function queryBuilder(array $existingJoins = []): QueryBuilder
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);

        // The real builder returns an array of arrays keyed by root alias. It is
        // stubbed rather than left to the mock's default null, because the
        // filter now reads the existing joins for every search, not only for a
        // dotted field.
        $queryBuilder->method('getDQLPart')->with('join')->willReturn($existingJoins === [] ? [] : ['d' => $existingJoins]);

        return $queryBuilder;
    }
}
