{{-- Roster email (plain-text version of mail/roster.blade.php): only days with something on. Times are base local. --}}
Dear {{ $crew->name }},

Your roster: {{ $roster['label'] }} ({{ $roster['range'] }})
{{ $roster['totals']['flights'] }} {{ $roster['totals']['flights'] === 1 ? 'flight' : 'flights' }} · {{ $roster['totals']['block'] }} block · {{ $roster['totals']['duty'] }} duty

@foreach($roster['days'] as $day)
@continue($day['entries'] === [])
{{ $day['weekday'] }} {{ $day['day'] }}
@foreach($day['entries'] as $entry)
@if($entry['kind'] === 'duty')
  {{ $entry['code'] }} {{ $entry['route'] }} · {{ $entry['seat'] }}{{ $entry['day_of_trip'] ? ' · '.$entry['day_of_trip'] : '' }}
  {{ $entry['report_local'] }}–{{ $entry['release_local'] }} LT ({{ $entry['report_gmt'] }}–{{ $entry['release_gmt'] }} GMT)
  With: {{ collect($entry['crew'])->reject(fn ($member) => $member['you'])->map(fn ($member) => $member['open'] ? $member['role'].' not yet assigned' : $member['name'].' ('.$member['role'].')')->implode(', ') }}
@elseif($entry['kind'] === 'layover')
  Night stop in {{ $entry['airport'] }} ({{ $entry['code'] }}, same crew)
@else
  {{ $entry['label'] }}{{ $entry['times'] ? ' '.$entry['times'].' LT' : '' }}{{ $entry['note'] ? ' · '.$entry['note'] : '' }}
@endif
@endforeach

@endforeach
Days not listed have no duty. The attached PDF has the full detail and the calendar file adds every duty to your phone. Contact crew control if anything looks wrong.

{{ $signature }}
