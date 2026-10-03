{{--
    Account administration shell. Data and interactions: resources/js/pages/accounts.js (API: /api/v1/accounts).
    Administrators manage every account type; schedulers create and edit pilot and cabin crew accounts only.
    data-admin lets the script show the administrator-only options; the server enforces every rule.
--}}
@extends('layouts.app')
@section('content')
@php($admin = auth()->user()->isAdmin())
<div id="accounts-root" data-admin="{{ $admin ? 'true' : 'false' }}" hidden></div>
<x-page-heading eyebrow="Administration / Accounts" heading="Accounts" :description="$admin ? 'Create and manage sign-ins for administrators, schedulers, crew control, pilots and cabin crew. Pilots and cabin crew can only view their own published roster and hours.' : 'Create sign-ins for pilots and cabin crew so they can view their own published roster and hours. Only administrators can create staff accounts or delete accounts.'">
    <button id="add-account" class="button" type="button" disabled><x-icon name="plus" class="icon-sm" />Create account</button>
</x-page-heading>

{{-- Account counts by type, and crew who still have no sign-in. --}}
<div class="kpi-grid">
    <x-kpi label="Accounts" icon="lock" value="accounts.total" meta="That you can manage" />
    @if($admin)
    <x-kpi label="Staff" icon="shield" value="accounts.staff" tone="info" meta="Admins · schedulers · crew control" />
    @endif
    <x-kpi label="Pilots" icon="id-card" value="accounts.pilots" tone="info" meta="Captains and first officers" />
    <x-kpi label="Cabin crew" icon="users" value="accounts.cabin" tone="info" meta="Cabin crew members" />
    <x-kpi label="Crew without a sign-in" icon="alert" value="accounts.missing" meta="Active crew with no account" />
</div>

{{-- Account list with type filter and search. --}}
<section class="panel" aria-labelledby="accounts-title">
    <div class="panel-heading">
        <div class="panel-title"><x-icon name="lock" /><h2 id="accounts-title">Sign-in accounts</h2><span id="account-count" class="chip"></span></div>
        <div class="toolbar">
            <div class="search"><x-icon name="search" class="icon-sm" /><label class="visually-hidden" for="account-search">Search accounts</label><input id="account-search" type="search" placeholder="Name, email or username" autocomplete="off"></div>
            <div class="segmented" id="type-filter" role="group" aria-label="Filter by account type">
                <button type="button" data-value="" aria-pressed="true">All</button>
                @if($admin)<button type="button" data-value="staff" aria-pressed="false">Staff</button>@endif
                <button type="button" data-value="pilot" aria-pressed="false">Pilots</button>
                <button type="button" data-value="cabin" aria-pressed="false">Cabin crew</button>
            </div>
        </div>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th scope="col">Account</th><th scope="col">Email</th><th scope="col">Type</th><th scope="col">Crew profile</th><th scope="col">Created</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody id="account-rows"><tr class="loading-row"><td colspan="6">Loading accounts…</td></tr></tbody>
    </table></div>
    <div class="panel-footer"><span>People sign in with their email or username. Setting a new password signs that person out everywhere.</span></div>
</section>

@if($admin)
{{-- Administrator settings: airports that flight routes can use (e.g. Entebbe, EBB) and which are crew bases. --}}
<section class="panel" aria-labelledby="airports-title">
    <div class="panel-heading">
        <div class="panel-title"><x-icon name="route" /><h2 id="airports-title">Airports</h2><span id="airport-count" class="chip"></span></div>
        <div class="toolbar"><button id="add-airport" class="button button-sm" type="button" disabled><x-icon name="plus" class="icon-sm" />Add airport</button></div>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th scope="col">Code</th><th scope="col">Name</th><th scope="col">UTC offset</th><th scope="col">Crew base</th><th scope="col">Used by</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody id="airport-rows"><tr class="loading-row"><td colspan="6">Loading airports…</td></tr></tbody>
    </table></div>
    <div class="panel-footer"><span>New airports appear in the From / To lists on Flight routes. Airports used by a route or as a crew base cannot be removed.</span></div>
</section>
@endif

{{-- Create / edit account dialog. --}}
<dialog id="account-edit-dialog" class="dialog" aria-labelledby="account-edit-title"><form id="account-edit-form" novalidate>
    <div class="dialog-heading"><div><h2 id="account-edit-title" data-dialog-title>Create account</h2><p>Choose what this person can do, then their sign-in details.</p></div><button type="button" class="icon-button" data-close aria-label="Close"><x-icon name="x" /></button></div>
    <fieldset>
        <legend>Account type</legend>
        <div class="option-cards account-types">
            @if($admin)
            <label class="option-card"><input type="radio" name="type" value="admin"><span><strong>Administrator</strong><small>Everything, including all accounts.</small></span></label>
            <label class="option-card"><input type="radio" name="type" value="scheduler"><span><strong>Scheduler</strong><small>Rosters, rules and pilot/cabin accounts.</small></span></label>
            <label class="option-card"><input type="radio" name="type" value="crew_control"><span><strong>Crew control</strong><small>Rosters and operational data.</small></span></label>
            @endif
            <label class="option-card"><input type="radio" name="type" value="pilot" checked><span><strong>Pilot</strong><small>Views own roster and hours only.</small></span></label>
            <label class="option-card"><input type="radio" name="type" value="cabin"><span><strong>Cabin crew</strong><small>Views own roster and hours only.</small></span></label>
        </div>
    </fieldset>
    <div class="form-grid">
        <label class="span-2" id="crew-field">Crew member<select name="crew_member_id" aria-describedby="crew-help"></select><span id="crew-help" class="field-hint">Only crew of this type without a sign-in are listed.</span></label>
        <label>Full name<input name="name" required maxlength="150" autocomplete="off"></label>
        <label>Email<input name="email" type="email" required autocomplete="off"></label>
        <label>Username<input name="username" maxlength="50" autocomplete="off" aria-describedby="username-help"><span id="username-help" class="field-hint">Optional. Letters, numbers, dots, dashes and underscores.</span></label>
        <span></span>
        <label>Password<input name="password" type="password" autocomplete="new-password" minlength="12" aria-describedby="password-help"><span id="password-help" class="field-hint" data-password-hint>At least 12 characters.</span></label>
        <label>Repeat password<input name="password_confirmation" type="password" autocomplete="new-password" minlength="12"></label>
    </div>
    <div class="status" data-form-status role="alert"></div>
    <div class="form-actions"><button class="button button-secondary" type="button" data-close-secondary>Cancel</button><button class="button" type="submit">Save account</button></div>
</form></dialog>

@if($admin)
{{-- Add / edit airport dialog. The code is fixed once the airport exists (routes and crew refer to it). --}}
<dialog id="airport-dialog" class="dialog" aria-labelledby="airport-dialog-title"><form id="airport-form" novalidate>
    <div class="dialog-heading"><div><h2 id="airport-dialog-title" data-dialog-title>Add airport</h2><p>Add a destination for flight routes, for example Entebbe (EBB).</p></div><button type="button" class="icon-button" data-close aria-label="Close"><x-icon name="x" /></button></div>
    <div class="form-grid">
        <label>Airport code<input name="code" required maxlength="4" class="mono" placeholder="EBB" autocomplete="off" aria-describedby="airport-code-help"><span id="airport-code-help" class="field-hint">3-letter IATA code. It cannot be changed later.</span></label>
        <label>Name<input name="name" required maxlength="100" placeholder="Entebbe" autocomplete="off"></label>
        <label>UTC offset (hours)<input name="utc_offset_hours" type="number" required min="-12" max="14" step="0.25" value="3" class="mono" aria-describedby="airport-offset-help"><span id="airport-offset-help" class="field-hint">Entebbe is +3, Lilongwe +2. Use .5 or .75 for half and quarter hours.</span></label>
        <div class="flex flex-col justify-center"><label class="check-label"><input name="is_base" type="checkbox">Crew base (routes may start and end here, crew may be based here)</label></div>
    </div>
    <div class="status" data-form-status role="alert"></div>
    <div class="form-actions"><button class="button button-secondary" type="button" data-close-secondary>Cancel</button><button class="button" type="submit">Save airport</button></div>
</form></dialog>
@endif
@endsection
