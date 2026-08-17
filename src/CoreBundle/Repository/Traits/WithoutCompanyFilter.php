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

namespace SolidInvoice\CoreBundle\Repository\Traits;

/**
 * Run one query across every business on the portal.
 *
 * The company filter is on for good reason - it is what keeps one member's
 * invoices out of another member's list. But a handful of things are
 * deliberately platform-wide: the support queue, the Marketplace, the community
 * feed. Those are read by people from every business, and on the public
 * Marketplace page there is no signed-in business at all, so a filter scoped to
 * "the company you are inside" silently returns nothing.
 *
 * The filter is put back exactly as it was found, including when the query
 * throws, so nothing else in the request is affected by having borrowed it.
 */
trait WithoutCompanyFilter
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withoutCompanyFilter(callable $callback): mixed
    {
        $filters = $this->getEntityManager()->getFilters();
        $wasEnabled = $filters->isEnabled('company');

        if ($wasEnabled) {
            $filters->disable('company');
        }

        try {
            return $callback();
        } finally {
            if ($wasEnabled) {
                $filters->enable('company');
            }
        }
    }
}
