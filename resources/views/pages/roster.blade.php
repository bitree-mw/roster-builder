{{-- Roster workspace shell. Data and interactions: resources/js/pages/roster.js. Crew see only their own published roster. --}}
@extends('layouts.app')
@section('content')
@php($staff = auth()->user()->isStaff())
<x-page-heading eyebrow="Crew control / Planning" heading="Roster workspace" :description="$staff ? 'Monthly roster periods and the operational readiness behind them: crew, flight patterns, fleet availability and maintenance.' : 'Your published personal roster. Only periods released by crew control are shown.'">
    @if($staff)
    <button id="create-period" class="button" type="button" hidden><x-icon name="plus" class="icon-sm" />Create draft period</button>
    @endif
</x-page-heading>

@if($staff)
{{-- Staff readiness KPIs and "needs attention" list. --}}
<div class="kpi-grid">
    <x-kpi label="Active crew" icon="users" value="crew.active" meta="CPT · FO · CC" :href="route('crew')" />
    <x-kpi label="Flights enabled" icon="route" value="flights.enabled" tone="success" meta="Patterns available for planning" :href="route('flights')" />
    <x-kpi label="Fleet available" icon="plane" value="fleet.available" tone="success" meta="Serviceable airframes" :href="route('aircraft')" />
    <x-kpi label="Maintenance alerts" icon="wrench" value="maintenance.alerts" meta="Overdue or due soon" :href="route('maintenance')" />
    <x-kpi label="Document alerts" icon="id-card" value="crew.documents" meta="Expired or expiring crew documents" :href="route('crew')" />
</div>

<section class="panel" aria-labelledby="attention-title">
    <div class="panel-heading"><div class="panel-title"><x-icon name="bell" /><h2 id="attention-title">Needs attention</h2></div><span class="small muted">Fleet and maintenance items that can affect planning</span></div>
    <div id="attention-list" aria-live="polite" aria-busy="true"><p class="loading-block">Checking fleet and maintenance…</p></div>
</section>
@endif

{{-- Monthly roster period with month navigation (draft creation is staff-only). --}}
<section class="panel" aria-labelledby="period-title">
    <div class="panel-heading">
        <div class="period-nav">
            <button id="previous-month" class="icon-button" type="button" aria-label="Previous month"><x-icon name="chevron-left" /></button>
            <label class="visually-hidden" for="month">Roster month</label>
            <select id="month" class="month-select"></select>
            <button id="next-month" class="icon-button" type="button" aria-label="Next month"><x-icon name="chevron-right" /></button>
        </div>
        <div class="panel-title"><h2 id="period-title">Monthly roster</h2><span id="period-state" class="chip">Loading</span></div>
    </div>
    <div id="roster-content" aria-live="polite" aria-busy="true"><p class="loading-block">Loading roster period…</p></div>
</section>

@if($staff)
{{-- Quick links to the setup pages. --}}
<div class="setup-links">
    <a href="{{ route('flights') }}" class="link-card"><span class="link-card-icon"><x-icon name="route" /></span><h2>Flights &amp; routes</h2><p>Operating days, connected legs, night stops and enabling or disabling patterns.</p></a>
    <a href="{{ route('aircraft') }}" class="link-card"><span class="link-card-icon"><x-icon name="plane" /></span><h2>Fleet</h2><p>Airframes by registration and whether each is available, in maintenance, grounded or unavailable.</p></a>
    <a href="{{ route('maintenance') }}" class="link-card"><span class="link-card-icon"><x-icon name="wrench" /></span><h2>Maintenance</h2><p>Completed checks with next due dates or hours, and alerts before they fall due.</p></a>
    <a href="{{ route('crew') }}" class="link-card"><span class="link-card-icon"><x-icon name="users" /></span><h2>Crew directory</h2><p>Ratings, bases, and licence, medical and recurrent expiry dates.</p></a>
</div>
@endif
@endsection
