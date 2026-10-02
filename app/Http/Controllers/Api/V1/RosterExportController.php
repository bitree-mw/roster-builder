<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RosterCalendarRequest;
use App\Http\Requests\RosterPdfRequest;
use App\Models\CrewMember;
use App\Models\EmailLog;
use App\Models\RosterPeriod;
use App\Services\RosterEmailService;
use App\Services\RosterExportService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Roster outputs for one week: CSV and iCalendar downloads, and roster emails to crew with their delivery
 * log. Crew accounts may download only their own published duties; staff may export everything and send.
 */
class RosterExportController extends Controller
{
    public function __construct(private RosterExportService $exports) {}

    /**
     * GET /roster-periods/{id}/export.csv — staff: every seat; crew: own seats in a published week.
     */
    public function csv(Request $request, RosterPeriod $rosterPeriod): Response
    {
        $own = $this->ownCrewId($request, $rosterPeriod);

        return $this->download($this->exports->csv($rosterPeriod, $own), 'roster-week-'.$rosterPeriod->starts_on->isoWeek.'-'.$rosterPeriod->starts_on->format('Y').'.csv', 'text/csv; charset=UTF-8');
    }

    /**
     * GET /roster-periods/{id}/calendar.ics?crew_member_id= — one crew member's week as calendar events.
     * Crew always get their own; staff choose the crew member.
     */
    public function calendar(RosterCalendarRequest $request, RosterPeriod $rosterPeriod): Response
    {
        $own = $this->ownCrewId($request, $rosterPeriod);
        $crewId = $own ?? (int) $request->validated('crew_member_id');
        $crew = CrewMember::query()->findOrFail($crewId);

        return $this->download($this->exports->calendar($rosterPeriod, $crew), 'roster-week-'.$rosterPeriod->starts_on->isoWeek.'-'.str($crew->name)->slug().'.ics', 'text/calendar; charset=UTF-8');
    }

    /**
     * GET /roster-periods/{id}/roster.pdf?layout=grid|crew&crew_member_ids[]= — staff: the full grid (default)
     * or one page per crew member (all with duties, or the selected ones); crew: their own page of a
     * published week.
     */
    public function pdf(RosterPdfRequest $request, RosterPeriod $rosterPeriod): Response
    {
        $own = $this->ownCrewId($request, $rosterPeriod);
        $layout = $own !== null ? 'crew' : ($request->validated('layout') ?? 'grid');
        $selected = array_map('intval', $request->validated('crew_member_ids') ?? []);
        $crewIds = $own !== null ? [$own] : ($selected === [] ? null : $selected);
        $pdf = $this->exports->pdf($rosterPeriod, $layout, $crewIds, $request->user()->name);

        return $this->download($pdf, 'roster-week-'.$rosterPeriod->starts_on->isoWeek.'-'.$rosterPeriod->starts_on->format('Y').($layout === 'crew' ? '-crew' : '').'.pdf', 'application/pdf');
    }

    /**
     * POST /roster-periods/{id}/email — queue roster emails for everyone with a seat (published weeks only).
     */
    public function email(Request $request, RosterPeriod $rosterPeriod, RosterEmailService $emails): JsonResponse
    {
        Gate::authorize('manage-operations');
        $result = $emails->queue($rosterPeriod, $request->user());
        if ($result['queued'] === 0) {
            return ApiResponse::success('email.none', [], ['data' => $result]);
        }

        return ApiResponse::success($result['missing'] > 0 ? 'email.queued_with_missing' : 'email.queued', ['count' => $result['queued'], 'missing' => $result['missing']], ['data' => $result]);
    }

    /**
     * GET /roster-periods/{id}/email-logs — delivery status of this week's roster emails, newest first.
     */
    public function emailLogs(RosterPeriod $rosterPeriod): JsonResponse
    {
        Gate::authorize('read-operations');
        $logs = EmailLog::query()->where('roster_period_id', $rosterPeriod->id)->with('crewMember')->latest('id')->limit(500)->get()
            ->map(fn (EmailLog $log): array => ['id' => $log->id, 'crew_member_id' => $log->crew_member_id, 'name' => $log->crewMember->name, 'email' => $log->email,
                'status' => $log->status, 'error' => $log->error, 'sent_at' => $log->sent_at?->toIso8601String(), 'created_at' => $log->created_at?->toIso8601String()]);

        return ApiResponse::resource(JsonResource::make($logs->all()));
    }

    /**
     * For crew accounts: their own crew id (404 for unpublished weeks, so drafts stay invisible). Null for
     * staff, who may export everything.
     */
    private function ownCrewId(Request $request, RosterPeriod $period): ?int
    {
        Gate::authorize('read-rosters');
        if ($request->user()->isStaff()) {
            return null;
        }
        abort_if($period->status !== 'published', 404);

        return (int) $request->user()->crew_member_id;
    }

    /** A file download that is never cached by shared proxies. */
    private function download(string $content, string $filename, string $type): Response
    {
        return response($content, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
