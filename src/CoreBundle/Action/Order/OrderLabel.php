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
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use SolidInvoice\CoreBundle\Entity\StoreOrder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The printable 4x6 (100x150mm) thermal shipping label for an order - sender
 * (MobilesOnline return address) and recipient block, order number, courier /
 * tracking, COD amount and a scannable QR of the order number. Rendered as a
 * self-contained page sized to the label so it prints straight to a thermal
 * printer (and fits an A4 sheet fine too). IMEI/internal notes never appear here.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class OrderLabel extends AbstractController
{
    /** MobilesOnline return address printed as the sender on every label. */
    private const SENDER = [
        'name' => 'Mobiles Online - Online Seller',
        'line' => 'Deira, Dubai',
        'country' => 'United Arab Emirates',
        'phone' => '+971 58 585 8942',
        'licence' => 'Trade Licence 1596056 (Dubai DET)',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $order = $this->entityManager->find(StoreOrder::class, $id);

        if (! $order instanceof StoreOrder) {
            throw $this->createNotFoundException('Order not found.');
        }

        $qr = new QrCode(data: $order->getOrderNumber(), size: 200, margin: 0);
        $qrDataUri = (new SvgWriter())->write($qr)->getDataUri();

        return $this->render('@SolidInvoiceCore/Order/label.html.twig', [
            'order' => $order,
            'sender' => self::SENDER,
            'qr' => $qrDataUri,
        ]);
    }
}
