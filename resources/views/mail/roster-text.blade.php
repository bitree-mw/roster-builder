{{-- Roster email (plain text version). Times are base local. --}}
Dear {{ $crew->name }},

Your published roster for {{ $period->label() }}:

@forelse($duties as $duty)
- {{ $duty['date'] }}  {{ $duty['code'] }} {{ $duty['seat'] }}  {{ $duty['route'] }}  report {{ $duty['report_local'] }} LT, release {{ $duty['release_local'] }} LT
@empty
You have no rostered flights this week.
@endforelse

The attached calendar file adds every duty to your calendar. Contact crew control if anything looks wrong.

{{ $signature }}
