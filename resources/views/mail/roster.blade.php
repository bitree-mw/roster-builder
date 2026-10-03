{{--
    Roster email (HTML), built by App\Mail\RosterMail from RosterEmailService::roster(). Styles come from
    resources/css/mail/roster.css with the app's colour tokens and are inlined before sending (mail clients
    ignore most <style> blocks); $styles is also kept in the head for clients that support media queries.
    Layout uses tables because mail clients do not support flex or grid. Data is escaped by Blade.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>Your roster: {{ $roster['label'] }}</title>
<style>{!! $styles !!}</style>
</head>
<body>
<table class="wrapper" role="presentation" cellpadding="0" cellspacing="0"><tr><td align="center">
<table class="card" role="presentation" cellpadding="0" cellspacing="0">

    {{-- Header: logo, "Crew roster" and the roster dates. --}}
    <tr><td class="header">
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr>
            @if($logo)
            <td class="logo-cell" width="1%"><div class="logo-tile"><img class="logo" src="{{ $logo }}" alt="Malawi Airlines" height="34"></div></td>
            @endif
            <td>
                <div class="eyebrow">Crew roster · Malawi Airlines</div>
                <div class="title">{{ $roster['label'] }}</div>
                <div class="range">{{ $roster['range'] }}</div>
            </td>
        </tr></table>
    </td></tr>

    {{-- Greeting and totals. --}}
    <tr><td class="body">
        <p class="greeting">Dear {{ $crew->name }},</p>
        <p class="intro">Your published roster is below, day by day, with the crew you fly with. The attached calendar file adds every duty to your phone or mail calendar.</p>
        <table class="stats" role="presentation" cellpadding="0" cellspacing="0"><tr>
            <td class="stat"><span class="stat-label">Flights</span><span class="stat-value">{{ $roster['totals']['flights'] }}</span></td>
            <td class="stat"><span class="stat-label">Block</span><span class="stat-value">{{ $roster['totals']['block'] }}</span></td>
            <td class="stat"><span class="stat-label">Duty</span><span class="stat-value">{{ $roster['totals']['duty'] }}</span></td>
            <td class="stat"><span class="stat-label">Planned days</span><span class="stat-value">{{ $roster['totals']['planned'] }}</span></td>
        </tr></table>

        {{-- Every day of the roster. --}}
        <table class="days" role="presentation" cellpadding="0" cellspacing="0">
        @foreach($roster['days'] as $day)
            <tr class="day">
                <td class="date"><span class="weekday">{{ $day['weekday'] }}</span>{{ $day['day'] }}</td>
                <td>
                @forelse($day['entries'] as $entry)
                    @if($entry['kind'] === 'duty')
                    {{-- A flight: code, route, seat, times (local and GMT), legs, and the whole crew of the trip. --}}
                    <table class="strip" role="presentation" cellpadding="0" cellspacing="0"><tr>
                        <td>
                            <span class="code">{{ $entry['code'] }}</span> <span class="seat">{{ $entry['seat'] }}</span>
                            @if($entry['day_of_trip'])<span class="tag">{{ $entry['day_of_trip'] }}</span>@endif
                            <div class="route">{{ $entry['route'] }}</div>
                            <div class="meta">{{ $entry['aircraft'] }} · block {{ $entry['block'] }}</div>
                            @if($entry['legs'] !== [])
                            <div class="legs">@foreach($entry['legs'] as $leg){{ $leg['from'] }}→{{ $leg['to'] }} {{ $leg['departs_local'] }}–{{ $leg['arrives_local'] }}@if(! $loop->last) · @endif @endforeach</div>
                            @endif
                            <div class="crew-title">Flying with</div>
                            <table class="crew" role="presentation" cellpadding="0" cellspacing="0">
                            @foreach($entry['crew'] as $member)
                                <tr><td class="crew-role">{{ $member['role'] }}</td><td @class(['crew-name', 'you' => $member['you'], 'open' => $member['open']])>{{ $member['name'] }}@if($member['you']) (you)@endif</td></tr>
                            @endforeach
                            </table>
                        </td>
                        <td class="times times-cell" width="1%">{{ $entry['report_local'] }}–{{ $entry['release_local'] }} LT<span class="gmt">{{ $entry['report_gmt'] }}–{{ $entry['release_gmt'] }} GMT</span></td>
                    </tr></table>
                    @elseif($entry['kind'] === 'layover')
                    <table class="activity activity-layover" role="presentation" cellpadding="0" cellspacing="0"><tr><td><span class="activity-label">Night stop</span> · {{ $entry['code'] }} at {{ $entry['airport'] }}, same crew until back at base</td></tr></table>
                    @else
                    <table @class(['activity', 'activity-'.$entry['type'] => in_array($entry['type'], ['standby', 'sim'], true)]) role="presentation" cellpadding="0" cellspacing="0"><tr><td><span class="activity-label">{{ $entry['label'] }}</span>@if($entry['times']) · {{ $entry['times'] }} LT @endif @if($entry['note']) · {{ $entry['note'] }}@endif</td></tr></table>
                    @endif
                @empty
                    <span class="none">No duty</span>
                @endforelse
                </td>
            </tr>
        @endforeach
        </table>
    </td></tr>

    {{-- Notes, signature and footer. --}}
    <tr><td class="notes">
        Times are base local (LT) with GMT underneath. If anything looks wrong, contact crew control. This roster can still change until the day of operation; the app always shows the latest version.
        <p class="signature">{{ $signature }}</p>
    </td></tr>
    <tr><td class="footer">Sent by the Malawi Airlines Roster Builder · this mailbox is not monitored, please do not reply.</td></tr>
</table>
</td></tr></table>
</body>
</html>
