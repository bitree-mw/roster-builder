{{--
    Admin settings / Airports shell (administrators only). Data and interactions: resources/js/pages/airports.js
    (API: /api/v1/airports). Airports are the destinations flight routes can use, e.g. Entebbe (EBB), and the
    crew bases; the server enforces who may change them and the in-use guards.
--}}
@extends('layouts.app')
@section('content')
<x-page-heading eyebrow="Admin settings / Airports" heading="Airports" description="Destinations that flight routes can use, with their UTC offset, and which of them are crew bases. New airports appear straight away in the From / To lists on Flight routes.">
    <button id="add-airport" class="button" type="button" disabled><x-icon name="plus" class="icon-sm" />Add airport</button>
</x-page-heading>

<x-admin-nav current="airports" />

{{-- Airport counts: all, crew bases, outstations, and airports nothing uses yet. --}}
<div class="kpi-grid">
    <x-kpi label="Airports" icon="route" value="airports.total" meta="Available to flight routes" />
    <x-kpi label="Crew bases" icon="shield" value="airports.bases" tone="info" meta="Routes start and end here" />
    <x-kpi label="Outstations" icon="plane" value="airports.outstations" tone="info" meta="Destinations and night stops" />
    <x-kpi label="Not used yet" icon="alert" value="airports.unused" meta="No route or crew base" />
</div>

{{-- Airport list with search. --}}
<section class="panel" aria-labelledby="airports-title">
    <div class="panel-heading">
        <div class="panel-title"><x-icon name="route" /><h2 id="airports-title">Airports</h2><span id="airport-count" class="chip"></span></div>
        <div class="toolbar">
            <div class="search"><x-icon name="search" class="icon-sm" /><label class="visually-hidden" for="airport-search">Search airports</label><input id="airport-search" type="search" placeholder="Code or name" autocomplete="off"></div>
        </div>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th scope="col">Code</th><th scope="col">Name</th><th scope="col">UTC offset</th><th scope="col">Crew base</th><th scope="col">Used by</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody id="airport-rows"><tr class="loading-row"><td colspan="6">Loading airports…</td></tr></tbody>
    </table></div>
    <div class="panel-footer"><span>Airports used by a route or as a crew base cannot be removed. The code cannot be changed once an airport exists.</span></div>
</section>

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
@endsection
