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

use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\UnlockCode;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Repository\UnlockCodeRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use function preg_replace;
use function trim;

/**
 * Public, no-login IMEI unlock-code lookup. A customer types their IMEI, hits
 * search, and gets their code back - and nothing else (no model, no carrier).
 */
final readonly class PublicUnlockLookup
{
    /**
     * The support WhatsApp number customers are pointed to (international format,
     * digits only, for wa.me links).
     */
    private const WHATSAPP_NUMBER = '971585678669';

    public function __construct(
        private CompanyRepository $companyRepository,
        private UnlockCodeRepository $unlockCodeRepository,
    ) {
    }

    /**
     * @return array{company: Company, searched: bool, imei: string, result: ?UnlockCode, whatsappUrl: string}
     */
    #[Template('@SolidInvoiceCore/Unlock/public.html.twig')]
    public function __invoke(Request $request): array
    {
        $raw = trim((string) $request->query->get('imei', ''));
        $imei = (string) preg_replace('/\D+/', '', $raw);
        $searched = $imei !== '';

        $result = $searched
            ? $this->unlockCodeRepository->findOneByImeiPublic($imei)
            : null;

        // Brand the page with the company that owns the matched code, or fall
        // back to the first company on the install (anonymous request, so the
        // company filter adds no constraint).
        $company = $result instanceof UnlockCode
            ? $result->getCompany()
            : $this->companyRepository->findOneBy([]);

        if (! $company instanceof Company) {
            throw new NotFoundHttpException();
        }

        return [
            'company' => $company,
            'searched' => $searched,
            'imei' => $imei,
            'result' => $result,
            'whatsappUrl' => 'https://wa.me/' . self::WHATSAPP_NUMBER,
        ];
    }
}
