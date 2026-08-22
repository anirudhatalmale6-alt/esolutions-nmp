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

namespace SolidInvoice\CoreBundle;

/**
 * What the portal calls itself when it speaks to somebody.
 *
 * One constant rather than a string typed out at each site, because the places
 * this appears are exactly the places nobody looks: a WhatsApp message, a test
 * e-mail's subject line, the last line the update script prints. The name got
 * out of step once already - a test message went out saying "eSolutions" while
 * every screen in the app said B2B Network - and it did so because each of
 * those strings was written on its own.
 *
 * Deliberately NOT read from the company settings. getPlatformWide() answers
 * with whichever business happened to fill a setting in, so a member of one
 * business could be welcomed to the name of another. The portal has one name;
 * the businesses on it have their own, and those come from their own records.
 */
final class Platform
{
    public const string NAME = 'B2B Network';
}
