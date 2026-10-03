{{--
    Admin settings sub-navigation, shown on each admin settings page: user accounts (administrators and
    schedulers) and airports (administrators only). "current" names the page being shown.
--}}
@props(['current'])
<nav class="subnav" aria-label="Admin settings">
    <a href="{{ route('accounts') }}" @if($current === 'accounts') aria-current="page" @endif><x-icon name="lock" class="icon-sm" />User accounts</a>
    @if(auth()->user()->isAdmin())
    <a href="{{ route('airports') }}" @if($current === 'airports') aria-current="page" @endif><x-icon name="route" class="icon-sm" />Airports</a>
    @endif
</nav>
