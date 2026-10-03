{{--
    One crew member's roster as a PDF, attached to their roster email (App\Mail\RosterMail). Same data as the
    email (RosterEmailService::roster()): every day of the roster with flights (local and GMT times, legs and
    who they fly with), night stops and planned days. Rendered by PdfService on the shared PDF layout.
--}}
@extends('pdf.layout')
@section('body')
@include('pdf.partials.summary', ['summary' => ['Flights' => $roster['totals']['flights'], 'Block' => $roster['totals']['block'], 'Duty' => $roster['totals']['duty'], 'Planned days' => $roster['totals']['planned']]])
<table class="data">
    <thead><tr><th>Date</th><th>Duty</th><th>Times</th><th>Flying with</th></tr></thead>
    <tbody>
    @foreach($roster['days'] as $index => $day)
    <tr @class(['alt' => $index % 2 === 1])>
        <td><strong>{{ $day['weekday'] }} {{ $day['day'] }}</strong></td>
        @if($day['entries'] === [])
        <td colspan="3" class="muted">No duty</td>
        @else
        <td>
            @foreach($day['entries'] as $entry)
            <div>
            @if($entry['kind'] === 'duty')
                <strong>{{ $entry['code'] }}</strong> {{ $entry['route'] }} · {{ $entry['seat'] }}<br>
                <span class="small">{{ $entry['aircraft'] }} · block {{ $entry['block'] }}@if($entry['day_of_trip']) · {{ $entry['day_of_trip'] }}@endif</span>
                @if($entry['legs'] !== [])<br><span class="small">@foreach($entry['legs'] as $leg){{ $leg['from'] }}–{{ $leg['to'] }} {{ $leg['departs_local'] }}–{{ $leg['arrives_local'] }}{{ $loop->last ? '' : ' · ' }}@endforeach</span>@endif
            @elseif($entry['kind'] === 'layover')
                <strong>Night stop</strong> in {{ $entry['airport'] }} ({{ $entry['code'] }}, same crew)
            @else
                <strong>{{ $entry['label'] }}</strong>@if($entry['note']) <span class="small">· {{ $entry['note'] }}</span>@endif
            @endif
            </div>
            @endforeach
        </td>
        <td>
            @foreach($day['entries'] as $entry)
            <div>
            @if($entry['kind'] === 'duty')
                {{ $entry['report_local'] }}–{{ $entry['release_local'] }} LT<br><span class="small">{{ $entry['report_gmt'] }}–{{ $entry['release_gmt'] }} GMT</span>
            @elseif($entry['kind'] === 'activity' && $entry['times'])
                {{ $entry['times'] }} LT
            @endif
            </div>
            @endforeach
        </td>
        <td>
            @foreach($day['entries'] as $entry)
            @if($entry['kind'] === 'duty')
            <div>@foreach($entry['crew'] as $member)<span @class(['tone-warning' => $member['open']])>{{ $member['role'] }}: {{ $member['open'] ? 'not yet assigned' : $member['name'] }}{{ $member['you'] ? ' (you)' : '' }}</span><br>@endforeach</div>
            @endif
            @endforeach
        </td>
        @endif
    </tr>
    @endforeach
    </tbody>
</table>
<p class="note">Times are base local (LT) with GMT underneath. Contact crew control if anything looks wrong; the app always shows the latest version.</p>
@endsection
