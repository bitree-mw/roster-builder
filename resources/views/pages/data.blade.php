{{--
    Import & backup shell (staff). Data and interactions: resources/js/pages/data.js
    (API: /api/v1/imports, /api/v1/backups). Backup and restore are shown to administrators only;
    data-admin only hides controls, the server enforces every permission.
--}}
@extends('layouts.app')
@section('content')
@php($admin = auth()->user()->isAdmin())
<div id="data-root" data-admin="{{ $admin ? 'true' : 'false' }}" hidden></div>
<x-page-heading eyebrow="Administration / Data" heading="Import & backup" description="Bring crew, flight patterns and leave in from spreadsheets: every row is checked and shown to you before anything is saved. Administrators can also download a full backup and restore one.">
</x-page-heading>

{{-- CSV import: choose what and how, preview every row, then import. --}}
<section class="panel" aria-labelledby="import-title">
    <div class="panel-heading"><div class="panel-title"><x-icon name="clipboard" /><h2 id="import-title">Import from CSV</h2></div><span class="small muted">Comma, semicolon or tab separated · first row is the headings</span></div>
    <form id="import-form" class="panel-body import-form" novalidate>
        <div class="form-grid">
            <label>What to import<select name="kind"><option value="crew">Crew members</option><option value="flights">Flight patterns</option><option value="activities">Leave and day planning</option></select></label>
            <label>CSV file<input name="file" type="file" accept=".csv,text/csv,text/plain" required></label>
            <fieldset class="span-2">
                <legend>How to import</legend>
                <div class="option-cards">
                    <label class="option-card"><input type="radio" name="mode" value="merge" checked><span><strong>Merge</strong><small>Add new records and update matching ones. Nothing else changes.</small></span></label>
                    <label class="option-card"><input type="radio" name="mode" value="replace"><span><strong>Replace</strong><small data-replace-help>Also deactivate crew that are not in the file.</small></span></label>
                </div>
            </fieldset>
            <label id="times-field" hidden>Leg times in the file<select name="times"><option value="local">Base local time</option><option value="utc">UTC (convert to local)</option></select></label>
        </div>
        <div class="form-actions">
            <button id="download-template" class="button button-secondary" type="button">Download template</button>
            <button class="button" type="submit">Preview</button>
        </div>
    </form>
    {{-- Preview: one row per file line with what will happen; import only when there are no errors. --}}
    <div id="import-preview" hidden>
        <div class="import-summary"><div id="import-summary" class="chip-list"></div>
            <div class="row-actions"><button id="discard-import" class="button button-sm button-quiet" type="button">Discard</button><button id="commit-import" class="button button-sm" type="button" disabled>Import</button></div>
        </div>
        <div class="table-wrap import-table"><table>
            <thead><tr><th scope="col">Line</th><th scope="col">Result</th><th scope="col">Record</th><th scope="col">Notes</th></tr></thead>
            <tbody id="import-rows"></tbody>
        </table></div>
    </div>
</section>

@if($admin)
{{-- Backup and restore (administrators). --}}
<section class="panel" aria-labelledby="backup-title">
    <div class="panel-heading"><div class="panel-title"><x-icon name="shield" /><h2 id="backup-title">Backup and restore</h2></div><span class="small muted">Accounts, passwords and tokens are never included</span></div>
    <div class="panel-body backup-body">
        <div class="backup-block">
            <h3>Download a backup</h3>
            <p class="small muted">A JSON file with fleet, maintenance, crew, day planning, flights, duty rules, rosters and email logs. Keep it somewhere safe: it contains crew names and contact details.</p>
            <button id="download-backup" class="button button-secondary" type="button"><x-icon name="shield" class="icon-sm" />Download backup</button>
        </div>
        <form id="restore-form" class="backup-block" novalidate>
            <h3>Restore a backup</h3>
            <p class="small muted">Replaces all operational data with the backup. Accounts are kept and stay linked to their crew profiles. Check the file first, then confirm.</p>
            <label>Backup file<input name="file" type="file" accept=".json,application/json" required></label>
            <div class="form-actions"><button class="button button-secondary" type="submit">Check backup</button></div>
        </form>
    </div>
    <div id="restore-check" class="restore-check" hidden>
        <p id="restore-source" class="small"></p>
        <div class="table-wrap"><table><thead><tr><th scope="col">Data</th><th scope="col" data-align="right">In backup</th><th scope="col" data-align="right">Now</th></tr></thead><tbody id="restore-rows"></tbody></table></div>
        <form id="restore-confirm" class="restore-confirm" novalidate>
            <label>Type RESTORE to replace all operational data<input name="confirmation" autocomplete="off" spellcheck="false"></label>
            <button class="button button-danger" type="submit">Restore backup</button>
        </form>
    </div>
</section>
@endif
@endsection
