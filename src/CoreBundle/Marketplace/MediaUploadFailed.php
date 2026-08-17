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

namespace SolidInvoice\CoreBundle\Marketplace;

use RuntimeException;

/**
 * A picture that could not be stored, carrying a message meant for the person
 * who tried to upload it rather than for a log file.
 */
final class MediaUploadFailed extends RuntimeException
{
}
