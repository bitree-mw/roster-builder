{{-- <x-icon name="plane" class="icon-sm" /> — a symbol from components/icon-sprite.blade.php, hidden from screen readers. --}}
@props(['name'])
<svg {{ $attributes->class('icon') }} aria-hidden="true" focusable="false"><use href="#icon-{{ $name }}"></use></svg>
