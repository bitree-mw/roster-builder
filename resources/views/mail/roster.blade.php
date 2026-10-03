{{--
    Roster email (HTML), built by App\Mail\RosterMail from RosterEmailService::roster(). Deliberately simple:
    a one-line summary and only the days that have something on; the attached PDF has the full detail.
    Styles come from resources/css/mail/roster.css (app colour tokens) and are inlined before sending;
    $styles is also kept in the head for clients that support media queries. Data is escaped by Blade.
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

    {{-- Header: logo, title and dates. --}}
    <tr><td class="header">
        <table role="presentation" cellpadding="0" cellspacing="0"><tr>
            @if($logo)
            <td class="logo-cell"><img class="logo" src="{{ $logo }}" alt="Malawi Airlines" height="32"></td>
            @endif
            <td><p class="title">Your roster: {{ $roster['label'] }}</p><p class="range">{{ $roster['range'] }}</p></td>
        </tr></table>
    </td></tr>

    {{-- Greeting and one-line summary. --}}
    <tr><td class="body">
        <p class="greeting">Dear {{ $crew->name }},</p>
        <p class="summary">{{ $roster['totals']['flights'] }} {{ $roster['totals']['flights'] === 1 ? 'flight' : 'flights' }} · {{ $roster['totals']['block'] }} block · {{ $roster['totals']['duty'] }} duty</p>
        <p class="intro">Your published roster is below. The attached PDF has the full detail and the calendar file adds every duty to your phone.</p>

        {{-- Only the days with something on. --}}
        <table class="days" role="presentation" cellpadding="0" cellspacing="0">
        @foreach($roster['days'] as $day)
            @continue($day['entries'] === [])
            <tr class="day">
                <td class="date">{{ $day['day'] }}<br><span class="weekday">{{ $day['weekday'] }}</span></td>
                <td>
                @foreach($day['entries'] as $entry)
                    @if($entry['kind'] === 'duty')
                    <div class="entry">
                        <p class="line"><span class="flight">{{ $entry['code'] }}</span> {{ $entry['route'] }} · {{ $entry['seat'] }}@if($entry['day_of_trip']) <span class="muted">· {{ $entry['day_of_trip'] }}</span>@endif</p>
                        <p class="line">{{ $entry['report_local'] }}–{{ $entry['release_local'] }} LT <span class="muted">({{ $entry['report_gmt'] }}–{{ $entry['release_gmt'] }} GMT)</span></p>
                        <p class="with">With: @foreach(array_values(array_filter($entry['crew'], fn ($member) => ! $member['you'])) as $member)<span @class(['open' => $member['open']])>{{ $member['open'] ? $member['role'].' not yet assigned' : $member['name'].' ('.$member['role'].')' }}</span>{{ $loop->last ? '' : ', ' }}@endforeach</p>
                    </div>
                    @elseif($entry['kind'] === 'layover')
                    <div class="entry entry-layover"><p class="line"><span class="flight">Night stop</span> in {{ $entry['airport'] }} <span class="muted">({{ $entry['code'] }}, same crew)</span></p></div>
                    @else
                    <div class="entry entry-{{ $entry['type'] }}"><p class="line"><span class="flight">{{ $entry['label'] }}</span>@if($entry['times']) {{ $entry['times'] }} LT @endif @if($entry['note'])<span class="muted">· {{ $entry['note'] }}</span>@endif</p></div>
                    @endif
                @endforeach
                </td>
            </tr>
        @endforeach
        </table>
    </td></tr>

    {{-- Notes, signature and footer. --}}
    <tr><td class="notes">
        Days not listed have no duty. Times are base local (LT). Contact crew control if anything looks wrong; the app always shows the latest version.
        <p class="signature">{{ $signature }}</p>
    </td></tr>
    <tr><td class="footer">Malawi Airlines Roster Builder · automated message</td></tr>
</table>
</td></tr></table>
</body>
</html>
