<?php

namespace App\Services;

use App\Models\CrewMember;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Business operations for crew members, their aircraft ratings and expiry documents.
 */
class CrewMemberService
{
    public function __construct(private AuditService $audit) {}

    /**
     * Create or replace a crew member. Ratings and documents are synchronised from the full payload (a PUT is a
     * complete replacement), all inside one transaction with the audit entry.
     *
     * @param  array<string, mixed>  $data  validated CrewMemberRequest payload including rating_ids and documents
     */
    public function save(array $data, User $actor, ?CrewMember $crew = null): CrewMember
    {
        return DB::transaction(function () use ($data, $actor, $crew): CrewMember {
            $crew ??= new CrewMember;
            $before = $crew->exists ? $crew->load('ratings', 'documents')->toArray() : null;
            $crew->fill(Arr::except($data, ['rating_ids', 'documents']))->save();
            $crew->ratings()->sync($data['rating_ids']);
            // Documents are replaced wholesale: one row per kind, and an omitted kind means "not recorded".
            $crew->documents()->delete();
            $crew->documents()->createMany($data['documents']);
            $crew->load('ratings', 'documents');
            $this->audit->record($actor, $before ? 'updated' : 'created', $crew, $before, $crew->toArray());

            return $crew;
        });
    }

    /**
     * Delete a crew member who has never been rostered, linked to an account or emailed. Anyone with history
     * must be marked inactive instead so past rosters stay intact.
     *
     * @throws ValidationException when history exists
     */
    public function delete(CrewMember $crew, User $actor): void
    {
        DB::transaction(function () use ($crew, $actor): void {
            if ($crew->assignments()->exists() || User::where('crew_member_id', $crew->id)->exists() || EmailLog::where('crew_member_id', $crew->id)->exists()) {
                throw ValidationException::withMessages(['crew_member' => 'This crew member has account or roster history. Mark them inactive instead.']);
            }
            $this->audit->record($actor, 'deleted', $crew, $crew->load('ratings', 'documents')->toArray());
            $crew->delete();
        });
    }
}
