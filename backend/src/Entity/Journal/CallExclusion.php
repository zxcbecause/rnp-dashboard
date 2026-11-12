<?php

declare(strict_types=1);

namespace App\Entity\Journal;

use App\Entity\Auth\User;
use App\Entity\Org\OrgUnit;
use App\Enum\CallExclusionCategory;
use App\Enum\CallExclusionStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Request to leave an incoming call out of the statistics: missed for an objective reason (internet down,
 * telephony failure), spam, a test call, a call of our own staff. A manager asks with a reason, the head of the
 * unit approves or rejects; only approved exclusions change the metrics — the call leaves both the incoming and
 * the missed calls. Decisions are final and kept as an audit trail.
 *
 * The call is referenced by its Bitrix activity id without a foreign key: raw snapshots may be rewritten by
 * syncs, the request must survive that. Start time and responsible are copied for the history.
 */
#[ORM\Entity]
#[ORM\Table(name: 'call_exclusion')]
#[ORM\UniqueConstraint(name: 'uniq_call_exclusion_call', columns: ['call_id'])]
#[ORM\Index(name: 'idx_call_exclusion_unit_status', columns: ['org_unit_id', 'status'])]
class CallExclusion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrgUnit::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OrgUnit $orgUnit;

    /** Bitrix call activity id (raw_bitrix_call.id). */
    #[ORM\Column]
    private int $callId;

    /** Madrid time, as in raw_bitrix_call. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $callStartedAt;

    /** Bitrix user responsible for the call. */
    #[ORM\Column(nullable: true)]
    private ?int $responsibleId;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(length: 16, enumType: CallExclusionStatus::class)]
    private CallExclusionStatus $status = CallExclusionStatus::Pending;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $decisionNote = null;

    #[ORM\Column(length: 16, enumType: CallExclusionCategory::class, options: ['default' => 'technical'])]
    private CallExclusionCategory $category;

    public function __construct(OrgUnit $orgUnit, int $callId, \DateTimeImmutable $callStartedAt, ?int $responsibleId, string $reason, User $requestedBy, CallExclusionCategory $category = CallExclusionCategory::Technical)
    {
        $this->category = $category;
        $this->orgUnit = $orgUnit;
        $this->callId = $callId;
        $this->callStartedAt = $callStartedAt;
        $this->responsibleId = $responsibleId;
        $this->reason = $reason;
        $this->requestedBy = $requestedBy;
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrgUnit(): OrgUnit
    {
        return $this->orgUnit;
    }

    public function getCallId(): int
    {
        return $this->callId;
    }

    public function getCallStartedAt(): \DateTimeImmutable
    {
        return $this->callStartedAt;
    }

    public function getResponsibleId(): ?int
    {
        return $this->responsibleId;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getStatus(): CallExclusionStatus
    {
        return $this->status;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }

    public function decide(bool $approve, User $by, ?string $note): void
    {
        if (CallExclusionStatus::Pending !== $this->status) {
            throw new \LogicException('The decision on this call is already made.');
        }

        $this->status = $approve ? CallExclusionStatus::Approved : CallExclusionStatus::Rejected;
        $this->decidedBy = $by;
        $this->decidedAt = new \DateTimeImmutable();
        $this->decisionNote = $note;
    }

    public function getCategory(): CallExclusionCategory
    {
        return $this->category;
    }
}
