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

use Symfony\Component\Form\DataTransformerInterface;
use Throwable;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function trim;

/**
 * Carries a line's grade mix between the entity and the hidden field the mix
 * editor writes into.
 *
 * The mix travels as a small piece of JSON - {"<grade id>": quantity} - because
 * it is a handful of numbers attached to one line, and giving it a form
 * collection of its own would mean a second set of rows appearing and
 * disappearing inside a Live Component that already rebuilds its lines on every
 * keystroke.
 *
 * Anything unreadable becomes "no mix" rather than an error. The line is still
 * a perfectly good line; it just goes back to selling a single grade, and the
 * entity's own validation is what insists the mix adds up.
 *
 * @implements DataTransformerInterface<array<string, int>|null, string>
 */
final class GradeSplitTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return '';
        }

        return json_encode($value) ?: '';
    }

    /**
     * @return array<string, int>|null
     */
    public function reverseTransform(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        // The entity does the cleaning (ids that are really ids, quantities
        // that are really quantities), so this only has to hand it a list.
        return is_array($decoded) ? $decoded : null;
    }
}
