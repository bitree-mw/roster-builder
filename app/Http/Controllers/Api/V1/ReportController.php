<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportRequest;
use App\Services\ExpiryService;
use App\Services\PdfService;
use App\Services\ReportService;
use App\Support\Api\ApiResponse;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/reports — operational reports (staff). The same report definition is returned as JSON for the
 * Reports page, or downloaded as CSV or PDF.
 */
class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    /**
     * GET /reports — the available report types and today's base-local date (for default periods).
     */
    public function index(ExpiryService $expiry): JsonResponse
    {
        Gate::authorize('read-operations');

        return ApiResponse::resource(JsonResource::make([
            'today' => $expiry->today()->format('Y-m-d'),
            'types' => collect(ReportService::TYPES)->map(fn (string $title, string $key): array => ['key' => $key, 'title' => $title])->values()->all(),
        ]));
    }

    /**
     * GET /reports/{type}?from=&to=&group= — the report as JSON.
     */
    public function show(ReportRequest $request, string $type): JsonResponse
    {
        return ApiResponse::resource(JsonResource::make(['type' => $type, ...$this->reports->build($type, $request->validated())]));
    }

    /**
     * GET /reports/{type}/export.{format} — the report as a CSV (formula-safe) or PDF download.
     */
    public function export(ReportRequest $request, PdfService $pdf, string $type, string $format): Response
    {
        $report = $this->reports->build($type, $request->validated());
        $name = 'report-'.$type.'-'.$request->validated('from').'-to-'.$request->validated('to');
        if ($format === 'csv') {
            $content = Csv::write(array_column($report['columns'], 'label'), array_map(fn (array $row): array => array_map(fn (array $column): mixed => $row[$column['key']] ?? '', $report['columns']), $report['rows']));

            return $this->file($content, $name.'.csv', 'text/csv; charset=UTF-8');
        }
        $content = $pdf->render('pdf.report', [...$report, 'subtitle' => $report['description'].' Period: '.$report['period'].'.', 'generatedBy' => $request->user()->name]);

        return $this->file($content, $name.'.pdf', 'application/pdf');
    }

    /** A private, non-cached file download. */
    private function file(string $content, string $filename, string $type): Response
    {
        return response($content, 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
