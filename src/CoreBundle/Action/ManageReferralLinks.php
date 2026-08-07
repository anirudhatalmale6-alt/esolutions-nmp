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

namespace SolidInvoice\CoreBundle\Action;

use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\ReferralLink;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Repository\ReferralLinkRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use function preg_replace;
use function strtoupper;
use function substr;
use function trim;

/**
 * Super-user console for the sales / referral links. The platform owner creates a
 * short code per sales rep (their personal join link), can pause or delete a link,
 * and sees how many businesses each rep has brought onto the network - so referral
 * performance is visible at a glance for motivation / commission.
 *
 * Open public registration is closed; these links are the only way a new business
 * can join (see {@see \SolidInvoice\CoreBundle\Action\JoinReferral} and Register).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class ManageReferralLinks extends AbstractController
{
    public function __construct(
        private readonly ReferralLinkRepository $referralRepository,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return match ((string) $request->request->get('intent', 'create')) {
                'toggle' => $this->handleToggle($request),
                'delete' => $this->handleDelete($request),
                default => $this->handleCreate($request),
            };
        }

        // Count how many companies each code has brought in. Company count is small
        // and this mirrors the memberships console (no company filter in play here).
        $counts = [];
        foreach ($this->companyRepository->findBy([]) as $company) {
            if (! $company instanceof Company) {
                continue;
            }

            $code = $company->getReferredByCode();

            if ($code !== null && $code !== '') {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($this->referralRepository->findAllOrdered() as $link) {
            $rows[] = [
                'link' => $link,
                'url' => $this->generateUrl('_referral_join', ['code' => $link->getCode()], UrlGeneratorInterface::ABSOLUTE_URL),
                'count' => $counts[$link->getCode()] ?? 0,
            ];
        }

        return $this->render('@SolidInvoiceCore/Referral/manage.html.twig', [
            'rows' => $rows,
        ]);
    }

    private function handleCreate(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('referral.manage', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_referral_manage');
        }

        $repName = trim((string) $request->request->get('rep_name'));

        if ($repName === '') {
            $this->addFlash('error', 'Please enter the sales rep\'s name.');

            return $this->redirectToRoute('_referral_manage');
        }

        $code = $this->normalizeCode((string) $request->request->get('code', ''));

        if ($code === '') {
            $code = $this->normalizeCode($repName);
        }

        if ($code === '') {
            $this->addFlash('error', 'Please enter a link code (letters or numbers).');

            return $this->redirectToRoute('_referral_manage');
        }

        $code = $this->uniqueCode($code);

        $link = (new ReferralLink())
            ->setRepName($repName)
            ->setCode($code)
            ->setActive(true);

        $this->referralRepository->save($link);

        $this->addFlash('success', sprintf('Referral link for %s created: /join/%s', $repName, $code));

        return $this->redirectToRoute('_referral_manage');
    }

    private function handleToggle(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('referral.manage', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_referral_manage');
        }

        $link = $this->resolveLink($request);

        if (! $link instanceof ReferralLink) {
            $this->addFlash('error', 'That link could not be found.');

            return $this->redirectToRoute('_referral_manage');
        }

        $link->setActive(! $link->isActive());
        $this->referralRepository->save($link);

        $this->addFlash('success', sprintf('%s\'s link is now %s.', $link->getRepName(), $link->isActive() ? 'active' : 'paused'));

        return $this->redirectToRoute('_referral_manage');
    }

    private function handleDelete(Request $request): Response
    {
        if (! $this->isCsrfTokenValid('referral.manage', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_referral_manage');
        }

        $link = $this->resolveLink($request);

        if (! $link instanceof ReferralLink) {
            $this->addFlash('error', 'That link could not be found.');

            return $this->redirectToRoute('_referral_manage');
        }

        $repName = $link->getRepName();
        $this->referralRepository->delete($link);

        // Companies already referred keep their stamped code/name, so history is
        // preserved even after the link itself is removed.
        $this->addFlash('success', sprintf('%s\'s referral link was deleted.', $repName));

        return $this->redirectToRoute('_referral_manage');
    }

    private function resolveLink(Request $request): ?ReferralLink
    {
        $id = (string) $request->request->get('link');

        return Ulid::isValid($id) ? $this->referralRepository->find(Ulid::fromString($id)) : null;
    }

    /**
     * Uppercase, keep only letters/digits/dash/underscore, cap length. Produces a
     * path-safe code from either a typed code or a rep's name.
     */
    private function normalizeCode(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = (string) preg_replace('/[^A-Z0-9_-]+/', '', $value);

        return substr($value, 0, 64);
    }

    /**
     * Ensure the code is unique by appending a number when it is already taken.
     */
    private function uniqueCode(string $code): string
    {
        $candidate = $code;
        $suffix = 2;

        while ($this->referralRepository->codeExists($candidate)) {
            $candidate = substr($code, 0, 60) . $suffix;
            ++$suffix;
        }

        return $candidate;
    }
}
