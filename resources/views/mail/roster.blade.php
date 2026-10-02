{{-- Roster email (HTML). Sent by App\Jobs\SendRosterEmail; data is escaped by Blade. Times are base local. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Your roster</title></head>
<body>
<p>Dear {{ $crew->name }},</p>
<p>Your published roster for {{ $period->label() }} is below. The attached calendar file adds every duty to your phone or mail calendar.</p>
@if($duties === [])
<p>You have no rostered flights this week.</p>
@else
<table cellpadding="6" cellspacing="0" border="1">
    <thead><tr><th>Date</th><th>Flight</th><th>Seat</th><th>Route</th><th>Report (LT)</th><th>Release (LT)</th></tr></thead>
    <tbody>
    @foreach($duties as $duty)
    <tr><td>{{ $duty['date'] }}</td><td>{{ $duty['code'] }}</td><td>{{ $duty['seat'] }}</td><td>{{ $duty['route'] }}</td><td>{{ $duty['report_local'] }}</td><td>{{ $duty['release_local'] }}</td></tr>
    @endforeach
    </tbody>
</table>
@endif
<p>Times are base local (LT). Contact crew control if anything looks wrong.</p>
<p>{{ $signature }}</p>
</body>
</html>
