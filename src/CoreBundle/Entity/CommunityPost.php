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

namespace SolidInvoice\CoreBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SolidInvoice\CoreBundle\Repository\CommunityPostRepository;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function array_slice;
use function array_values;
use function count;
use function is_string;
use function trim;

/**
 * Something a member wanted to say to the rest of the market - stock they have,
 * stock they want, a price, a warning about a bad batch.
 *
 * A PLATFORM-level record: every member reads every post, so it deliberately
 * does not use the CompanyAware trait. The business that posted it is an
 * ordinary relation, kept so the feed can show who is talking.
 */
#[ORM\Table(name: CommunityPost::TABLE_NAME)]
#[ORM\Entity(repositoryClass: CommunityPostRepository::class)]
#[ORM\Index(name: 'community_post_hidden_idx', columns: ['hidden'])]
class CommunityPost
{
    final public const string TABLE_NAME = 'community_post';

    /** Small pictures, a few of them - a post, not an album. */
    final public const int MAX_IMAGES = 4;

    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    /**
     * Who said it, as they were at the time. A post that outlives the account
     * that wrote it still has to say who wrote it, or the thread stops making
     * sense to everybody who replied.
     */
    #[ORM\Column(name: 'author_name', type: Types::STRING, length: 191, nullable: true)]
    private ?string $authorName = null;

    #[ORM\Column(name: 'business_name', type: Types::STRING, length: 191, nullable: true)]
    private ?string $businessName = null;

    #[ORM\Column(name: 'body', type: Types::TEXT)]
    private string $body = '';

    /**
     * Paths under the marketplace media folder, in the order they were uploaded.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'images', type: Types::JSON, options: ['default' => '[]'])]
    private array $images = [];

    /**
     * Taken off the feed by the platform owner. Kept rather than deleted so the
     * owner can see what was said if the poster argues about it.
     */
    #[ORM\Column(name: 'hidden', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $hidden = false;

    #[ORM\Column(name: 'last_activity_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastActivityAt = null;

    /**
     * @var Collection<int, CommunityComment>
     */
    #[ORM\OneToMany(mappedBy: 'post', targetEntity: CommunityComment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['created' => 'ASC'])]
    private Collection $comments;

    public function __construct()
    {
        $this->comments = new ArrayCollection();
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): self
    {
        $this->company = $company;

        if ($company instanceof Company) {
            $this->businessName = $company->getName();
        }

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): self
    {
        $this->author = $author;

        if ($author instanceof User) {
            $name = trim(($author->getFirstName() ?? '') . ' ' . ($author->getLastName() ?? ''));
            $this->authorName = $name === '' ? null : $name;
        }

        return $this;
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    public function getBusinessName(): ?string
    {
        return $this->businessName;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = trim($body);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getImages(): array
    {
        return array_values(array_filter($this->images, static fn ($path): bool => is_string($path) && $path !== ''));
    }

    /**
     * @param list<string> $images
     */
    public function setImages(array $images): self
    {
        $clean = [];

        foreach ($images as $path) {
            if (is_string($path) && trim($path) !== '') {
                $clean[] = trim($path);
            }
        }

        $this->images = array_slice($clean, 0, self::MAX_IMAGES);

        return $this;
    }

    public function hasImages(): bool
    {
        return count($this->getImages()) > 0;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function setHidden(bool $hidden): self
    {
        $this->hidden = $hidden;

        return $this;
    }

    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt ?? ($this->getCreated() === null ? null : \DateTimeImmutable::createFromInterface($this->getCreated()));
    }

    public function touch(?\DateTimeInterface $at = null): self
    {
        $this->lastActivityAt = $at === null
            ? new \DateTimeImmutable()
            : \DateTimeImmutable::createFromInterface($at);

        return $this;
    }

    /**
     * @return Collection<int, CommunityComment>
     */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(CommunityComment $comment): self
    {
        $comment->setPost($this);
        $this->comments->add($comment);
        $this->touch($comment->getCreated());

        return $this;
    }

    /**
     * The replies anybody is allowed to read. A hidden reply stays on the record
     * but leaves the conversation.
     *
     * @return list<CommunityComment>
     */
    public function visibleComments(): array
    {
        $visible = [];

        foreach ($this->comments as $comment) {
            if (! $comment->isHidden()) {
                $visible[] = $comment;
            }
        }

        return $visible;
    }
}
