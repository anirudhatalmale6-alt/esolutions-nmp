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

namespace SolidInvoice\InvoiceBundle\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SolidInvoice\ApiBundle\State\Processor\InvoiceLinePersistProcessor;
use SolidInvoice\CoreBundle\Doctrine\Type\BigIntegerType;
use SolidInvoice\CoreBundle\Entity\LineInterface;
use SolidInvoice\CoreBundle\Entity\StockGrade;
use SolidInvoice\CoreBundle\Entity\StockModel;
use SolidInvoice\CoreBundle\Traits\Entity\CompanyAware;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use SolidInvoice\InvoiceBundle\Enum\InvoiceLineType;
use SolidInvoice\InvoiceBundle\Repository\LineRepository;
use SolidInvoice\TaxBundle\Entity\LineTax;
use Stringable;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Throwable;

#[ORM\Table(name: Line::TABLE_NAME)]
#[ORM\Entity(repositoryClass: LineRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\MappedSuperclass]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string', enumType: InvoiceLineType::class)]
#[ORM\DiscriminatorMap(['invoice' => Line::class, 'recurring_invoice' => RecurringInvoiceLine::class])]
#[ApiResource(
    uriTemplate: '/invoices/{invoiceId}/lines',
    shortName: 'InvoiceLine',
    operations: [new GetCollection(), new Post(processor: InvoiceLinePersistProcessor::class)],
    uriVariables: [
        'invoiceId' => new Link(
            fromProperty: 'lines',
            fromClass: Invoice::class,
        ),
    ],
    normalizationContext: [
        AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
    ],
    denormalizationContext: [
        AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
    ]
)]
#[ApiResource(
    uriTemplate: '/invoices/{invoiceId}/line/{id}',
    shortName: 'InvoiceLine',
    operations: [new Get(), new Patch(), new Delete()],
    uriVariables: [
        'invoiceId' => new Link(
            fromProperty: 'lines',
            fromClass: Invoice::class,
        ),
        'id' => new Link(
            fromClass: Line::class,
        ),
    ],
    normalizationContext: [
        AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
    ],
    denormalizationContext: [
        AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
    ]
)]
class Line implements LineInterface, Stringable
{
    final public const string TABLE_NAME = 'invoice_lines';

    use TimeStampable;
    use CompanyAware;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[Groups(['invoice_api:read', 'recurring_invoice_api:read'])]
    protected ?Ulid $id = null;

    #[ORM\Column(name: 'description', type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Groups(['invoice_api:read', 'invoice_api:write', 'recurring_invoice_api:read', 'recurring_invoice_api:write'])]
    protected ?string $description = null;

    #[ORM\Column(name: 'price_amount', type: BigIntegerType::NAME)]
    #[Assert\NotBlank]
    #[Groups(['invoice_api:read', 'invoice_api:write', 'recurring_invoice_api:read', 'recurring_invoice_api:write'])]
    #[ApiProperty(
        openapiContext: [
            'type' => 'number',
        ],
        jsonSchemaContext: [
            'type' => 'number',
        ]
    )]
    protected BigNumber $price;

    #[ORM\Column(name: 'qty', type: Types::FLOAT)]
    #[Assert\NotBlank]
    #[Groups(['invoice_api:read', 'invoice_api:write', 'recurring_invoice_api:read', 'recurring_invoice_api:write'])]
    protected ?float $qty = 1;

    /**
     * Internal only: the IMEI number(s) of the handset(s) sold on this line,
     * stored comma-separated. Captured on the invoice form for warranty/claim
     * tracking and shown on the internal invoice view - deliberately kept off
     * the customer PDF and the public (external) invoice view.
     */
    #[ORM\Column(name: 'imei', type: Types::TEXT, nullable: true)]
    protected ?string $imei = null;

    /**
     * The stock item this line sells, when it is a stock item at all.
     *
     * Set by the model picker on the description box. Null means the line is not
     * stock (delivery, repair, a one-off charge) and so moves no quantity - the
     * description is still whatever was typed either way.
     */
    #[ORM\ManyToOne(targetEntity: StockModel::class)]
    #[ORM\JoinColumn(name: 'stock_model_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?StockModel $stockModel = null;

    /**
     * Which grade of that item is being sold.
     *
     * The same handset in A and in B are different things at different prices,
     * so a sale comes out of one grade, not out of the model as a whole. Null
     * where the item is not graded at all.
     */
    #[ORM\ManyToOne(targetEntity: StockGrade::class)]
    #[ORM\JoinColumn(name: 'stock_grade_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?StockGrade $stockGrade = null;

    /**
     * Internal only: when one line covers more than one grade.
     *
     * Stock is sometimes sold as a mix - a hundred handsets made up of sixty
     * Grade A and forty Grade B - and the customer is shown one line for a
     * hundred, with no mention of grades. That is a selling decision and not
     * something the system should force into the open by splitting the line in
     * two on the customer's invoice.
     *
     * So the customer sees one line, and this records what it was really made
     * of: grade id => quantity. The stock comes out of those exact grades, and
     * this never appears on the PDF or the public invoice view.
     *
     * Null / empty on the ordinary case, where the line sells one grade and
     * stockGrade above says which.
     *
     * @var array<string, int>|null
     */
    #[ORM\Column(name: 'grade_split', type: Types::JSON, nullable: true)]
    protected ?array $gradeSplit = null;

    /**
     * Internal only: anything about this line the customer must not read.
     *
     * The description is the customer's line and is printed exactly as typed,
     * so a note written there - "Mix Grade", "off the Hong Kong lot", whatever
     * was agreed on the phone - goes out on the invoice. This is where that
     * belongs instead. Shown on the staff view, never on the PDF, the public
     * link, or a printed copy of the staff view.
     */
    #[ORM\Column(name: 'internal_note', type: Types::TEXT, nullable: true)]
    protected ?string $internalNote = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[ApiProperty(
        writable: false,
        writableLink: false,
        example: '/api/invoices/3fa85f64-5717-4562-b3fc-2c963f66afa6'
    )]
    protected ?Invoice $invoice = null;

    /**
     * @var Collection<int, LineTax>
     */
    #[ORM\OneToMany(mappedBy: 'invoiceLine', targetEntity: LineTax::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['invoice_api:read', 'invoice_api:write', 'recurring_invoice_api:read', 'recurring_invoice_api:write'])]
    protected Collection $taxes;

    #[ORM\Column(name: 'total_amount', type: BigIntegerType::NAME)]
    #[Groups(['invoice_api:read', 'recurring_invoice_api:read'])]
    #[ApiProperty(
        writable: false,
        openapiContext: [
            'type' => 'number',
        ],
        jsonSchemaContext: [
            'type' => 'number',
        ]
    )]
    protected BigNumber $total;

    public function __construct()
    {
        $this->total = BigDecimal::zero();
        $this->price = BigDecimal::zero();
        $this->taxes = new ArrayCollection();
    }

    public function getId(): Ulid
    {
        return $this->id;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setStockModel(?StockModel $stockModel): static
    {
        $this->stockModel = $stockModel;

        return $this;
    }

    public function getStockModel(): ?StockModel
    {
        return $this->stockModel;
    }

    public function setStockGrade(?StockGrade $stockGrade): static
    {
        $this->stockGrade = $stockGrade;

        return $this;
    }

    public function getStockGrade(): ?StockGrade
    {
        return $this->stockGrade;
    }

    /**
     * What this line is really made of, when it is a mix.
     *
     * Cleaned on the way in: anything that is not a grade id with a positive
     * whole number against it is dropped, and an empty result is stored as null
     * so "not a mix" has exactly one representation.
     *
     * @param array<string, int|string>|null $gradeSplit
     */
    public function setGradeSplit(?array $gradeSplit): static
    {
        $clean = [];

        foreach ($gradeSplit ?? [] as $gradeId => $quantity) {
            $gradeId = trim((string) $gradeId);
            $quantity = is_numeric($quantity) ? (int) round((float) $quantity) : 0;

            if ($gradeId === '' || ! Ulid::isValid($gradeId) || $quantity <= 0) {
                continue;
            }

            $clean[$gradeId] = ($clean[$gradeId] ?? 0) + $quantity;
        }

        $this->gradeSplit = $clean === [] ? null : $clean;

        return $this;
    }

    /**
     * @return array<string, int>
     */
    public function getGradeSplit(): array
    {
        return $this->gradeSplit ?? [];
    }

    public function isMixedGrade(): bool
    {
        return $this->getGradeSplit() !== [];
    }

    /**
     * How many units the mix accounts for. Has to come to the line quantity,
     * or some of what was sold came out of nowhere.
     */
    public function gradeSplitTotal(): int
    {
        return array_sum($this->getGradeSplit());
    }

    public function getInternalNote(): ?string
    {
        $note = trim((string) $this->internalNote);

        return $note === '' ? null : $note;
    }

    public function setInternalNote(?string $internalNote): self
    {
        $note = trim((string) $internalNote);

        $this->internalNote = $note === '' ? null : $note;

        return $this;
    }

    /**
     * The words that must never reach a customer's copy.
     *
     * Grading is how the stock is bought and sorted, and it is deliberately not
     * something the customer is told - a lot sold as a mix is sold as a lot.
     * The description is printed exactly as typed, so a note like "Mix Grade"
     * written there goes out on the invoice, which is what this refuses.
     */
    private const string GRADE_WORDS = '/\b(grade|grades|grading|graded)\b/i';

    /**
     * Nothing about grading may be typed into the line the customer reads.
     *
     * This is not about stock accounting, so unlike the check below it applies
     * whether or not the business is keeping live figures - the customer's copy
     * is the customer's copy either way. The internal note is the way to record
     * it, and the message says so.
     */
    #[Assert\Callback]
    public function validateDescriptionIsCustomerSafe(ExecutionContextInterface $context): void
    {
        if (preg_match(self::GRADE_WORDS, (string) $this->description) !== 1) {
            return;
        }

        $context->buildViolation('The description is printed on the customer\'s invoice, so it cannot mention grading. Put it in the internal note instead - that is never printed.')
            ->atPath('description')
            ->addViolation();
    }

    /**
     * A sale has to say which grade it came out of - that is how the business
     * works, and it is the only thing keeping the grade figures and the item
     * total from drifting apart. Either one grade, or a mix that adds up.
     *
     * Silent while live stock tracking is off for the business: nothing is
     * moving quantities yet, so there is nothing to keep honest and no reason
     * to stand in the way of an invoice.
     */
    #[Assert\Callback]
    public function validateGrades(ExecutionContextInterface $context): void
    {
        $model = $this->stockModel;

        if (! $model instanceof StockModel || ! $this->tracksStock()) {
            return;
        }

        $grades = [];

        foreach ($model->getGrades() as $grade) {
            $grades[(string) $grade->getId()] = $grade->getGrade();
        }

        if ($grades === []) {
            return;
        }

        $split = $this->getGradeSplit();

        if ($split === []) {
            if (! $this->stockGrade instanceof StockGrade) {
                $context->buildViolation('Choose which grade of %model% is being sold, or set the mix.')
                    ->setParameter('%model%', $model->getName())
                    ->atPath('description')
                    ->addViolation();
            }

            return;
        }

        foreach (array_keys($split) as $gradeId) {
            if (! isset($grades[$gradeId])) {
                $context->buildViolation('The mix on %model% points at a grade that is not on this item.')
                    ->setParameter('%model%', $model->getName())
                    ->atPath('description')
                    ->addViolation();

                return;
            }
        }

        $sold = (int) round((float) ($this->qty ?? 0));
        $mixed = $this->gradeSplitTotal();

        if ($mixed !== $sold) {
            $context->buildViolation('The mix on %model% comes to %mixed% but the line sells %sold%.')
                ->setParameter('%model%', $model->getName())
                ->setParameter('%mixed%', (string) $mixed)
                ->setParameter('%sold%', (string) $sold)
                ->atPath('description')
                ->addViolation();
        }
    }

    /**
     * Whether the business behind this line is keeping its own stock figures.
     * A line being added to an invoice may not have been given the company yet
     * (that happens as it is saved), so the invoice is asked as well.
     */
    private function tracksStock(): bool
    {
        try {
            return ($this->getCompany() ?? $this->invoice?->getCompany())?->hasLiveStock() === true;
        } catch (Throwable) {
            return false;
        }
    }

    public function setImei(?string $imei): static
    {
        $imei = $imei !== null ? trim($imei) : null;
        $this->imei = ($imei === null || $imei === '') ? null : $imei;

        return $this;
    }

    public function getImei(): ?string
    {
        return $this->imei;
    }

    /**
     * @throws MathException
     */
    public function setPrice(BigNumber|int|string $price): static
    {
        $this->price = BigNumber::of($price);

        return $this;
    }

    public function getPrice(): BigNumber
    {
        return $this->price;
    }

    public function setQty(float $qty): static
    {
        $this->qty = $qty;

        return $this;
    }

    public function getQty(): ?float
    {
        return $this->qty;
    }

    public function setInvoice(?Invoice $invoice): static
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    /**
     * @throws MathException
     */
    public function setTotal(BigNumber|int|string $total): static
    {
        $this->total = BigNumber::of($total);

        return $this;
    }

    public function getTotal(): BigNumber
    {
        return $this->total;
    }

    /**
     * @return Collection<int, LineTax>
     */
    public function getTaxes(): Collection
    {
        return $this->taxes;
    }

    public function addTax(LineTax $lineTax): static
    {
        if (! $this->taxes->contains($lineTax)) {
            $this->taxes->add($lineTax);
            $lineTax->setInvoiceLine($this);
        }

        return $this;
    }

    public function removeTax(LineTax $lineTax): static
    {
        if ($this->taxes->removeElement($lineTax) && $lineTax->getInvoiceLine() === $this) {
            $lineTax->setInvoiceLine(null);
        }

        return $this;
    }

    /**
     * @throws MathException
     */
    #[ORM\PrePersist]
    public function updateTotal(): static
    {
        $this->total = $this->getPrice()->toBigDecimal()->multipliedBy($this->qty !== null ? (string) $this->qty : 1);

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->description;
    }
}
