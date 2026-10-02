@extends('layouts.app')
@section('content')
<x-page-heading eyebrow="Network / Flight operations" heading="Flights & routes" description="Connected flight patterns with operating days and base-local times. Every duty starts and ends at a crew base. Disable a pattern to stop it being planned without losing its legs or history.">
    <button id="add-flight" class="button" type="button" disabled><x-icon name="plus" class="icon-sm" />Add flight</button>
</x-page-heading>

<div class="kpi-grid">
    <x-kpi label="Flight patterns" icon="route" value="flights.total" meta="Registered lines" />
    <x-kpi label="Enabled" icon="power" value="flights.enabled" tone="success" meta="Available for planning" />
    <x-kpi label="Disabled" icon="ban" value="flights.disabled" meta="Kept but not planned" />
    <x-kpi label="Night stops" icon="moon" value="flights.night" tone="info" meta="Multi-day rotations" />
    <x-kpi label="Aircraft warnings" icon="alert" value="flights.aircraft" tone="warning" meta="Enabled flights with no available airframe" />
</div>

<div class="split">
    <section class="panel" aria-labelledby="patterns-title">
        <div class="panel-heading">
            <div class="panel-title"><x-icon name="route" /><h2 id="patterns-title">Operating patterns</h2><span id="flight-count" class="chip"></span></div>
            <div class="toolbar">
                <div class="search"><x-icon name="search" class="icon-sm" /><label class="visually-hidden" for="flight-search">Search flights</label><input id="flight-search" type="search" placeholder="Code or airport" autocomplete="off"></div>
                <div class="segmented" id="state-filter" role="group" aria-label="Filter by status">
                    <button type="button" data-value="" aria-pressed="true">All</button>
                    <button type="button" data-value="enabled" aria-pressed="false">Enabled</button>
                    <button type="button" data-value="disabled" aria-pressed="false">Disabled</button>
                </div>
            </div>
        </div>
        <div class="table-wrap"><table class="flight-table">
            <thead><tr><th scope="col">Flight</th><th scope="col">Aircraft</th><th scope="col">Route</th><th scope="col">Dep – Arr (base local)</th><th scope="col">Block</th><th scope="col">Days</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody id="flight-rows"><tr class="loading-row"><td colspan="8">Loading flight patterns…</td></tr></tbody>
        </table></div>
        <div class="panel-footer"><span>Weekday 1 = Monday · times are base local (GMT+2 by default)</span><span>Select a row to inspect its rotation</span></div>
    </section>

    <aside class="panel sticky-panel" aria-labelledby="detail-title">
        <div class="panel-heading"><div class="panel-title"><x-icon name="clipboard" /><h2 id="detail-title">Rotation breakdown</h2></div></div>
        <div id="flight-detail" class="panel-body" aria-live="polite"><p class="muted small">Select a flight to see its legs, block time and status.</p></div>
    </aside>
</div>

<dialog id="flight-dialog" class="dialog" aria-labelledby="flight-dialog-title"><form id="flight-form" novalidate>
    <div class="dialog-heading"><div><h2 id="flight-dialog-title" data-dialog-title>Add flight</h2><p>Enter all times in base local time, including outstation legs. Use trip day 2–4 for night stops.</p></div><button type="button" class="icon-button" data-close aria-label="Close"><x-icon name="x" /></button></div>
    <div class="form-grid">
        <label>Flight code<input name="code" required maxlength="20" class="mono" placeholder="LB1"></label>
        <label>Aircraft type<select name="aircraft_type_id" required></select></label>
    </div>
    <fieldset><legend>Operating days</legend><div class="day-picker">
        @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $index => $day)
        <label><input type="checkbox" name="weekdays" value="{{ $index }}"><span>{{ $day }}</span></label>
        @endforeach
    </div></fieldset>
    <label class="check-label"><input name="active" type="checkbox" checked>Enabled for planning</label>
    <div>
        <div class="legs-heading"><h3>Ordered legs</h3><button id="add-leg" class="button button-sm button-quiet" type="button"><x-icon name="plus" class="icon-sm" />Add leg</button></div>
        <p class="field-hint">The first leg must depart a crew base and the final leg must return to it.</p>
        <div id="leg-editor"></div>
    </div>
    <div class="status" data-form-status role="alert"></div>
    <div class="form-actions"><button class="button button-secondary" type="button" data-close-secondary>Cancel</button><button class="button" type="submit">Save flight pattern</button></div>
</form></dialog>
@endsection
