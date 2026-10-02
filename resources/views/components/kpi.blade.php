@props(['label', 'icon', 'value', 'meta' => null, 'tone' => null, 'href' => null])
<{{ $href ? 'a' : 'div' }} @if($href) href="{{ $href }}" @endif class="kpi" @if($tone) data-tone="{{ $tone }}" @endif data-kpi-card="{{ $value }}">
    <div class="kpi-top"><span class="label-caps">{{ $label }}</span><span class="kpi-icon"><x-icon :name="$icon" /></span></div>
    <strong class="kpi-value" data-kpi="{{ $value }}"><span class="skeleton skeleton-value" aria-hidden="true"></span><span class="visually-hidden">Loading</span></strong>
    @if($meta)<span class="kpi-meta" data-kpi-meta="{{ $value }}">{{ $meta }}</span>@endif
</{{ $href ? 'a' : 'div' }}>
