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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Mpdf\MpdfException;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Contracts\EmailVerificationGateInterface;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Pdf\Generator;
use SolidInvoice\CoreBundle\Repository\Traits\WithoutCompanyFilter;
use SolidInvoice\CoreBundle\Response\PdfResponse;
use SolidInvoice\InvoiceBundle\Entity\Invoice;
use SolidInvoice\QuoteBundle\Entity\Quote;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class ViewBilling
{
    use WithoutCompanyFilter;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly RouterInterface $router,
        private readonly CompanySelector $companySelector,
        private readonly Generator $pdfGenerator,
        private readonly Environment $twig,
        private readonly EmailVerificationGateInterface $emailVerificationGate,
        private readonly Security $security,
    ) {
    }

    /**
     * View a quote if not logged in.
     *
     * @return array{quote: Quote, title: string, template: string}|Response
     * @throws InvalidArgumentException|InvalidParameterException|MissingMandatoryParametersException|NotFoundHttpException|RouteNotFoundException|LoaderError|MpdfException|RuntimeError|SyntaxError
     */
    #[Template('@SolidInvoiceCore/View/quote.html.twig')]
    public function quoteAction(Request $request, string $uuid): array|Response
    {
        $options = [
            'repository' => Quote::class,
            'route' => '_quotes_view',
            'template' => '@SolidInvoiceQuote/quote_template.html.twig',
            'uuid' => $uuid,
            'entity' => 'quote',
            'pdfTemplate' => '@SolidInvoiceQuote/Pdf/quote.html.twig',
        ];

        return $this->createResponse($request, $options);
    }

    /**
     * View a invoice if not logged in.
     *
     * @return array{invoice: Invoice, title: string, template: string}|Response
     * @throws InvalidArgumentException|InvalidParameterException|MissingMandatoryParametersException|NotFoundHttpException|RouteNotFoundException|LoaderError|MpdfException|RuntimeError|SyntaxError
     */
    #[Template('@SolidInvoiceCore/View/invoice.html.twig')]
    public function invoiceAction(Request $request, string $uuid): array|Response
    {
        $options = [
            'repository' => Invoice::class,
            'route' => '_invoices_view',
            'template' => '@SolidInvoiceInvoice/external_invoice_view.html.twig',
            'uuid' => $uuid,
            'entity' => 'invoice',
            'pdfTemplate' => '@SolidInvoiceInvoice/Pdf/invoice.html.twig',
        ];

        return $this->createResponse($request, $options);
    }

    /**
     * Is the reader currently standing inside the business that issued this?
     *
     * Deliberately the company they have ACTIVE, not merely one they belong to.
     * The staff page resolves the document under the company filter, which is set
     * from the active company - so somebody who owns three businesses and is
     * currently inside the wrong one would be sent to a page that cannot find it.
     * They get the customer's copy instead, which always works.
     *
     * A role is not enough either. A platform super-admin is not staff at a
     * member's business, and would 404 on that page for the same reason.
     */
    private function readerWorksFor(?Company $company): bool
    {
        if (! $company instanceof Company) {
            return false;
        }

        if (! $this->security->getUser() instanceof User) {
            return false;
        }

        $active = $this->companySelector->getCompany();

        return $active !== null && $company->getId()?->equals($active) === true;
    }

    /**
     * The customer's copy is a platform-wide read: see the note on the trait, and
     * on the lookup below. ViewBilling is an action rather than a repository, so
     * it hands the trait the manager the same way one would.
     */
    private function getEntityManager(): EntityManagerInterface
    {
        $manager = $this->registry->getManager();

        assert($manager instanceof EntityManagerInterface);

        return $manager;
    }

    /**
     * @param array{"repository": class-string, "route": string, "template": string, "uuid": string, "entity": string, "pdfTemplate": string} $options
     * @return array<string, mixed>|Response
     * @throws NotFoundHttpException|InvalidArgumentException|InvalidParameterException|MissingMandatoryParametersException|RouteNotFoundException|LoaderError|MpdfException|RuntimeError|SyntaxError
     */
    private function createResponse(Request $request, array $options): array|Response
    {
        $repository = $this->registry->getRepository($options['repository']);

        // Looked up across every business on purpose.
        //
        // This is the customer's link, and the customer has no company. But when
        // the reader IS signed in, the company filter is already on and scoped to
        // whichever business they currently have active - so asking for the
        // invoice here found nothing whenever the reader was not standing inside
        // the business that issued it, and the page 404'd. That is what the
        // platform owner hit on every member's invoice: the row was there, his
        // filter simply could not see it.
        //
        // The unguessable uuid in the URL is what authorises this, exactly as it
        // does for the customer. Nothing else about the request is trusted, and
        // the very next thing that happens is switchCompany() below, which points
        // the filter at the ISSUING business for everything the page renders.
        $entity = $this->withoutCompanyFilter(static fn () => $repository->findOneBy(['uuid' => $options['uuid']]));

        if (null === $entity) {
            throw new NotFoundHttpException(sprintf('"%s" with id %s does not exist', ucfirst((string) $options['entity']), $options['uuid']));
        }

        if ($this->emailVerificationGate->isCompanyGated($entity->getCompany())) {
            throw new NotFoundHttpException(sprintf('"%s" with id %s does not exist', ucfirst((string) $options['entity']), $options['uuid']));
        }

        // Somebody who works AT the business that issued this document gets the
        // staff page instead of the customer's copy - that is where the payments,
        // the history and the edit button are.
        //
        // Anybody else stays on the customer's copy, even when they are signed in.
        // The staff page is scoped to the company the reader currently has active,
        // so sending a reader there for another business's invoice ended in a bare
        // 404 - which is exactly what happened when the platform owner opened a
        // member's customer link to check it. It also meant the owner could never
        // see what their members' customers actually see. Now they see precisely
        // that: the customer's copy, issued by the member's business, with no
        // trace of whoever happens to be logged in.
        try {
            if ($this->authorizationChecker->isGranted('IS_AUTHENTICATED_REMEMBERED') && $this->readerWorksFor($entity->getCompany())) {
                return new RedirectResponse($this->router->generate($options['route'], ['id' => $entity->getId()]));
            }
        } catch (AuthenticationCredentialsNotFoundException) {
        }

        $entityId = null;

        if ($entity instanceof Invoice) {
            $entityId = $entity->getInvoiceId();
        } elseif ($entity instanceof Quote) {
            $entityId = $entity->getQuoteId();
        }

        $this->companySelector->switchCompany($entity->getCompany()->getId());

        // Handle PDF format
        if ('pdf' === $request->getRequestFormat() && $this->pdfGenerator->canPrintPdf()) {
            $html = $this->twig->render($options['pdfTemplate'], [$options['entity'] => $entity]);
            $filename = sprintf('%s_%s.pdf', $options['entity'], $entityId);

            return new PdfResponse($this->pdfGenerator->generate($html), $filename);
        }

        return [
            $options['entity'] => $entity,
            'title' => $options['entity'] . ' #' . $entityId,
            'template' => $options['template'],
        ];
    }
}
