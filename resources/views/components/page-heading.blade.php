{{-- Page heading card with optional action buttons in the slot. Outcomes are shown as pop-ups, not here. --}}
@props(['eyebrow', 'heading', 'description'])
<div class="page-heading">
    <div>
        <p class="eyebrow">{{ $eyebrow }}</p>
        <h1>{{ $heading }}</h1>
        <p class="page-description">{{ $description }}</p>
    </div>
    @if(! $slot->isEmpty())
    <div class="page-actions">{{ $slot }}</div>
    @endif
</div>
