<!DOCTYPE html>
{{-- data-theme comes from the "theme" cookie (light or dark); without it the system preference applies. --}}
<html lang="en" @if(in_array(request()->cookie('theme'), ['light', 'dark'], true)) data-theme="{{ request()->cookie('theme') }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · Malawi Airlines Roster Builder</title>
    <link rel="icon" type="image/png" href="{{ asset('images/malawi-airlines-logo.png') }}">
    {{-- Guest layout (sign-in): no navigation and no app.js; pop-up notifications still work through the page script. --}}
    @vite(['resources/css/app.css', 'resources/css/pages/'.$page.'.css', 'resources/js/pages/'.$page.'.js'])
</head>
<body data-page="{{ $page }}">
<x-icon-sprite />
<a class="skip-link" href="#main">Skip to content</a>
<main id="main">
    @yield('content')
</main>
</body>
</html>
