@extends('layouts.app')
@section('content')
<x-page-heading eyebrow="Fleet / Continuing airworthiness" heading="Maintenance" description="Record completed checks and services with their next due date or airframe hours. The latest record of each check type per aircraft drives the alerts, so overdue and upcoming work is flagged before it affects the schedule.">
    <button id="add-record" class="button" type="button" disabled><x-icon name="plus" class="icon-sm" />Record maintenance</button>
</x-page-heading>

<div class="kpi-grid">
    <x-kpi label="Overdue" icon="alert" value="maintenance.overdue" tone="danger" meta="Past due date or hours" />
    <x-kpi label="Due soon" icon="clock" value="maintenance.due_soon" tone="warning" meta="Within {{ config('roster.maintenance_due_soon_days') }} days or {{ config('roster.maintenance_due_soon_hours') }} h" />
    <x-kpi label="In maintenance" icon="wrench" value="fleet.maintenance" meta="Airframes currently in work" :href="route('aircraft')" />
    <x-kpi label="Grounded (AOG)" icon="ban" value="fleet.grounded" meta="Airframes on ground" :href="route('aircraft')" />
</div>

<section class="panel" aria-labelledby="alerts-title">
    <div class="panel-heading">
        <div class="panel-title"><x-icon name="bell" /><h2 id="alerts-title">Maintenance due</h2><span id="alert-count" class="chip"></span></div>
        <span class="small muted">Due soon: within {{ config('roster.maintenance_due_soon_days') }} days or {{ config('roster.maintenance_due_soon_hours') }} airframe hours (base-local dates).</span>
    </div>
    <div id="alert-list" aria-live="polite" aria-busy="true"><p class="loading-block">Checking maintenance due items…</p></div>
</section>

<section class="panel" aria-labelledby="records-title">
    <div class="panel-heading">
        <div class="panel-title"><x-icon name="clipboard" /><h2 id="records-title">Maintenance log</h2><span id="record-count" class="chip"></span></div>
        <label class="record-filter"><span class="visually-hidden">Filter by aircraft</span><select id="aircraft-filter"><option value="">All aircraft</option></select></label>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th scope="col">Performed</th><th scope="col">Aircraft</th><th scope="col">Check / work</th><th scope="col">Hours at</th><th scope="col">Next due</th><th scope="col">Recorded by</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody id="record-rows"><tr class="loading-row"><td colspan="7">Loading maintenance log…</td></tr></tbody>
    </table></div>
</section>

<dialog id="record-dialog" class="dialog" aria-labelledby="record-dialog-title"><form id="record-form" novalidate>
    <div class="dialog-heading"><div><h2 id="record-dialog-title" data-dialog-title>Record maintenance</h2><p>Set a next due date, next due airframe hours, or both. Whichever comes first raises the alert.</p></div><button type="button" class="icon-button" data-close aria-label="Close"><x-icon name="x" /></button></div>
    <div class="form-grid">
        <label>Aircraft<select name="aircraft_id" required></select></label>
        <label>Check type<select name="kind" required>
            @foreach(\App\Models\MaintenanceRecord::KINDS as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select></label>
        <label class="span-2">Description<input name="title" required maxlength="150" placeholder="e.g. A-check 4A, propeller overhaul, weekly check"></label>
        <label>Performed on<input name="performed_on" type="date" required></label>
        <label>Airframe hours at completion<input name="airframe_hours_at" type="number" min="0" max="999999" step="0.1" class="mono"><span class="field-hint">Optional.</span></label>
        <label>Next due date<input name="next_due_on" type="date"><span class="field-hint">Calendar limit, base-local date.</span></label>
        <label>Next due airframe hours<input name="next_due_hours" type="number" min="0" max="999999" step="0.1" class="mono"><span class="field-hint">Hour limit, compared with current airframe hours.</span></label>
        <label class="span-2">Notes<textarea name="notes" maxlength="2000" placeholder="Work order, findings or deferred items"></textarea></label>
    </div>
    <div class="status" data-form-status role="alert"></div>
    <div class="form-actions"><button class="button button-secondary" type="button" data-close-secondary>Cancel</button><button class="button" type="submit">Save record</button></div>
</form></dialog>
@endsection
