@extends('layouts.app')
@section('content')
<x-page-heading eyebrow="Fleet / Airworthiness" heading="Fleet" description="Registered airframes, their current availability and the aircraft types used by flight patterns. Mark an aircraft as in maintenance, grounded or unavailable with a reason so planners know what can fly.">
    <button id="add-type" class="button button-secondary" type="button" disabled><x-icon name="plus" class="icon-sm" />Aircraft type</button>
    <button id="add-aircraft" class="button" type="button" disabled><x-icon name="plus" class="icon-sm" />Register aircraft</button>
</x-page-heading>

<div class="kpi-grid">
    <x-kpi label="Registered airframes" icon="plane" value="fleet.total" meta="Across all aircraft types" />
    <x-kpi label="Available" icon="check" value="fleet.available" tone="success" meta="Serviceable for planning" />
    <x-kpi label="In maintenance" icon="wrench" value="fleet.maintenance" tone="warning" meta="Checks or repairs in progress" />
    <x-kpi label="Grounded (AOG)" icon="ban" value="fleet.grounded" tone="danger" meta="Not airworthy until released" />
    <x-kpi label="Unavailable" icon="power" value="fleet.unavailable" meta="Leased out, stored or withdrawn" />
</div>

<section class="panel" aria-labelledby="airframes-title">
    <div class="panel-heading">
        <div class="panel-title"><x-icon name="plane" /><h2 id="airframes-title">Airframes</h2><span id="aircraft-count" class="chip"></span></div>
        <div class="segmented" id="status-filter" role="group" aria-label="Filter airframes by status">
            <button type="button" data-value="" aria-pressed="true">All</button>
            @foreach(\App\Models\Aircraft::STATUSES as $value => $label)
            <button type="button" data-value="{{ $value }}" aria-pressed="false">{{ $label }}</button>
            @endforeach
        </div>
    </div>
    <div id="aircraft-cards" class="airframe-grid" aria-live="polite" aria-busy="true"><p class="loading-block">Loading fleet…</p></div>
</section>

<section class="panel" aria-labelledby="types-title">
    <div class="panel-heading">
        <div class="panel-title"><x-icon name="grid" /><h2 id="types-title">Aircraft types</h2><span id="type-count" class="chip"></span></div>
        <span class="small muted">Flight patterns and crew ratings refer to aircraft types.</span>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th scope="col">Type</th><th scope="col">Cabin crew required</th><th scope="col">Roster palette</th><th scope="col">Airframes available</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody id="type-rows"></tbody>
    </table></div>
</section>

<dialog id="aircraft-dialog" class="dialog" aria-labelledby="aircraft-dialog-title"><form id="aircraft-form" novalidate>
    <div class="dialog-heading"><div><h2 id="aircraft-dialog-title" data-dialog-title>Register aircraft</h2><p>New aircraft start as available. Use “Change status” to ground or withdraw them.</p></div><button type="button" class="icon-button" data-close aria-label="Close"><x-icon name="x" /></button></div>
    <div class="form-grid">
        <label>Registration<input name="registration" required maxlength="12" placeholder="7Q-TBA" autocomplete="off" class="mono"><span class="field-hint">Letters, numbers and hyphens.</span></label>
        <label>Aircraft type<select name="aircraft_type_id" required></select></label>
        <label>Airframe hours<input name="airframe_hours" type="number" required min="0" max="999999" step="0.1" value="0" class="mono"><span class="field-hint">Keep current so hour-based maintenance alerts stay accurate.</span></label>
        <label class="span-2">Notes<textarea name="notes" maxlength="2000" placeholder="Optional configuration or ownership notes"></textarea></label>
    </div>
    <div class="status" data-form-status role="alert"></div>
    <div class="form-actions"><button class="button button-secondary" type="button" data-close-secondary>Cancel</button><button class="button" type="submit">Save aircraft</button></div>
</form></dialog>

<dialog id="status-dialog" class="dialog" aria-labelledby="status-dialog-title"><form id="status-form" novalidate>
    <div class="dialog-heading"><div><h2 id="status-dialog-title">Change aircraft status</h2><p id="status-dialog-subtitle"></p></div><button type="button" class="icon-button" data-close aria-label="Close"><x-icon name="x" /></button></div>
    <fieldset><legend>Status</legend><div class="option-cards">
        @foreach([
            'available' => 'Serviceable and can be planned.',
            'maintenance' => 'Scheduled or unscheduled work in progress.',
            'grounded' => 'Aircraft on ground — not airworthy until released.',
            'unavailable' => 'Leased out, stored or otherwise withdrawn.',
        ] as $value => $description)
        <label class="option-card"><input type="radio" name="status" value="{{ $value }}" required><span><strong>{{ \App\Models\Aircraft::STATUSES[$value] }}</strong><small>{{ $description }}</small></span></label>
        @endforeach
    </div></fieldset>
    <label>Reason<textarea name="reason" maxlength="255" placeholder="e.g. Awaiting replacement propeller de-ice boot"></textarea><span class="field-hint" id="reason-hint">Required unless the aircraft is available. Recorded in the audit log.</span></label>
    <div class="status" data-form-status role="alert"></div>
    <div class="form-actions"><button class="button button-secondary" type="button" data-close-secondary>Cancel</button><button class="button" type="submit">Update status</button></div>
</form></dialog>

<dialog id="type-dialog" class="dialog" aria-labelledby="type-dialog-title"><form id="type-form" novalidate>
    <div class="dialog-heading"><div><h2 id="type-dialog-title" data-dialog-title>Add aircraft type</h2><p>Cabin complement is used when crewing every flight of this type.</p></div><button type="button" class="icon-button" data-close aria-label="Close"><x-icon name="x" /></button></div>
    <div class="form-grid">
        <label>Type code<input name="code" required maxlength="20" placeholder="Q400" class="mono"></label>
        <label>Cabin crew required<input name="cabin_crew_required" type="number" required min="0" max="20" value="2"></label>
        <label>Roster palette<select name="palette"><option value="forest">Forest</option><option value="gold">Gold</option><option value="sky">Sky</option><option value="plum">Plum</option><option value="coral">Coral</option></select></label>
    </div>
    <div class="status" data-form-status role="alert"></div>
    <div class="form-actions"><button class="button button-secondary" type="button" data-close-secondary>Cancel</button><button class="button" type="submit">Save aircraft type</button></div>
</form></dialog>
@endsection
