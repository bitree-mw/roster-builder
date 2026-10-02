{{-- Crew hours report shell (staff). Data and interactions: resources/js/pages/hours.js (API: /api/v1/crew-hours). --}}
@extends('layouts.app')
@section('content')
<x-page-heading eyebrow="People / Hours" heading="Crew hours" description="Block and duty hours accumulated by each pilot and cabin crew member from published rosters: flown once a duty is released, scheduled until then. Duty includes timed simulator and standby sessions.">
</x-page-heading>

{{-- Group totals for the month (pilots or cabin crew, following the switch below). --}}
<div class="kpi-grid">
    <x-kpi label="Block flown this month" icon="plane" value="hours.block" meta="Whole group" />
    <x-kpi label="Duty this month" icon="clock" value="hours.duty" meta="Whole group, flown" />
    <x-kpi label="Average per person" icon="users" value="hours.average" meta="Block this month" />
    <x-kpi label="Highest this month" icon="gauge" value="hours.highest" meta="Block flown + scheduled" />
    <x-kpi label="Near monthly limit" icon="alert" value="hours.near" meta="80% of the block limit or more" />
</div>

<section class="panel" aria-labelledby="hours-title">
    <div class="panel-heading">
        <div class="panel-title"><x-icon name="gauge" /><h2 id="hours-title">Accumulated hours</h2><span id="hours-count" class="chip"></span></div>
        <div class="toolbar">
            <div class="segmented" id="group-switch" role="group" aria-label="Crew group">
                <button type="button" data-value="pilots" aria-pressed="true">Pilots</button>
                <button type="button" data-value="cabin" aria-pressed="false">Cabin crew</button>
            </div>
            <div class="search"><x-icon name="search" class="icon-sm" /><label class="visually-hidden" for="hours-search">Search crew</label><input id="hours-search" type="search" placeholder="Crew name or base" autocomplete="off"></div>
        </div>
    </div>
    <div class="table-wrap"><table class="hours-table">
        <thead><tr id="hours-head"><th scope="col">Crew member</th></tr></thead>
        <tbody id="hours-rows"><tr class="loading-row"><td colspan="6">Loading hours…</td></tr></tbody>
        <tfoot id="hours-totals"></tfoot>
    </table></div>
    <div class="panel-footer"><span id="hours-note">Only published weeks count. Dates are base local; a duty counts on its report date.</span></div>
</section>
@endsection
