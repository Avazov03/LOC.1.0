<?php

namespace App\Services\Onboarding;

use App\Enums\ActiveStatus;
use App\Enums\InviteStatus;
use App\Enums\MembershipStatus;
use App\Enums\StudentStatus;
use App\Enums\UserRole;
use App\Models\InternshipInvite;
use App\Models\InternshipParticipant;
use App\Models\StudentGroupMembership;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Internships\InviteService;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Invite-based student onboarding (§7–§12, A8–A10, API-CONTRACT §3).
 * Channel-agnostic: the Telegram adapter (Phase 3) calls these methods; nothing here knows about Telegram.
 */
class StudentOnboardingService
{
    /**
     * What the student is joining, for the "Siz {course} {group} amaliyotiga qo‘shilmoqdasiz." prompt.
     *
     * @return array{invite_id: int, internship_id: int, university: string, program: string, course: string, group: string, academic_year: string, period_start: string, period_end: string}
     */
    public function context(string $rawToken): array
    {
        return $this->contextByHash(InviteService::hash($rawToken));
    }

    /**
     * Same as context(), keyed by the SHA-256 hash so a conversation never has to keep the raw token (A17).
     *
     * @return array{invite_id: int, internship_id: int, university: string, program: string, course: string, group: string, academic_year: string, period_start: string, period_end: string}
     */
    public function contextByHash(string $tokenHash): array
    {
        $invite = InternshipInvite::query()
            ->where('token_hash', $tokenHash)
            ->with(['internship.university', 'internship.academicYear', 'group.studyYear.program'])
            ->first();
        $this->assertUsable($invite);

        return [
            'invite_id' => $invite->id,
            'internship_id' => $invite->internship_id,
            'university' => $invite->internship->university->name,
            'program' => $invite->group->studyYear->program->name,
            'course' => $invite->group->studyYear->name,
            'group' => $invite->group->name,
            'academic_year' => $invite->internship->academicYear->name,
            'period_start' => $invite->internship->period_start->toDateString(),
            'period_end' => $invite->internship->period_end->toDateString(),
        ];
    }

    /**
     * Writes user, profile, membership and participant in one transaction. Never creates an assignment (A8).
     */
    public function join(string $rawToken, int $telegramUserId, string $firstName, string $lastName, string $phone, ?string $studentCode = null): StudentProfile
    {
        return $this->joinByHash(InviteService::hash($rawToken), $telegramUserId, $firstName, $lastName, $phone, $studentCode);
    }

    public function joinByHash(string $tokenHash, int $telegramUserId, string $firstName, string $lastName, string $phone, ?string $studentCode = null): StudentProfile
    {
        try {
            return DB::transaction(function () use ($tokenHash, $telegramUserId, $firstName, $lastName, $phone, $studentCode) {
                $invite = InternshipInvite::query()
                    ->where('token_hash', $tokenHash)
                    ->with('internship')
                    ->lockForUpdate()
                    ->first();
                $this->assertUsable($invite);

                if (StudentProfile::query()->where('telegram_user_id', $telegramUserId)->exists()) {
                    throw new OnboardingException(OnboardingException::ALREADY_REGISTERED);
                }

                $universityId = $invite->internship->university_id;
                if ($this->phoneRegistered($universityId, $phone)) {
                    throw new OnboardingException(OnboardingException::PHONE_REGISTERED);
                }
                $studentCode = $studentCode !== null && trim($studentCode) !== '' ? trim($studentCode) : null;
                if ($studentCode !== null && StudentProfile::query()->where('university_id', $universityId)->where('student_code', $studentCode)->exists()) {
                    throw new OnboardingException(OnboardingException::STUDENT_CODE_TAKEN);
                }

                $user = User::query()->create([
                    'university_id' => $universityId,
                    'name' => trim("{$lastName} {$firstName}"),
                    'role' => UserRole::Student,
                    'status' => ActiveStatus::Active,
                ]);

                $profile = StudentProfile::query()->create([
                    'user_id' => $user->id,
                    'university_id' => $universityId,
                    'current_group_id' => $invite->student_group_id,
                    'student_code' => $studentCode,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'telegram_user_id' => $telegramUserId,
                    'status' => StudentStatus::Active,
                ]);
                // The bot only accepts the phone from the sender's own contact button (A83).
                $profile->forceFill(['phone_verified_at' => now()])->save();

                $now = now();
                StudentGroupMembership::query()->create([
                    'student_profile_id' => $profile->id,
                    'student_group_id' => $invite->student_group_id,
                    'academic_year_id' => $invite->academic_year_id,
                    'internship_invite_id' => $invite->id,
                    'status' => MembershipStatus::Active,
                    'joined_at' => $now,
                ]);

                InternshipParticipant::query()->create([
                    'internship_id' => $invite->internship_id,
                    'student_profile_id' => $profile->id,
                    'internship_invite_id' => $invite->id,
                    'joined_at' => $now,
                ]);

                return $profile;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent join with the same Telegram id or student code won the race.
            $taken = StudentProfile::query()->where('telegram_user_id', $telegramUserId)->exists();

            throw new OnboardingException($taken ? OnboardingException::ALREADY_REGISTERED : OnboardingException::STUDENT_CODE_TAKEN);
        }
    }

    /**
     * Early check at the phone step; joinByHash() repeats it inside the transaction.
     */
    public function assertPhoneAvailable(string $tokenHash, string $phone): void
    {
        $invite = InternshipInvite::query()->where('token_hash', $tokenHash)->with('internship:id,university_id')->first();
        $this->assertUsable($invite);
        if ($this->phoneRegistered($invite->internship->university_id, $phone)) {
            throw new OnboardingException(OnboardingException::PHONE_REGISTERED);
        }
    }

    /**
     * Phones may have been edited by staff in any format, so they are compared by digits only.
     */
    private function phoneRegistered(int $universityId, string $phone): bool
    {
        $wanted = Phone::digits($phone);
        if ($wanted === '') {
            return false;
        }

        return StudentProfile::query()
            ->where('university_id', $universityId)
            ->whereNotNull('phone')
            ->pluck('phone')
            ->contains(fn (string $existing) => Phone::digits($existing) === $wanted);
    }

    /**
     * @phpstan-assert InternshipInvite $invite
     */
    private function assertUsable(?InternshipInvite $invite): void
    {
        if ($invite === null) {
            throw new OnboardingException(OnboardingException::INVALID_INVITE);
        }

        match ($invite->effectiveStatus()) {
            InviteStatus::Closed => throw new OnboardingException(OnboardingException::INVITE_CLOSED),
            InviteStatus::Expired => throw new OnboardingException(OnboardingException::INVITE_EXPIRED),
            InviteStatus::Active => null,
        };
    }
}
