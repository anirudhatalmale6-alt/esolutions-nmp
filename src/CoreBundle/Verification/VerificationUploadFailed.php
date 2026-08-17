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

namespace SolidInvoice\CoreBundle\Verification;

use RuntimeException;

/**
 * A document could not be stored. The message is written for the member, not for
 * a log file - it goes straight onto the page they are looking at.
 */
final class VerificationUploadFailed extends RuntimeException
{
}
