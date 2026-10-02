<?php

namespace App\Services;

use App\Models\Aircraft;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance records and the due-item / alert logic built on them. Alerts never change aircraft status;
 * grounding stays a deliberate, audited decision.
 */
class MaintenanceService
{
    /**
     * Sort order: most urgent first.
     *
     * @var array<string, int>
     */
    private const SEVERITY = ['overdue' => 0, 'due_soon' => 1, 'ok' => 2];

    public function __construct(private AuditService $audit, private ExpiryService $expiry) {}

    /**
     * Record or correct completed maintenance. The recorder is taken from the signed-in user, never the payload.
     *
     * @param  array<string, mixed>  $data  validated MaintenanceRecordRequest payload
     *
     * @throws ValidationException when the next due hours do not exceed the hours at completion
     */
    public function save(array $data, User $actor, ?MaintenanceRecord $record = null): MaintenanceRecord
    {
        if (isset($data['airframe_hours_at'], $data['next_due_hours']) && $data['next_due_hours'] <= $data['airframe_hours_at']) {
            throw ValidationException::withMessages(['next_due_hours' => 'The next due hours must be greater than the airframe hours when the work was performed.']);
        }

        return DB::transaction(function () use ($data, $actor, $record): MaintenanceRecord {
            $record ??= new MaintenanceRecord;
            $before = $record->exists ? $record->toArray() : null;
            $record->fill($data);
            if (! $record->exists) {
                $record->recorded_by = $actor->id;
            }
            $record->save();
            $this->audit->record($actor, $before ? 'updated' : 'created', $record, $before, $record->toArray());

            return $record->load('aircraft.aircraftType');
        });
    }

    /**
     * Remove a record (for example one entered by mistake) with an audit entry.
     */
    public function delete(MaintenanceRecord $record, User $actor): void
    {
        DB::transaction(function () use ($record, $actor): void {
            $this->audit->record($actor, 'deleted', $record, $record->toArray());
            $record->delete();
        });
    }

    /**
     * The next due item per aircraft and kind: the most recent record of that kind, when it sets a due date or due hours.
     * A newer record of the same kind supersedes older ones.
     *
     * @return Collection<int, array{record: MaintenanceRecord, state: string, days_remaining: ?int, hours_remaining: ?float}>
     */
    public function dueItems(): Collection
    {
        return MaintenanceRecord::query()->with('aircraft.aircraftType')
            ->orderByDesc('performed_on')->orderByDesc('id')->get()
            ->unique(fn (MaintenanceRecord $record): string => $record->aircraft_id.'|'.$record->kind)
            ->filter(fn (MaintenanceRecord $record): bool => $record->next_due_on !== null || $record->next_due_hours !== null)
            ->map(fn (MaintenanceRecord $record): array => $this->evaluate($record))
            ->sort(fn (array $a, array $b): int => [self::SEVERITY[$a['state']], $a['days_remaining'] ?? PHP_INT_MAX, $a['hours_remaining'] ?? PHP_FLOAT_MAX]
                <=> [self::SEVERITY[$b['state']], $b['days_remaining'] ?? PHP_INT_MAX, $b['hours_remaining'] ?? PHP_FLOAT_MAX])
            ->values();
    }

    /**
     * Only items that are overdue or due soon, most urgent first.
     *
     * @return Collection<int, array{record: MaintenanceRecord, state: string, days_remaining: ?int, hours_remaining: ?float}>
     */
    public function alerts(): Collection
    {
        return $this->dueItems()->reject(fn (array $item): bool => $item['state'] === 'ok')->values();
    }

    /**
     * Attach each airframe's current due items as a "dueItems" relation for AircraftResource.
     * Runs one query for all airframes instead of one per airframe.
     *
     * @param  iterable<Aircraft>  $aircraft
     */
    public function attachDueItems(iterable $aircraft): void
    {
        $items = $this->dueItems()->groupBy(fn (array $item): int => $item['record']->aircraft_id);
        foreach ($aircraft as $airframe) {
            $airframe->setRelation('dueItems', $items->get($airframe->id, collect()));
        }
    }

    /**
     * Work out how urgent one record's next due point is. When both a date and hours are set, the more urgent
     * of the two wins ("whichever comes first").
     *
     * @return array{record: MaintenanceRecord, state: string, days_remaining: ?int, hours_remaining: ?float}
     */
    private function evaluate(MaintenanceRecord $record): array
    {
        $states = [];
        $days = null;
        $hours = null;
        if ($record->next_due_on !== null) {
            $days = $this->expiry->daysUntil($record->next_due_on);
            $states[] = match ($this->expiry->dateState($record->next_due_on, (int) config('roster.maintenance_due_soon_days'))) {
                'expired' => 'overdue',
                'due_soon' => 'due_soon',
                default => 'ok',
            };
        }
        if ($record->next_due_hours !== null) {
            $hours = round($record->next_due_hours - $record->aircraft->airframe_hours, 1);
            $states[] = $hours <= 0 ? 'overdue' : ($hours <= config('roster.maintenance_due_soon_hours') ? 'due_soon' : 'ok');
        }
        usort($states, fn (string $a, string $b): int => self::SEVERITY[$a] <=> self::SEVERITY[$b]);

        return ['record' => $record, 'state' => $states[0], 'days_remaining' => $days, 'hours_remaining' => $hours];
    }
}
