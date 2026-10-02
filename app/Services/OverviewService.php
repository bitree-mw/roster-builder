<?php

namespace App\Services;

use App\Models\Aircraft;
use App\Models\AircraftType;
use App\Models\CrewDocument;
use App\Models\CrewMember;
use App\Models\Flight;

/** Operational counts for the workspace KPI strip and navigation badges. */
class OverviewService
{
    public function __construct(private ExpiryService $expiry, private MaintenanceService $maintenance) {}

    /**
     * Counts for crew (with document expiry), flights, fleet status and maintenance alerts.
     * Document alerts only consider active crew.
     *
     * @return array<string, array<string, int>>
     */
    public function summary(): array
    {
        $documentStates = CrewDocument::query()->whereHas('crewMember', fn ($query) => $query->where('active', true))->get(['expires_on'])
            ->map(fn (CrewDocument $document): string => $this->expiry->documentState($document->expires_on))->countBy();
        $fleet = Aircraft::query()->toBase()->select('status')->selectRaw('count(*) as total')->groupBy('status')->pluck('total', 'status');
        $crew = CrewMember::query()->where('active', true)->toBase()->select('rank')->selectRaw('count(*) as total')->groupBy('rank')->pluck('total', 'rank');
        $maintenance = $this->maintenance->alerts()->countBy('state');

        return [
            'crew' => [
                'active' => (int) $crew->sum(),
                'captains' => (int) ($crew['CPT'] ?? 0),
                'first_officers' => (int) ($crew['FO'] ?? 0),
                'cabin' => (int) ($crew['CC'] ?? 0),
                'documents_expired' => (int) $documentStates->get('expired', 0),
                'documents_due_soon' => (int) $documentStates->get('due_soon', 0),
            ],
            'flights' => [
                'enabled' => Flight::query()->where('active', true)->count(),
                'disabled' => Flight::query()->where('active', false)->count(),
            ],
            'fleet' => [
                'types' => AircraftType::query()->count(),
                'total' => (int) $fleet->sum(),
                ...collect(array_keys(Aircraft::STATUSES))->mapWithKeys(fn (string $status): array => [$status => (int) ($fleet[$status] ?? 0)])->all(),
            ],
            'maintenance' => [
                'overdue' => (int) $maintenance->get('overdue', 0),
                'due_soon' => (int) $maintenance->get('due_soon', 0),
            ],
        ];
    }
}
