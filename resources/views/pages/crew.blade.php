@extends('layouts.app')
@section('content')
<x-page-heading eyebrow="People / Licensing" heading="Crew directory" description="Flight deck and cabin crew with their base, aircraft ratings and licence, medical and recurrent training expiry. Documents expiring within {{ config('roster.document_warning_days') }} days are flagged.">
    <button id="add-crew" class="button" type="button" disabled><x-icon name="plus" class="icon-sm" />Add crew member</button>
</x-page-heading>

<div class="kpi-grid">
    <x-kpi label="Active crew" icon="users" value="crew.active" meta="Available to roster" />
    <x-kpi label="Captains" icon="id-card" value="crew.captains" tone="info" meta="CPT" />
    <x-kpi label="First officers" icon="id-card" value="crew.first_officers" tone="info" meta="FO" />
    <x-kpi label="Cabin crew" icon="users" value="crew.cabin" tone="info" meta="CC" />
    <x-kpi label="Expired documents" icon="alert" value="crew.documents_expired" tone="danger" meta="Active crew only" />
    <x-kpi label="Expiring soon" icon="clock" value="crew.documents_due_soon" tone="warning" meta="Within {{ config('roster.document_warning_days') }} days" />
</div>

<section class="panel" aria-labelledby="crew-title">
    <div class="panel-heading">
        <div class="panel-title"><x-icon name="users" /><h2 id="crew-title">Crew members</h2><span id="crew-count" class="chip"></span></div>
        <div class="toolbar">
            <div class="search"><x-icon name="search" class="icon-sm" /><label class="visually-hidden" for="crew-search">Search crew</label><input id="crew-search" type="search" placeholder="Name, email or base" autocomplete="off"></div>
            <div class="segmented" id="rank-filter" role="group" aria-label="Filter by position">
                <button type="button" data-value="" aria-pressed="true">All</button>
                <button type="button" data-value="CPT" aria-pressed="false">Captains</button>
                <button type="button" data-value="FO" aria-pressed="false">First officers</button>
                <button type="button" data-value="CC" aria-pressed="false">Cabin</button>
            </div>
            <label class="check-label small"><input id="alerts-only" type="checkbox">Document alerts only</label>
        </div>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th scope="col">Crew member</th><th scope="col">Position</th><th scope="col">Base</th><th scope="col">Aircraft</th><th scope="col">Licence</th><th scope="col">Medical</th><th scope="col">Recurrent</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody id="crew-rows"><tr class="loading-row"><td colspan="9">Loading crew…</td></tr></tbody>
    </table></div>
    <div class="panel-footer"><span>Dates are calendar dates at base; missing documents are never assumed valid.</span></div>
</section>

<dialog id="crew-dialog" class="dialog" aria-labelledby="crew-dialog-title"><form id="crew-form" novalidate>
    <div class="dialog-heading"><div><h2 id="crew-dialog-title" data-dialog-title>Add crew member</h2><p>Ratings constrain which flights a crew member can be assigned to.</p></div><button type="button" class="icon-button" data-close aria-label="Close"><x-icon name="x" /></button></div>
    <div class="form-grid">
        <label>Full name<input name="name" required maxlength="150" autocomplete="off"></label>
        <label>Email<input name="email" type="email" autocomplete="off"></label>
        <label>Position<select name="rank"><option value="CPT">Captain</option><option value="FO">First officer</option><option value="CC">Cabin crew</option></select></label>
        <label>Base<select name="base_airport" required></select></label>
        <label>Aircraft ratings<select name="rating_ids" multiple aria-describedby="ratings-help"></select><span id="ratings-help" class="field-hint">Hold Ctrl / Command to select several types.</span></label>
        <div class="flex flex-col gap-3 justify-center"><label class="check-label"><input name="all_aircraft" type="checkbox">Rated on all aircraft (cabin crew only)</label><label class="check-label"><input name="active" type="checkbox" checked>Active crew member</label></div>
        @foreach(['licence' => 'Licence', 'medical' => 'Medical', 'recurrent' => 'Recurrent training'] as $kind => $label)
        <label>{{ $label }} expiry<input name="{{ $kind }}" type="date"><span class="field-hint">Leave blank if not recorded.</span></label>
        @endforeach
    </div>
    <div class="status" data-form-status role="alert"></div>
    <div class="form-actions"><button class="button button-secondary" type="button" data-close-secondary>Cancel</button><button class="button" type="submit">Save crew member</button></div>
</form></dialog>
@endsection
