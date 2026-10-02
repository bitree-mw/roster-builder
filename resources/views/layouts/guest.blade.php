<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · Malawi Airlines Roster Builder</title>
    <link rel="icon" type="image/png" href="{{ asset('images/malawi-airlines-logo.png') }}">
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
