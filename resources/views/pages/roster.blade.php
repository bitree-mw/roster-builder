{{--
    Roster window. Rosters run for 1 week, 2 weeks or a calendar month. Staff create, build, review and edit
    them, plan leave/day off/standby/SIM by clicking an empty day, and open any crew member's individual
    roster; crew see only their own published duties.
    Data and interactions: resources/js/pages/roster.js (API: /api/v1/roster-periods, /api/v1/assignments).
    data-today is today's base-local date so the week timeline never depends on the browser's timezone.
--}}
@extends('layouts.app')
@section('content')
@php($staff = auth()->user()->isStaff())
<div id="roster-root" data-today="{{ $today }}" hidden></div>
<x-page-heading :eyebrow="$staff ? 'Crew control / Roster' : 'My roster'" :heading="$staff ? 'Roster' : 'My roster'" :description="$staff ? 'Rosters run for one week, two weeks or a calendar month. The generator fills every seat with legal crew, balancing each person\'s working hours; you can then change any seat, and click an empty day to plan leave, a day off, standby or SIM. Past rosters are kept as read-only history.' : 'Your published duties day by day. Only rosters released by crew control are shown.'">
    @if($staff)
    {{-- Roster actions; roster.js shows only those that apply. New rosters are created from the "No roster" view. --}}
    <button id="build-week" class="button" type="button" hidden><x-icon name="zap" class="icon-sm" /><span data-label>Build roster</span></button>
    <button id="publish-week" class="button button-secondary" type="button" hidden><x-icon name="send" class="icon-sm" />Publish to crew</button>
    <button id="reopen-week" class="button button-secondary" type="button" hidden><x-icon name="rotate" class="icon-sm" />Reopen as draft</button>
    <button id="email-week" class="button button-secondary" type="button" hidden><x-icon name="mail" class="icon-sm" />Email crew</button>
    @endif
</x-page-heading>

{{-- Timeline: past and upcoming rosters (any length) and the gaps between them. --}}
<section class="panel" aria-labelledby="timeline-title">
    <div class="panel-heading">
        <div class="panel-title"><x-icon name="calendar" /><h2 id="timeline-title">Rosters</h2></div>
        <div class="toolbar">
            <button id="timeline-earlier" class="icon-button" type="button" aria-label="Earlier" title="Earlier"><x-icon name="chevron-left" /></button>
            <button id="timeline-today" class="button button-sm button-quiet" type="button">Today</button>
            <button id="timeline-later" class="icon-button" type="button" aria-label="Later" title="Later"><x-icon name="chevron-right" /></button>
        </div>
    </div>
    <div id="week-strip" class="week-strip" role="list" aria-label="Rosters" aria-busy="true"><p class="loading-block">Loading rosters…</p></div>
</section>

{{-- KPIs for the selected roster (staff: coverage and conflicts; crew: their own totals). --}}
<div class="kpi-grid">
    @if($staff)
    <x-kpi label="Seat coverage" icon="users" value="week.coverage" meta="Filled of required seats" />
    <x-kpi label="Open seats" icon="alert" value="week.open" meta="No legal crew available" />
    <x-kpi label="Rule conflicts" icon="shield" value="week.conflicts" meta="Must be resolved to publish" />
    <x-kpi label="Crew on duty" icon="users" value="week.crew" meta="With at least one duty" />
    <x-kpi label="Duty hours" icon="clock" value="week.hours" meta="All crew in this roster" />
    @else
    <x-kpi label="My duties" icon="calendar" value="week.duties" meta="Trips in this roster" />
    <x-kpi label="Duty time" icon="clock" value="week.duty" meta="Report to release" />
    <x-kpi label="Block time" icon="plane" value="week.block" meta="Flying time" />
    @endif
</div>

@unless($staff)
{{-- Crew only: accumulated hours from published rosters (GET /api/v1/my-hours). --}}
<section class="panel" aria-labelledby="my-hours-title">
    <div class="panel-heading"><div class="panel-title"><x-icon name="gauge" /><h2 id="my-hours-title">My hours</h2></div><span class="small muted">Published rosters only · flown once released, scheduled until then</span></div>
    <div id="my-hours" class="my-hours" aria-live="polite" aria-busy="true"><p class="loading-block">Loading your hours…</p></div>
</section>
@endunless

<div class="split roster-split">
    {{-- The selected roster: crew grid, individual roster, trips or conflicts. --}}
    <section class="panel roster-panel" aria-labelledby="week-title">
        <div class="panel-heading">
            <div class="panel-title"><h2 id="week-title">Roster</h2><span id="week-state" class="chip">Loading</span></div>
            @if($staff)
            <div class="toolbar">
                <div class="segmented" id="view-switch" role="group" aria-label="Roster view">
                    <button type="button" data-value="grid" aria-pressed="true">Crew grid</button>
                    <button type="button" data-value="individual" aria-pressed="false">Individual</button>
                    <button type="button" data-value="trips" aria-pressed="false">Trips</button>
                    <button type="button" data-value="conflicts" aria-pressed="false">Conflicts <span id="conflict-tab-count"></span></button>
                </div>
                <div class="segmented" id="rank-filter" role="group" aria-label="Filter by position">
                    <button type="button" data-value="" aria-pressed="true">All</button>
                    <button type="button" data-value="CPT" aria-pressed="false">CPT</button>
                    <button type="button" data-value="FO" aria-pressed="false">FO</button>
                    <button type="button" data-value="CC" aria-pressed="false">CC</button>
                </div>
                <div class="search"><x-icon name="search" class="icon-sm" /><label class="visually-hidden" for="crew-search">Search crew</label><input id="crew-search" type="search" placeholder="Crew or flight" autocomplete="off"></div>
                <label class="check-label small"><input id="duties-only" type="checkbox">Only crew with duties</label>
            </div>
            @endif
        </div>
        <div id="week-notice" class="week-notice" hidden></div>
        <div id="roster-view" aria-live="polite" aria-busy="true"><p class="loading-block">Loading roster…</p></div>
        <div class="panel-footer">
            {{-- Outputs for the selected week: CSV and PDF (staff: full grid or one page per crew member), a calendar file for crew, and print. --}}
            <span class="week-exports">
                <button id="download-csv" class="button button-sm button-secondary" type="button" disabled>CSV</button>
                <button id="download-pdf" class="button button-sm button-secondary" type="button" disabled>PDF</button>
                @if($staff)<button id="download-crew-pdf" class="button button-sm button-secondary" type="button" disabled>Crew pages PDF</button>@endif
                @unless($staff)<button id="download-calendar" class="button button-sm button-secondary" type="button" disabled><x-icon name="calendar" class="icon-sm" />Add to calendar</button>@endunless
                <button id="print-week" class="button button-sm button-quiet" type="button" disabled>Print</button>
                @if($staff)<button id="email-log" class="text-button" type="button" hidden>Email status</button>@endif
            </span>
            <span id="time-note">Duty times are base local (LT); UTC instants are shown in the inspector.</span>
            <span class="roster-legend" aria-label="Legend"><span class="legend-item" data-kind="duty">Duty</span>@if($staff)<span class="legend-item" data-kind="manual">Locked seat</span><span class="legend-item" data-kind="conflict">Conflict</span>@endif<span class="legend-item" data-kind="leave">Leave / off</span><span class="legend-item" data-kind="sim">SIM</span><span class="legend-item" data-kind="standby">Standby</span></span>
        </div>
    </section>

    {{-- Duty inspector: the selected trip, its seats, conflicts and why each crew member was chosen. --}}
    <aside class="panel sticky-panel inspector" aria-labelledby="inspector-title">
        <div class="panel-heading"><div class="panel-title"><x-icon name="eye" /><h2 id="inspector-title">Duty inspector</h2></div></div>
        <div id="inspector" class="inspector-body" aria-live="polite"></div>
    </aside>
</div>

@if($staff)
{{-- Seat editor: candidates (legal first) and, when a choice breaks a rule, the override reason. --}}
<dialog id="seat-dialog" class="dialog" aria-labelledby="seat-dialog-title"><form id="seat-form" novalidate>
    <div class="dialog-heading"><div><h2 id="seat-dialog-title">Change seat</h2><p id="seat-dialog-subtitle"></p></div><button type="button" class="icon-button" data-close aria-label="Close"><x-icon name="x" /></button></div>
    <fieldset>
        <legend>Choose a crew member <span class="field-hint">Legal choices are listed first, lowest fairness score (share of weekly hours used) first.</span></legend>
        <div id="candidate-list" class="candidate-list" aria-busy="true"><p class="loading-block">Checking crew…</p></div>
    </fieldset>
    <label id="override-field" hidden>Override reason<textarea name="override_reason" maxlength="500" placeholder="Why this assignment is acceptable despite the rule checks above"></textarea><span class="field-hint">Required to assign someone who breaks a rule. It is recorded with the seat and in the audit log.</span></label>
    <div class="status" data-form-status role="alert"></div>
    <div class="form-actions">
        <button id="clear-seat" class="button button-danger" type="button">Clear seat</button>
        <button class="button button-secondary" type="button" data-close-secondary>Cancel</button>
        <button class="button" type="submit">Assign</button>
    </div>
</form></dialog>

{{-- Day planning for one crew member: leave, day off, SIM or standby over a date range, and this week's items. --}}
<dialog id="plan-dialog" class="dialog" aria-labelledby="plan-dialog-title"><form id="plan-form" novalidate>
    <div class="dialog-heading"><div><h2 id="plan-dialog-title">Day planning</h2><p id="plan-dialog-subtitle">Leave, protected days off, simulator and standby.</p></div><button type="button" class="icon-button" data-close aria-label="Close"><x-icon name="x" /></button></div>
    <div id="plan-existing" class="plan-existing"></div>
    <div class="form-grid">
        <label>Type<select name="type"><option value="leave">Leave</option><option value="day_off">Protected day off</option><option value="sim">Simulator session</option><option value="standby">Standby</option></select></label>
        <span></span>
        <label>From<input name="date_from" type="date" required></label>
        <label>To<input name="date_to" type="date" required><span class="field-hint">Up to 31 days at a time.</span></label>
        <label data-timed>Starts (LT)<input name="starts_local" type="time"><span class="field-hint">Optional. With times, SIM and standby count as duty.</span></label>
        <label data-timed>Ends (LT)<input name="ends_local" type="time"></label>
        <label class="span-2">Note<input name="note" maxlength="1000" autocomplete="off"></label>
    </div>
    <div class="status" data-form-status role="alert"></div>
    <div class="form-actions">
        <button id="plan-calendar" class="button button-secondary" type="button"><x-icon name="calendar" class="icon-sm" />Calendar file</button>
        <button id="plan-pdf" class="button button-secondary" type="button">PDF page</button>
        <button class="button button-secondary" type="button" data-close-secondary>Close</button>
        <button class="button" type="submit">Add to plan</button>
    </div>
</form></dialog>

{{-- Delivery status of this week's roster emails. --}}
<dialog id="email-dialog" class="dialog" aria-labelledby="email-dialog-title"><form method="dialog">
    <div class="dialog-heading"><div><h2 id="email-dialog-title">Roster email status</h2><p>Emails are sent by the queue worker; refresh to see progress.</p></div><button type="submit" class="icon-button" aria-label="Close"><x-icon name="x" /></button></div>
    <div class="table-wrap"><table><thead><tr><th scope="col">Crew member</th><th scope="col">Address</th><th scope="col">Status</th><th scope="col">Sent</th></tr></thead><tbody id="email-rows"></tbody></table></div>
    <div class="form-actions"><button id="email-refresh" class="button button-secondary" type="button">Refresh</button><button class="button" type="submit">Close</button></div>
</form></dialog>
@endif
@endsection
