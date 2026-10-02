{{-- Operations dashboard shell (staff). Data and interactions: resources/js/pages/dashboard.js (API: /api/v1/dashboard). --}}
@extends('layouts.app')
@section('content')
<x-page-heading eyebrow="Crew control / Overview" heading="Operations dashboard" description="Roster weeks, live roster conflicts, today's flying and the fleet, maintenance and crew document items that need attention, at a glance.">
    <a class="button" href="{{ route('roster') }}"><x-icon name="calendar" class="icon-sm" />Open weekly roster</a>
</x-page-heading>

{{-- Readiness KPIs; every card links to the page that resolves it. --}}
<div class="kpi-grid">
    <x-kpi label="Seat coverage" icon="users" value="roster.coverage" meta="This week's roster" :href="route('roster')" />
    <x-kpi label="Roster conflicts" icon="shield" value="roster.conflicts" meta="This and next week" :href="route('roster')" />
    <x-kpi label="Fleet available" icon="plane" value="fleet.available" tone="success" meta="Serviceable airframes" :href="route('aircraft')" />
    <x-kpi label="Flights enabled" icon="route" value="flights.enabled" tone="success" meta="Patterns available for planning" :href="route('flights')" />
    <x-kpi label="Maintenance alerts" icon="wrench" value="maintenance.alerts" meta="Overdue or due soon" :href="route('maintenance')" />
    <x-kpi label="Document alerts" icon="id-card" value="crew.documents" meta="Expired or expiring crew documents" :href="route('crew')" />
</div>

{{-- Last, this, next and the following roster week, each with coverage and conflicts. --}}
<section class="panel" aria-labelledby="weeks-title">
    <div class="panel-heading"><div class="panel-title"><x-icon name="calendar" /><h2 id="weeks-title">Roster weeks</h2></div><span class="small muted">Rosters run Monday to Sunday at base</span></div>
    <div id="week-cards" class="week-cards" aria-live="polite" aria-busy="true"><p class="loading-block">Loading roster weeks…</p></div>
</section>

<div class="dashboard-split">
    {{-- Live conflicts from this and next week's rosters (rule breaks first). --}}
    <section class="panel" aria-labelledby="conflicts-title">
        <div class="panel-heading"><div class="panel-title"><x-icon name="shield" /><h2 id="conflicts-title">Roster conflicts</h2><span id="conflict-count" class="chip"></span></div><span class="small muted">Checked live against leave, documents, ratings and duty limits</span></div>
        <div id="conflict-list" class="dashboard-list" aria-live="polite" aria-busy="true"><p class="loading-block">Checking rosters…</p></div>
    </section>
    {{-- Today's trips with how many seats are filled. --}}
    <section class="panel" aria-labelledby="today-title">
        <div class="panel-heading"><div class="panel-title"><x-icon name="clock" /><h2 id="today-title">Today's flying</h2></div><span id="today-date" class="chip mono"></span></div>
        <div id="today-list" class="dashboard-list" aria-live="polite" aria-busy="true"><p class="loading-block">Loading today's trips…</p></div>
        <div class="panel-footer"><span>Times are base local; seats come from this week's roster.</span></div>
    </section>
</div>

{{-- Fleet, maintenance and crew document items that can affect planning. --}}
<section class="panel" aria-labelledby="attention-title">
    <div class="panel-heading"><div class="panel-title"><x-icon name="bell" /><h2 id="attention-title">Needs attention</h2></div><span class="small muted">Fleet status, maintenance due and crew documents</span></div>
    <div id="attention-list" aria-live="polite" aria-busy="true"><p class="loading-block">Checking fleet, maintenance and documents…</p></div>
</section>

{{-- Quick links to the setup pages. --}}
<div class="setup-links">
    <a href="{{ route('flights') }}" class="link-card"><span class="link-card-icon"><x-icon name="route" /></span><h2>Flights &amp; routes</h2><p>Operating days, connected legs, night stops and enabling or disabling patterns.</p></a>
    <a href="{{ route('aircraft') }}" class="link-card"><span class="link-card-icon"><x-icon name="plane" /></span><h2>Fleet</h2><p>Airframes by registration and whether each is available, in maintenance, grounded or unavailable.</p></a>
    <a href="{{ route('maintenance') }}" class="link-card"><span class="link-card-icon"><x-icon name="wrench" /></span><h2>Maintenance</h2><p>Completed checks with next due dates or hours, and alerts before they fall due.</p></a>
    <a href="{{ route('crew') }}" class="link-card"><span class="link-card-icon"><x-icon name="users" /></span><h2>Crew directory</h2><p>Ratings, bases, weekly working hours and licence, medical and recurrent expiry dates.</p></a>
</div>
@endsection
