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

namespace SolidInvoice\CoreBundle\Action\Order;

use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\StoreOrder;
use SolidInvoice\CoreBundle\Enum\OrderStatus;
use SolidInvoice\CoreBundle\Repository\CompanyRepository;
use SolidInvoice\CoreBundle\Repository\StoreOrderRepository;
use SolidInvoice\CoreBundle\Repository\StoreProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function sprintf;
use function trim;

/**
 * Create or edit a MobilesOnline order from the orders portal. A single action
 * serves both the "new" and "edit" routes (matching ManagePurchase); the order
 * team fills the customer + despatch details, and the office later prints the
 * label and advances the status.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ManageOrder extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StoreOrderRepository $orderRepository,
        private readonly StoreProductRepository $storeProductRepository,
        private readonly CompanySelector $companySelector,
        private readonly CompanyRepository $companyRepository,
    ) {
    }

    public function __invoke(Request $request, ?string $id = null): Response
    {
        $order = null;

        if ($id !== null) {
            $order = $this->entityManager->find(StoreOrder::class, $id);

            if (! $order instanceof StoreOrder) {
                $this->addFlash('error', 'Order not found.');

                return $this->redirectToRoute('_orders_list');
            }
        }

        if ($request->isMethod('POST')) {
            return $this->save($request, $order);
        }

        return $this->render('@SolidInvoiceCore/Order/form.html.twig', [
            'order' => $order,
            'products' => $this->storeProductRepository->findAllOrdered(),
            'statuses' => OrderStatus::cases(),
        ]);
    }

    private function save(Request $request, ?StoreOrder $order): Response
    {
        if (! $this->isCsrfTokenValid('order.manage', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirectToRoute('_orders_list');
        }

        $data = $request->request;
        $customerName = trim((string) $data->get('customer_name'));
        $model = trim((string) $data->get('model'));

        if ($customerName === '' || $model === '') {
            $this->addFlash('error', 'Customer name and product model are required.');

            return $order instanceof StoreOrder
                ? $this->redirectToRoute('_order_edit', ['id' => (string) $order->getId()])
                : $this->redirectToRoute('_order_new');
        }

        $isNew = ! $order instanceof StoreOrder;

        if ($isNew) {
            $company = $this->currentCompany();

            if (! $company instanceof Company) {
                $this->addFlash('error', 'No active company selected.');

                return $this->redirectToRoute('_orders_list');
            }

            $order = new StoreOrder();
            $order->setCompany($company)
                ->setOrderNumber($this->orderRepository->nextOrderNumber($company));
        }

        $status = OrderStatus::tryFrom((string) $data->get('status')) ?? $order->getStatus();

        $order->setCustomerName($customerName)
            ->setCustomerPhone(trim((string) $data->get('customer_phone')))
            ->setCustomerWhatsapp($this->nullable($data, 'customer_whatsapp'))
            ->setAddressLine(trim((string) $data->get('address_line')))
            ->setArea($this->nullable($data, 'area'))
            ->setCity($this->nullable($data, 'city'))
            ->setEmirate($this->nullable($data, 'emirate'))
            ->setCountry(trim((string) $data->get('country')) ?: 'United Arab Emirates')
            ->setModel($model)
            ->setStorage($this->nullable($data, 'storage'))
            ->setCondition($this->nullable($data, 'grade_condition'))
            ->setColor($this->nullable($data, 'color'))
            ->setQuantity((int) $data->get('quantity', 1))
            ->setPrice($this->decimal($data, 'price'))
            ->setPaymentStatus((string) $data->get('payment_status'))
            ->setCodAmount($this->nullableDecimal($data, 'cod_amount'))
            ->setCourier($this->nullable($data, 'courier'))
            ->setTrackingNumber($this->nullable($data, 'tracking_number'))
            ->setNotes($this->nullable($data, 'notes'))
            ->setStatus($status);

        if ($isNew) {
            $this->entityManager->persist($order);
        }

        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Order %s saved.', $order->getOrderNumber()));

        return $this->redirectToRoute('_orders_list');
    }

    private function currentCompany(): ?Company
    {
        $companyId = $this->companySelector->getCompany();

        return $companyId !== null ? $this->companyRepository->find($companyId) : null;
    }

    private function nullable(ParameterBag $data, string $key): ?string
    {
        $value = trim((string) $data->get($key));

        return $value === '' ? null : $value;
    }

    private function decimal(ParameterBag $data, string $key): string
    {
        $value = trim((string) $data->get($key));

        return $value === '' ? '0' : $value;
    }

    private function nullableDecimal(ParameterBag $data, string $key): ?string
    {
        $value = trim((string) $data->get($key));

        return $value === '' ? null : $value;
    }
}
