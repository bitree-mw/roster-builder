<?php

namespace App\Services;

use App\Models\CrewActivity;
use App\Models\CrewMember;
use App\Models\RuleSet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Day planning for crew: leave, protected days off, simulator sessions and standby. One item per crew
 * member per day. Local times are converted to UTC with the base offset from the duty rules (never the
 * server or browser timezone); an end time at or before the start time runs past midnight.
 *
 * Planning an item over a rostered duty is allowed: the roster's conflict check then reports it, so crew
 * control can see what needs to change.
 */
class CrewActivityService
{
    /** Labels used in messages. */
    public const LABELS = ['leave' => 'Leave', 'day_off' => 'Day off', 'sim' => 'Simulator session', 'standby' => 'Standby'];

    public function __construct(private AuditService $audit) {}

    /**
     * Create one item per date in the range, all or nothing.
     *
     * @param  array{crew_member_id: int, type: string, date_from: string, date_to: string, starts_local?: ?string, ends_local?: ?string, note?: ?string}  $data
     * @return Collection<int, CrewActivity>
     *
     * @throws ValidationException when any date already has an item
     */
    public function create(array $data, User $actor): Collection
    {
        return DB::transaction(function () use ($data, $actor): Collection {
            $crew = CrewMember::query()->lockForUpdate()->findOrFail($data['crew_member_id']);
            $from = CarbonImmutable::parse($data['date_from'], 'UTC');
            $to = CarbonImmutable::parse($data['date_to'], 'UTC');
            $taken = $crew->activities()->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])->orderBy('date')->get();
            if ($taken->isNotEmpty()) {
                $list = $taken->map(fn (CrewActivity $activity): string => $activity->date->format('d M').' ('.self::LABELS[$activity->type].')')->implode(', ');
                throw ValidationException::withMessages(['date_from' => 'Already planned on '.$list.'. Remove those first.']);
            }
            $offset = (int) (RuleSet::query()->whereKey(1)->value('utc_offset_minutes') ?? 120);
            $created = collect();
            for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                [$startsAt, $endsAt] = $this->instants($date, $data['starts_local'] ?? null, $data['ends_local'] ?? null, $offset);
                $created->push($crew->activities()->create(['date' => $date->format('Y-m-d'), 'type' => $data['type'], 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'note' => $data['note'] ?? null]));
            }
            foreach ($created as $activity) {
                $this->audit->record($actor, 'created', $activity, null, $activity->toArray());
            }

            return $created;
        });
    }

    /** Remove one item (including standby the generator planned). */
    public function delete(CrewActivity $activity, User $actor): void
    {
        DB::transaction(function () use ($activity, $actor): void {
            $this->audit->record($actor, 'deleted', $activity, $activity->toArray());
            $activity->delete();
        });
    }

    /**
     * UTC instants for base-local "HH:MM" times on a date, or nulls for a whole-day item.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function instants(CarbonImmutable $date, ?string $startsLocal, ?string $endsLocal, int $offset): array
    {
        if ($startsLocal === null || $endsLocal === null) {
            return [null, null];
        }
        $minutes = fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $start = $minutes($startsLocal);
        $end = $minutes($endsLocal);
        if ($end <= $start) {
            $end += 1440;
        }
        $midnight = $date->startOfDay();

        return [$midnight->addMinutes($start - $offset)->format('Y-m-d H:i:s'), $midnight->addMinutes($end - $offset)->format('Y-m-d H:i:s')];
    }
}
