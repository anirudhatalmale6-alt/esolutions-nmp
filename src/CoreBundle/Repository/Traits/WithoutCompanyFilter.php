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
 * feed, and the customer's copy of an invoice. Those are read by people from
 * every business, and on a public page there is no signed-in business at all, so
 * a filter scoped to "the company you are inside" silently returns nothing.
 *
 * SUSPEND, never disable.
 *
 * Doctrine keeps a filter's parameters on the filter OBJECT, and enable() builds
 * a brand new one. So disable() followed by enable() hands the request back a
 * filter with no companyId on it - and CompanyFilter::addFilterConstraint
 * returns an empty string when that parameter is missing. The filter is then
 * still "on", still in the query hash, and constraining nothing: every query for
 * the rest of that request sees every business's rows. suspend()/restore() keep
 * the same object, parameters and all.
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
            $filters->suspend('company');
        }

        try {
            return $callback();
        } finally {
            // Put back exactly what was borrowed, including when the query threw.
            if ($wasEnabled && $filters->isSuspended('company')) {
                $filters->restore('company');
            }
        }
    }
}
