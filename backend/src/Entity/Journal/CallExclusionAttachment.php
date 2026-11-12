<?php

declare(strict_types=1);

namespace App\Entity\Journal;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Screenshot attached to a call exclusion request (Gleb, 11.11.2026). Kept in the database on purpose (decision
 * 11.11.2026): it travels with the nightly dump and needs no volume of its own. A separate table, so that lists of
 * requests never load the image.
 */
#[ORM\Entity]
#[ORM\Table(name: 'call_exclusion_attachment')]
class CallExclusionAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: CallExclusion::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CallExclusion $exclusion;

    /** image/png | image/jpeg | image/webp, detected from the content. */
    #[ORM\Column(length: 32)]
    private string $contentType;

    #[ORM\Column]
    private int $size;

    /**
     * @var resource|string a stream once loaded from the database
     */
    #[ORM\Column(type: Types::BLOB, length: 16777215)]
    private mixed $content;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(CallExclusion $exclusion, string $contentType, string $content)
    {
        $this->exclusion = $exclusion;
        $this->contentType = $contentType;
        $this->size = \strlen($content);
        $this->content = $content;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExclusion(): CallExclusion
    {
        return $this->exclusion;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getContent(): string
    {
        if (\is_resource($this->content)) {
            rewind($this->content);

            return (string) stream_get_contents($this->content);
        }

        return (string) $this->content;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
