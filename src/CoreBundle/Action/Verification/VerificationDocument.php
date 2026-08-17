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

namespace SolidInvoice\CoreBundle\Action\Verification;

use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Verification\VerificationStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/**
 * The ONLY way to read an identity document back.
 *
 * Documents live under var/verification, which the web server never serves, so
 * this action is the whole access control story: super-admin only, no exceptions
 * and no signed links. The member who uploaded a passport cannot fetch it back
 * either - there is nothing they would learn from it, and every extra door is
 * another way for the wrong person to get one.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class VerificationDocument extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VerificationStore $store,
    ) {
    }

    public function __invoke(string $company, string $kind): Response
    {
        if (! Ulid::isValid($company)) {
            throw $this->createNotFoundException();
        }

        $entity = $this->entityManager->find(Company::class, Ulid::fromString($company));

        if (! $entity instanceof Company) {
            throw $this->createNotFoundException();
        }

        $relative = match ($kind) {
            VerificationStore::ID_FRONT => $entity->getIdFrontPath(),
            VerificationStore::ID_BACK => $entity->getIdBackPath(),
            VerificationStore::PASSPORT => $entity->getPassportPath(),
            default => null,
        };

        $absolute = $this->store->resolve($relative);

        if ($absolute === null) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($absolute);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $kind . '.' . $this->store->extensionOf((string) $relative),
        );

        // Never let a proxy or a browser keep a copy of somebody's passport.
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
