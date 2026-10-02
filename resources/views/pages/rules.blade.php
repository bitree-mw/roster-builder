{{-- Duty rules shell. Data and saving: resources/js/pages/rules.js (API: /api/v1/rules). Only schedulers can save. --}}
@extends('layouts.app')
@section('content')
<x-page-heading eyebrow="Parameters / Duty limits" heading="Duty rules" description="Server-side planning parameters. Each roster period keeps a snapshot of the rules it was created with; changes apply to newly created periods only. These values are configurable defaults, not a certified statement of aviation regulations." />

<section class="panel rules-panel" aria-labelledby="rules-title">
    <div class="panel-heading">
        <div class="panel-title"><x-icon name="sliders" /><h2 id="rules-title">Standard rule set</h2></div>
        <span class="chip" data-tone="{{ in_array(auth()->user()->role, ['admin', 'scheduler'], true) ? 'success' : 'info' }}">{{ in_array(auth()->user()->role, ['admin', 'scheduler'], true) ? 'Editable by you' : 'Read only · scheduler-managed' }}</span>
    </div>
    <form id="rules-form" class="panel-body rules-form" novalidate>
        <label class="rules-name">Rule set name<input name="name" required maxlength="100"></label>
        {{-- Field groups: name => [label, unit, min, max, step]. Limits mirror RuleSetRequest. --}}
        @foreach([
            'Duty period' => [
                'report_before_min' => ['Report before first departure', 'min', 0, 240, 1],
                'release_after_min' => ['Release after last arrival', 'min', 0, 240, 1],
                'max_duty_day_h' => ['Maximum daily duty', 'hours', 1, 24, 0.25],
            ],
            'Rest and cumulative limits' => [
                'min_rest_h' => ['Minimum rest (incl. night stops)', 'hours', 1, 48, 0.25],
                'max_duty_7d_h' => ['Maximum rolling 7-day duty', 'hours', 1, 168, 0.25],
                'max_block_month_h' => ['Maximum calendar-month block', 'hours', 1, 744, 0.25],
                'max_consecutive_days' => ['Maximum consecutive duty days', 'days', 1, 31, 1],
            ],
            'Days off' => [
                'min_days_off_month' => ['Minimum monthly days off', 'days', 0, 28, 1],
                'max_days_off_week' => ['Maximum weekly days off (7 disables)', 'days', 0, 7, 1],
            ],
            'Time reference' => [
                'utc_offset_minutes' => ['Base local offset from GMT', 'min', -720, 840, 15],
            ],
        ] as $group => $fields)
        <fieldset class="rules-group">
            <legend class="label-caps">{{ $group }}</legend>
            <div class="rules-grid">
                @foreach($fields as $key => [$label, $unit, $minimum, $maximum, $step])
                <label>{{ $label }}<span class="unit-input"><input name="{{ $key }}" type="number" required min="{{ $minimum }}" max="{{ $maximum }}" step="{{ $step }}" class="mono"><span class="unit">{{ $unit }}</span></span></label>
                @endforeach
            </div>
        </fieldset>
        @endforeach
        <div class="status" data-form-status role="alert"></div>
        @if(in_array(auth()->user()->role, ['admin', 'scheduler'], true))
        <div class="form-actions"><button type="submit" class="button"><x-icon name="check" class="icon-sm" />Save duty rules</button></div>
        @else
        <p class="small muted">Crew control can view these rules. A scheduler account is required to change them.</p>
        @endif
    </form>
</section>
@endsection
