<?php

namespace App\Services\Internships;

use App\Enums\ActiveStatus;
use App\Enums\InviteStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Internship;
use App\Models\InternshipInvite;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Invite links (§8, §9, §81, A16–A18). Only the SHA-256 of the token is stored.
 */
class InviteService
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * @return array{invite: InternshipInvite, token: string} The raw token is returned once and never stored.
     */
    public function create(User $actor, int $internshipId, ?CarbonInterface $expiresAt): array
    {
        $this->scope->requireAdmin($actor);
        $internship = $this->scope->findInternship($actor, $internshipId);
        $internship->load('currentPeriod.supervisor.user');
        $supervisor = $internship->currentPeriod?->supervisor;

        if ($supervisor === null || $supervisor->user->status !== ActiveStatus::Active) {
            throw new BusinessRuleException('Amaliyot guruhining rahbari faol emas. Avval rahbarni almashtiring.');
        }
        if ($expiresAt !== null && $expiresAt->isPast()) {
            throw new BusinessRuleException('Amal qilish muddati kelajakda bo‘lishi kerak.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $invite = DB::transaction(function () use ($actor, $internship, $supervisor, $expiresAt, $token) {
            $invite = InternshipInvite::query()->create([
                'internship_id' => $internship->id,
                'token_hash' => self::hash($token),
                'academic_year_id' => $internship->academic_year_id,
                'student_group_id' => $internship->student_group_id,
                'supervisor_profile_id' => $supervisor->id,
                'created_by' => $actor->id,
                'status' => InviteStatus::Active,
                'expires_at' => $expiresAt?->utc(),
            ]);

            $this->audit->log($actor, 'invite.create', $invite, null, [
                'internship_id' => $internship->id,
                'supervisor_profile_id' => $supervisor->id,
                'expires_at' => $expiresAt?->toIso8601String(),
            ], universityId: $internship->university_id);

            return $invite;
        });

        return ['invite' => $invite, 'token' => $token];
    }

    /**
     * ACTIVE → CLOSED. Students who already joined stay (§9).
     */
    public function close(User $actor, int $inviteId): InternshipInvite
    {
        $this->scope->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $inviteId) {
            $invite = InternshipInvite::query()
                ->whereIn('internship_id', $this->scope->internships($actor)->select('internships.id'))
                ->lockForUpdate()
                ->findOrFail($inviteId);

            if ($invite->effectiveStatus() !== InviteStatus::Active) {
                throw new BusinessRuleException('Havola allaqachon yopilgan yoki muddati tugagan.');
            }

            $invite->update(['status' => InviteStatus::Closed, 'closed_at' => now()]);

            $this->audit->log($actor, 'invite.close', $invite, ['status' => 'ACTIVE'], ['status' => 'CLOSED'], universityId: $actor->university_id);

            return $invite;
        });
    }

    /**
     * Scheduler: stored ACTIVE rows past expires_at become EXPIRED (A18).
     */
    public function expireDue(): int
    {
        return InternshipInvite::query()
            ->where('status', InviteStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => InviteStatus::Expired->value, 'updated_at' => now()]);
    }

    /**
     * @return Collection<int, InternshipInvite>
     */
    public function forInternship(Internship $internship)
    {
        return $internship->invites()->with('supervisor.user:id,name')->orderByDesc('id')->get();
    }
}
