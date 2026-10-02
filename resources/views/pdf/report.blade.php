{{-- Generic report table (ReportService): $columns [{key, label, align}], $rows, $summary, optional $notes. --}}
@extends('pdf.layout')
@section('body')
@include('pdf.partials.summary', ['summary' => $summary])
@if($rows === [])
<p class="empty">Nothing to report for this selection.</p>
@else
<table class="data">
    <thead><tr>@foreach($columns as $column)<th @class(['num' => ($column['align'] ?? null) === 'right'])>{{ $column['label'] }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach($rows as $index => $row)
    <tr @class(['alt' => $index % 2 === 1])>
        @foreach($columns as $column)
        <td @class(['num' => ($column['align'] ?? null) === 'right', 'tone-'.($row['_tone'][$column['key']] ?? '') => isset($row['_tone'][$column['key']])])>{{ $row[$column['key']] ?? '' }}</td>
        @endforeach
    </tr>
    @endforeach
    </tbody>
</table>
@endif
@isset($notes)<p class="note">{{ $notes }}</p>@endisset
@endsection
