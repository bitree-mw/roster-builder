{{-- Fleet page shell. Data and interactions: resources/js/pages/aircraft.js (API: /api/v1/aircraft, /aircraft-types). --}}
@extends('layouts.app')
@section('content')
<x-page-heading eyebrow="Fleet / Airworthiness" heading="Fleet" description="Registered airframes and whether each one is available to fly, plus the aircraft types used by flight routes. Mark an aircraft as not available (with an optional reason) so planners know what can fly.">
    <a class="button button-secondary" href="{{ route('maintenance') }}"><x-icon name="wrench" class="icon-sm" />Maintenance log</a>
    <button id="add-type" class="button button-secondary" type="button" disabled><x-icon name="plus" class="icon-sm" />Aircraft type</button>
    <button id="add-aircraft" class="button" type="button" disabled><x-icon name="plus" class="icon-sm" />Register aircraft</button>
</x-page-heading>

{{-- Fleet status counts. --}}
<div class="kpi-grid">
    <x-kpi label="Registered airframes" icon="plane" value="fleet.total" meta="Across all aircraft types" />
    <x-kpi label="Available" icon="check" value="fleet.available" tone="success" meta="Can be planned" />
    <x-kpi label="Not available" icon="ban" value="fleet.not_available" tone="warning" meta="Cannot be planned" />
</div>

{{-- Airframe cards with an availability filter. --}}
<section class="panel" aria-labelledby="airframes-title">
    <div class="panel-heading">
        <div class="panel-title"><x-icon name="plane" /><h2 id="airframes-title">Airframes</h2><span id="aircraft-count" class="chip"></span></div>
        <div class="segmented" id="status-filter" role="group" aria-label="Filter airframes by status">
            <button type="button" data-value="" aria-pressed="true">All</button>
            <button type="button" data-value="available" aria-pressed="false">Available</button>
            <button type="button" data-value="not" aria-pressed="false">Not available</button>
        </div>
    </div>
    <div id="aircraft-cards" class="airframe-grid" aria-live="polite" aria-busy="true"><p class="loading-block">Loading fleet…</p></div>
</section>

{{-- Aircraft types table. --}}
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

{{-- Register/edit airframe dialog (status is not edited here). --}}
<dialog id="aircraft-dialog" class="dialog" aria-labelledby="aircraft-dialog-title"><form id="aircraft-form" novalidate>
    <div class="dialog-heading"><div><h2 id="aircraft-dialog-title" data-dialog-title>Register aircraft</h2><p>New aircraft start as available. Use “Availability” on the card to mark one as not available.</p></div><button type="button" class="icon-button" data-close aria-label="Close"><x-icon name="x" /></button></div>
    <div class="form-grid">
        <label>Registration<input name="registration" required maxlength="12" placeholder="7Q-TBA" autocomplete="off" class="mono"><span class="field-hint">Letters, numbers and hyphens.</span></label>
        <label>Aircraft type<select name="aircraft_type_id" required></select></label>
        <label>Airframe hours<input name="airframe_hours" type="number" required min="0" max="999999" step="0.1" value="0" class="mono"><span class="field-hint">Keep current so hour-based maintenance alerts stay accurate.</span></label>
        <label class="span-2">Notes<textarea name="notes" maxlength="2000" placeholder="Optional configuration or ownership notes"></textarea></label>
    </div>
    <div class="status" data-form-status role="alert"></div>
    <div class="form-actions"><button class="button button-secondary" type="button" data-close-secondary>Cancel</button><button class="button" type="submit">Save aircraft</button></div>
</form></dialog>

{{-- Availability dialog: Available or Not available, with an optional reason. --}}
<dialog id="status-dialog" class="dialog" aria-labelledby="status-dialog-title"><form id="status-form" novalidate>
    <div class="dialog-heading"><div><h2 id="status-dialog-title">Aircraft availability</h2><p id="status-dialog-subtitle"></p></div><button type="button" class="icon-button" data-close aria-label="Close"><x-icon name="x" /></button></div>
    <fieldset><legend>Can this aircraft fly?</legend><div class="option-cards">
        <label class="option-card"><input type="radio" name="status" value="available" required><span><strong>Available</strong><small>Can be planned on flights.</small></span></label>
        <label class="option-card"><input type="radio" name="status" value="unavailable" required><span><strong>Not available</strong><small>Maintenance, AOG, stored or leased out.</small></span></label>
    </div></fieldset>
    <label>Reason <span class="field-hint">(optional)</span><textarea name="reason" maxlength="255" placeholder="e.g. A-check at LLW hangar until Friday"></textarea><span class="field-hint" id="reason-hint">Shown on the aircraft card and recorded in the audit log.</span></label>
    <div class="status" data-form-status role="alert"></div>
    <div class="form-actions"><button class="button button-secondary" type="button" data-close-secondary>Cancel</button><button class="button" type="submit">Save availability</button></div>
</form></dialog>

{{-- Add/edit aircraft type dialog. --}}
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
