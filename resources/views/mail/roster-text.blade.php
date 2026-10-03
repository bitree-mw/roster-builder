{{-- Roster email (plain-text version of mail/roster.blade.php). Times are base local with GMT alongside. --}}
Dear {{ $crew->name }},

Your published roster for {{ $roster['label'] }} ({{ $roster['range'] }}):
Flights {{ $roster['totals']['flights'] }} · Block {{ $roster['totals']['block'] }} · Duty {{ $roster['totals']['duty'] }} · Planned days {{ $roster['totals']['planned'] }}

@foreach($roster['days'] as $day)
{{ $day['weekday'] }} {{ $day['day'] }}
@forelse($day['entries'] as $entry)
@if($entry['kind'] === 'duty')
  {{ $entry['code'] }} {{ $entry['route'] }} · {{ $entry['seat'] }}{{ $entry['day_of_trip'] ? ' · '.$entry['day_of_trip'] : '' }}
  Report {{ $entry['report_local'] }} LT, release {{ $entry['release_local'] }} LT ({{ $entry['report_gmt'] }}–{{ $entry['release_gmt'] }} GMT) · {{ $entry['aircraft'] }}, block {{ $entry['block'] }}
  Flying with: @foreach($entry['crew'] as $member){{ $member['role'] }} {{ $member['name'] }}{{ $member['you'] ? ' (you)' : '' }}{{ $loop->last ? '' : '; ' }}@endforeach

@elseif($entry['kind'] === 'layover')
  Night stop: {{ $entry['code'] }} at {{ $entry['airport'] }}, same crew until back at base
@else
  {{ $entry['label'] }}{{ $entry['times'] ? ' '.$entry['times'].' LT' : '' }}{{ $entry['note'] ? ' · '.$entry['note'] : '' }}
@endif
@empty
  No duty
@endforelse
@endforeach

The attached calendar file adds every duty to your calendar. Contact crew control if anything looks wrong.

{{ $signature }}

This mailbox is not monitored; please do not reply.
