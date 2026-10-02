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
<div id="status" class="status" role="status" aria-live="polite"></div>
