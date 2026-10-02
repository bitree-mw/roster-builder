{{-- Reports shell (staff). Data and interactions: resources/js/pages/reports.js (API: /api/v1/reports). --}}
@extends('layouts.app')
@section('content')
<x-page-heading eyebrow="Operations / Reports" heading="Reports" description="Roster coverage, crew hours, overrides, document expiry, fleet and maintenance, flight utilisation and the audit log for any period of up to a year. Every report can be downloaded as CSV for spreadsheets or as a PDF to print and share.">
</x-page-heading>

{{-- Report chooser: one button per report type (filled by reports.js). --}}
<section class="panel" aria-labelledby="report-types-title">
    <div class="panel-heading"><div class="panel-title"><x-icon name="clipboard" /><h2 id="report-types-title">Choose a report</h2></div></div>
    <div id="report-types" class="report-types" role="group" aria-label="Report type" aria-busy="true"><p class="loading-block">Loading reports…</p></div>
</section>

{{-- Filters and downloads, then the report itself. --}}
<section class="panel" aria-labelledby="report-title">
    <div class="panel-heading">
        <div class="panel-title"><h2 id="report-title">Report</h2><span id="report-period" class="chip mono"></span></div>
        <form id="report-filters" class="toolbar" novalidate>
            <label class="inline-field">From<input name="from" type="date" required></label>
            <label class="inline-field">To<input name="to" type="date" required></label>
            <label class="inline-field" id="group-field" hidden>Crew<select name="group"><option value="">Everyone</option><option value="pilots">Pilots</option><option value="cabin">Cabin crew</option></select></label>
            <button class="button button-sm" type="submit">Run report</button>
            <button id="report-csv" class="button button-sm button-secondary" type="button" disabled>CSV</button>
            <button id="report-pdf" class="button button-sm button-secondary" type="button" disabled>PDF</button>
        </form>
    </div>
    <p id="report-description" class="report-description"></p>
    <div id="report-summary" class="report-summary"></div>
    <div class="table-wrap"><table>
        <thead><tr id="report-head"></tr></thead>
        <tbody id="report-rows"><tr class="loading-row"><td>Choose a report.</td></tr></tbody>
    </table></div>
    <div class="panel-footer"><span id="report-notes">Dates are base local.</span></div>
</section>
@endsection
