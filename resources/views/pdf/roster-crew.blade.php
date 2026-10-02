{{-- One page per crew member for a roster week (individual, selected or full sets). Data from RosterExportService::pdf(). --}}
@extends('pdf.layout')
@section('body')
@forelse($people as $person)
<div class="person">
    <h2>{{ $person['name'] }}</h2>
    <div class="doc-subtitle">{{ $person['rank_label'] }} · base {{ $person['base'] }} · {{ $subtitle }}</div>
    @include('pdf.partials.summary', ['summary' => $person['summary']])
    @if($person['duties'] === [] && $person['activities'] === [])
    <p class="empty">No duties or planned activities this week.</p>
    @else
    <table class="data">
        <thead><tr><th>Date</th><th>Duty</th><th>Seat</th><th>Route</th><th>Report (LT)</th><th>Release (LT)</th><th>Report / release (UTC)</th><th class="num">Block</th><th class="num">Duty</th></tr></thead>
        <tbody>
        @foreach($person['duties'] as $index => $duty)
        <tr @class(['alt' => $index % 2 === 1])><td>{{ $duty['date'] }}</td><td><strong>{{ $duty['code'] }}</strong> {{ $duty['aircraft'] }}</td><td>{{ $duty['seat'] }}</td><td>{{ $duty['route'] }}</td><td>{{ $duty['report_local'] }}</td><td>{{ $duty['release_local'] }}</td><td>{{ $duty['utc'] }}</td><td class="num">{{ $duty['block'] }}</td><td class="num">{{ $duty['duty'] }}</td></tr>
        @endforeach
        @foreach($person['activities'] as $activity)
        <tr><td>{{ $activity['date'] }}</td><td colspan="8" class="muted">{{ $activity['label'] }}@if($activity['times']) · {{ $activity['times'] }}@endif @if($activity['note']) · {{ $activity['note'] }}@endif</td></tr>
        @endforeach
        </tbody>
    </table>
    @endif
    <p class="note">Base local times (LT); calendar apps should use the .ics download, which carries UTC instants.</p>
</div>
@empty
<p class="empty">No crew to print for this selection.</p>
@endforelse
@endsection
