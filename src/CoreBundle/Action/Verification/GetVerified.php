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

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Verification\VerificationAlerts;
use SolidInvoice\CoreBundle\Verification\VerificationStore;
use SolidInvoice\CoreBundle\Verification\VerificationUploadFailed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function ini_get;
use function strlen;
use function strtolower;
use function trim;

/**
 * "Get your Trusted badge" - the member's side of verification.
 *
 * Offered on the way out of sign-up and reachable from the profile menu forever
 * after, because it is optional by design: nobody is held at the door over a
 * passport scan. What it collects is what the platform owner needs in front of
 * them before ticking Verified, which is the prerequisite for Premium.
 *
 * It is deliberately NOT a step inside the sign-up flow. That flow carries its
 * answers through the session between pages, and an uploaded file cannot be put
 * in a session - it would have had to be written to disk on one page and
 * cleaned up if the person walked away on the next.
 */
#[IsGranted('ROLE_ADMIN')]
final class GetVerified extends AbstractController
{
    public function __construct(
        private readonly CompanySelector $companySelector,
        private readonly EntityManagerInterface $entityManager,
        private readonly VerificationStore $store,
        private readonly VerificationAlerts $alerts,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company = $this->currentCompany();

        if (! $company instanceof Company) {
            $this->addFlash('error', 'Pick a company first, then come back to this page.');

            return $this->redirectToRoute('_dashboard');
        }

        if ($request->isMethod('POST')) {
            return $this->handleUpload($request, $company);
        }

        return $this->render('@SolidInvoiceCore/Verification/index.html.twig', [
            'company' => $company,
            'documents' => [
                ['kind' => VerificationStore::ID_FRONT, 'label' => 'National ID - front', 'path' => $company->getIdFrontPath()],
                ['kind' => VerificationStore::ID_BACK, 'label' => 'National ID - back', 'path' => $company->getIdBackPath()],
                ['kind' => VerificationStore::PASSPORT, 'label' => 'Passport', 'path' => $company->getPassportPath()],
            ],
            'accept' => '.' . implode(',.', $this->store->allowedExtensions()),
            'maxMb' => (int) ($this->store->maxBytes() / 1024 / 1024),
        ]);
    }

    private function handleUpload(Request $request, Company $company): Response
    {
        // A phone photo bigger than PHP's post_max_size makes the entire POST
        // body vanish before it reaches us - the file and the CSRF token both
        // arrive empty, which reads as "your session expired" and sends people
        // round in circles. Catch it and say what actually went wrong.
        $postMax = $this->toBytes((string) ini_get('post_max_size'));
        $contentLength = (int) $request->server->get('CONTENT_LENGTH', 0);

        if ($postMax > 0 && $contentLength > $postMax && $request->files->count() === 0) {
            $this->addFlash('error', 'Those files are too large to upload together. Please send them one at a time.');

            return $this->redirectToRoute('_verification');
        }

        if (! $this->isCsrfTokenValid('verification.documents', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_verification');
        }

        $stored = 0;

        foreach (VerificationStore::KINDS as $kind) {
            $file = $request->files->get($kind);

            if (! $file instanceof UploadedFile) {
                continue;
            }

            try {
                $path = $this->store->store($company, $kind, $file);
            } catch (VerificationUploadFailed $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('_verification');
            }

            $this->store->remove($this->pathFor($company, $kind));
            $this->applyPath($company, $kind, $path);
            ++$stored;
        }

        if ($stored === 0) {
            $this->addFlash('error', 'Please choose at least one document to upload.');

            return $this->redirectToRoute('_verification');
        }

        // Sending a new document puts the company back in the queue, but never
        // takes away a badge it has already been given - re-uploading a clearer
        // photo of the same passport is not a reason to stop trusting somebody.
        $company->setVerificationSubmittedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        // Nothing used to happen here beyond the flash, so the only way the
        // owner found out was opening Manage and looking. Best-effort: the
        // documents are already stored and the person is already told, and a
        // mail server having a bad morning must not change either of those.
        $this->alerts->documentsSubmitted($company);

        $this->addFlash('success', 'Thank you - your documents are with us. We check these by hand, so give it a day or so.');

        return $this->redirectToRoute('_verification');
    }

    private function pathFor(Company $company, string $kind): ?string
    {
        return match ($kind) {
            VerificationStore::ID_FRONT => $company->getIdFrontPath(),
            VerificationStore::ID_BACK => $company->getIdBackPath(),
            VerificationStore::PASSPORT => $company->getPassportPath(),
            default => null,
        };
    }

    private function applyPath(Company $company, string $kind, string $path): void
    {
        match ($kind) {
            VerificationStore::ID_FRONT => $company->setIdFrontPath($path),
            VerificationStore::ID_BACK => $company->setIdBackPath($path),
            VerificationStore::PASSPORT => $company->setPassportPath($path),
            default => null,
        };
    }

    private function currentCompany(): ?Company
    {
        $companyId = $this->companySelector->getCompany();

        if ($companyId === null) {
            return null;
        }

        return $this->entityManager->find(Company::class, $companyId);
    }

    /**
     * Convert a php.ini shorthand size (e.g. "8M", "2G", "512K") into bytes.
     */
    private function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
