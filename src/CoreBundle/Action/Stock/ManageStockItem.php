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

namespace SolidInvoice\CoreBundle\Action\Stock;

use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\StockGrade;
use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidInvoice\CoreBundle\Enum\StockMovementReason;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Repository\StockModelRepository;
use SolidInvoice\CoreBundle\Stock\StockLedger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use function count;
use function is_array;
use function is_numeric;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Create or rename a stock item and its grades by hand.
 *
 * Until now the only way an item could exist was a Tally upload, which is no
 * use to a vendor who has never run Tally - and on a portal with many
 * businesses, most of them have not. This is the way in for them: name the
 * handset, list the grades you hold and how many of each, and the opening
 * figures are recorded the same way an import would record them.
 *
 * Quantities are only accepted when the item is being created. After that they
 * belong to the ledger - a purchase, a sale, a count or a regrade - so that a
 * figure can never be quietly overwritten by editing a form.
 */
#[IsGranted('ROLE_MANAGER')]
final class ManageStockItem extends AbstractController
{
    public function __construct(
        private readonly StockModelRepository $stockModelRepository,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly StockLedger $ledger,
    ) {
    }

    public function __invoke(Request $request, ?string $id = null): Response
    {
        $model = null;

        if ($id !== null) {
            $model = Ulid::isValid($id) ? $this->stockModelRepository->find(Ulid::fromString($id)) : null;

            if (! $model instanceof StockModel) {
                throw $this->createNotFoundException();
            }
        }

        if ($request->isMethod('POST')) {
            return $this->save($request, $model);
        }

        return $this->render('@SolidInvoiceCore/Stock/item.html.twig', ['model' => $model]);
    }

    private function save(Request $request, ?StockModel $model): Response
    {
        if (! $this->isCsrfTokenValid('stock.item', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_stock_list');
        }

        $name = trim((string) $request->request->get('name'));

        if ($name === '') {
            $this->addFlash('error', 'Give the item a name.');

            return $this->form($request, $model);
        }

        $isNew = ! $model instanceof StockModel;

        // The same name must not exist twice: the Tally import finds the item it
        // is updating BY NAME, and so does the line-item picker, so a second
        // spelling silently splits one handset into two quantities. Checked on a
        // rename as well, not just when adding - itself excepted.
        $clash = $this->existingNamed($name, $model);

        if ($clash instanceof StockModel) {
            $this->addFlash('error', sprintf('You already hold an item called "%s".', $clash->getName()));

            return $this->form($request, $model, $clash);
        }

        if ($isNew) {
            $company = $this->currentCompany();

            if (! $company instanceof Company) {
                $this->addFlash('error', 'No active company selected.');

                return $this->redirectToRoute('_stock_list');
            }

            $model = new StockModel();
            $model->setCompany($company);
            $this->entityManager->persist($model);
        }

        $model->setName($name);

        $added = $this->addGrades($request, $model, $isNew);

        $this->entityManager->flush();

        $this->addFlash('success', $isNew
            ? sprintf('%s added%s.', $name, $added > 0 ? sprintf(' with %d grade%s', $added, $added === 1 ? '' : 's') : '')
            : sprintf('%s saved.', $name));

        return $this->redirectToRoute('_stock_movements', ['model' => (string) $model->getId()]);
    }

    /**
     * Add any grades named on the form that the item does not already have.
     *
     * On a NEW item the quantity typed beside each grade becomes its opening
     * figure, recorded as a Baseline movement so the number has a reason behind
     * it from the very first row. On an existing item the quantity boxes are
     * ignored: a new grade starts empty and is filled by a purchase, a count or
     * a regrade like everything else.
     */
    private function addGrades(Request $request, StockModel $model, bool $isNew): int
    {
        $names = $request->request->all('grade_name');
        $quantities = $request->request->all('grade_qty');

        if (! is_array($names)) {
            return 0;
        }

        $existing = [];

        foreach ($model->getGrades() as $grade) {
            $existing[strtolower($grade->getGrade())] = true;
        }

        $added = 0;

        for ($i = 0, $count = count($names); $i < $count; $i++) {
            $gradeName = trim((string) ($names[$i] ?? ''));

            if ($gradeName === '' || isset($existing[strtolower($gradeName)])) {
                continue;
            }

            $grade = new StockGrade();
            $grade->setGrade($gradeName);
            $model->addGrade($grade);
            $this->entityManager->persist($grade);
            $existing[strtolower($gradeName)] = true;
            ++$added;

            $qty = trim((string) (is_array($quantities) ? ($quantities[$i] ?? '') : ''));

            if (! $isNew || ! is_numeric($qty) || (int) $qty === 0) {
                continue;
            }

            $this->ledger->record(
                model: $model,
                quantity: (int) $qty,
                reason: StockMovementReason::Baseline,
                reference: 'Added by hand',
                grade: $grade,
                note: 'Opening figure entered when the item was created',
                flush: false,
            );
        }

        return $added;
    }

    /**
     * Re-render the form with everything that was just typed still in it.
     *
     * An item can carry half a dozen grades with their opening figures; sending
     * back an empty form over one rejected name means typing the lot again, and
     * that is how a name ends up being changed to something nobody else uses
     * just to get past the message.
     */
    private function form(Request $request, ?StockModel $model, ?StockModel $duplicate = null): Response
    {
        $names = $request->request->all('grade_name');
        $quantities = $request->request->all('grade_qty');
        $grades = [];

        if (is_array($names)) {
            for ($i = 0, $count = count($names); $i < $count; $i++) {
                $grades[] = [
                    'name' => trim((string) ($names[$i] ?? '')),
                    'qty' => trim((string) (is_array($quantities) ? ($quantities[$i] ?? '') : '')),
                ];
            }
        }

        return $this->render('@SolidInvoiceCore/Stock/item.html.twig', [
            'model' => $model,
            'name' => trim((string) $request->request->get('name')),
            'grades' => $grades,
            'duplicate' => $duplicate,
        ]);
    }

    /**
     * The item this company already holds under $name, if any. $self is the item
     * being edited, which is not a duplicate of itself.
     */
    private function existingNamed(string $name, ?StockModel $self): ?StockModel
    {
        $wanted = strtolower(trim($name));
        $selfId = $self instanceof StockModel && $self->getId() !== null ? (string) $self->getId() : null;

        foreach ($this->stockModelRepository->findAllOrdered() as $model) {
            if ($selfId !== null && (string) $model->getId() === $selfId) {
                continue;
            }

            if (strtolower(trim($model->getName())) === $wanted) {
                return $model;
            }
        }

        return null;
    }

    private function currentCompany(): ?Company
    {
        $companyId = $this->companySelector->getCompany();

        return $companyId !== null ? $this->companyRepository->find($companyId) : null;
    }
}
