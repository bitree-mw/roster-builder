{{-- Row of summary figures: $summary is [label => value]. --}}
@if(! empty($summary))
<table class="summary"><tr>
    @foreach($summary as $label => $value)
    <td><span class="label">{{ $label }}</span><span class="value">{{ $value }}</span></td>
    @endforeach
</tr></table>
@endif
