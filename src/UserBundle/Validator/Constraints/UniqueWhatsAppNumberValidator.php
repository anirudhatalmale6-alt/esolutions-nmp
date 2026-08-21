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

namespace SolidInvoice\UserBundle\Validator\Constraints;

use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Repository\UserRepository;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use function is_string;
use function trim;

/**
 * Blank and badly-formatted numbers are left alone: NotBlank and WhatsAppNumber
 * each say their own piece, and being told a number is "already registered" when
 * the real problem is a missing country code would send somebody looking for an
 * account that does not exist.
 */
final class UniqueWhatsAppNumberValidator extends ConstraintValidator
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof UniqueWhatsAppNumber) {
            throw new UnexpectedTypeException($constraint, UniqueWhatsAppNumber::class);
        }

        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            throw new UnexpectedTypeException($value, 'string');
        }

        if (trim($value) === '') {
            return;
        }

        if ($this->userRepository->isWhatsAppNumberTaken($value, $this->editedUser()?->getId())) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }

    /**
     * The account being edited, when there is one.
     *
     * On sign-up the form holds a Registration and there is nobody to exclude.
     * On the profile page it holds the User themselves, whose own number is
     * already in the table - without excluding them, saving the profile with
     * the number untouched would report it as somebody else's.
     */
    private function editedUser(): ?User
    {
        $root = $this->context->getRoot();
        $data = $root instanceof FormInterface ? $root->getData() : $root;

        return $data instanceof User ? $data : null;
    }
}
