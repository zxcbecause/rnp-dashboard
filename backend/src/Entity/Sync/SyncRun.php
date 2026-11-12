<?php

declare(strict_types=1);

namespace App\Entity\Sync;

use App\Enum\SyncSource;
use App\Enum\SyncStatus;
use App\Repository\Sync\SyncRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal of data pulls from the platform API, Bitrix24, push sources and imports.
 */
#[ORM\Entity(repositoryClass: SyncRunRepository::class)]
#[ORM\Table(name: 'sync_run')]
#[ORM\Index(name: 'idx_sync_run_source_started', columns: ['source', 'started_at'])]
#[ORM\Index(name: 'idx_sync_run_scope_status', columns: ['source', 'scope', 'status', 'started_at'])]
class SyncRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: SyncSource::class)]
    private SyncSource $source;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 16, enumType: SyncStatus::class)]
    private SyncStatus $status = SyncStatus::Running;

    #[ORM\Column(name: '`rows`', options: ['unsigned' => true, 'default' => 0])]
    private int $rows = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    /** Resource inside the source, e.g. "bookings"; drives incremental syncs per resource. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $scope;

    public function __construct(SyncSource $source, ?string $scope = null)
    {
        $this->source = $source;
        $this->scope = $scope;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): SyncSource
    {
        return $this->source;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getStatus(): SyncStatus
    {
        return $this->status;
    }

    public function getRows(): int
    {
        return $this->rows;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function succeed(int $rows): void
    {
        $this->status = SyncStatus::Success;
        $this->rows = $rows;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function fail(string $error, int $rows = 0): void
    {
        $this->status = SyncStatus::Failed;
        $this->rows = $rows;
        $this->error = $error;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }
}
