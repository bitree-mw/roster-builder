{{-- Full weekly roster as a crew × day grid (landscape). Data from RosterExportService::pdf(). --}}
@extends('pdf.layout')
@section('body')
@include('pdf.partials.summary', ['summary' => $summary])
<table class="grid data">
    <thead><tr><th>Crew member</th>@foreach($days as $day)<th>{{ $day['label'] }}</th>@endforeach<th>Week</th></tr></thead>
    <tbody>
    {{-- Unfilled seats first so they are not overlooked on paper. --}}
    @if($open)
    <tr><td class="crew"><strong>Open time</strong><br><span class="small">Seats with no legal crew</span></td>
        @foreach($days as $day)<td>@foreach($open[$day['date']] ?? [] as $seat)<div class="duty"><strong>{{ $seat['code'] }}</strong> {{ $seat['seat'] }}</div>@endforeach</td>@endforeach
        <td></td></tr>
    @endif
    @foreach($rows as $index => $row)
    <tr @class(['alt' => $index % 2 === 1])>
        <td class="crew"><strong>{{ $row['name'] }}</strong><br><span class="small">{{ $row['rank'] }} · {{ $row['base'] }}</span></td>
        @foreach($days as $day)
        <td>
            @foreach($row['cells'][$day['date']] ?? [] as $item)
                @if($item['kind'] === 'duty')
                <div class="duty"><strong>{{ $item['code'] }}</strong> {{ $item['seat'] }}<br>{{ $item['times'] }}</div>
                @else
                <div class="activity">{{ $item['label'] }}@if($item['times']) {{ $item['times'] }}@endif</div>
                @endif
            @endforeach
        </td>
        @endforeach
        <td class="num">{{ $row['week'] }}</td>
    </tr>
    @endforeach
    </tbody>
</table>
<p class="note">Duty times are base local (LT), report to release. "Week" is duty time this week including timed SIM and standby.</p>
@endsection
