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

namespace SolidInvoice\CoreBundle\Action\Unlock;

use SolidInvoice\CoreBundle\Repository\UnlockCodeRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin overview of the IMEI unlock codes: how many are on file, the full
 * searchable list, a bulk lookup box, the public lookup link to share, and the
 * upload / clear controls.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final readonly class ListUnlockCodes
{
    public function __construct(
        private UnlockCodeRepository $unlockCodeRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array{total: int, codes: list<\SolidInvoice\CoreBundle\Entity\UnlockCode>, publicUrl: string}
     */
    #[Template('@SolidInvoiceCore/Unlock/list.html.twig')]
    public function __invoke(): array
    {
        // Both the count and the list rely on the CompanyFilter, so the number
        // shown always matches the rows below it.
        return [
            'total' => $this->unlockCodeRepository->countAll(),
            'codes' => $this->unlockCodeRepository->findAllOrdered(),
            'publicUrl' => $this->urlGenerator->generate('_unlock_public', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }
}
