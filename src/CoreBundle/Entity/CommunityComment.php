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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use SolidInvoice\CoreBundle\Repository\CommunityCommentRepository;
use SolidInvoice\CoreBundle\Traits\Entity\TimeStampable;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;
use function trim;

/**
 * A reply to a community post.
 *
 * Like the post itself, a platform-level record - the whole market reads it.
 */
#[ORM\Table(name: CommunityComment::TABLE_NAME)]
#[ORM\Entity(repositoryClass: CommunityCommentRepository::class)]
class CommunityComment
{
    final public const string TABLE_NAME = 'community_comment';

    use TimeStampable;

    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;

    #[ORM\ManyToOne(targetEntity: CommunityPost::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CommunityPost $post = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(name: 'author_name', type: Types::STRING, length: 191, nullable: true)]
    private ?string $authorName = null;

    #[ORM\Column(name: 'business_name', type: Types::STRING, length: 191, nullable: true)]
    private ?string $businessName = null;

    #[ORM\Column(name: 'body', type: Types::TEXT)]
    private string $body = '';

    #[ORM\Column(name: 'hidden', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $hidden = false;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getPost(): ?CommunityPost
    {
        return $this->post;
    }

    public function setPost(?CommunityPost $post): self
    {
        $this->post = $post;

        return $this;
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

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function setHidden(bool $hidden): self
    {
        $this->hidden = $hidden;

        return $this;
    }
}
