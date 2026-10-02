<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportCommitRequest;
use App\Http\Requests\ImportPreviewRequest;
use App\Services\ImportService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/imports — CSV import of crew, flight patterns and day planning: template download, row-by-row
 * preview, then commit of the previewed rows (staff with write access).
 */
class ImportController extends Controller
{
    public function __construct(private ImportService $imports) {}

    /**
     * GET /imports/templates/{kind} — a CSV template with every column and example rows.
     */
    public function template(string $kind): Response
    {
        Gate::authorize('manage-operations');

        return response($this->imports->template($kind), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="import-template-'.$kind.'.csv"', 'Cache-Control' => 'no-store']);
    }

    /**
     * POST /imports/preview (multipart: kind, mode, times?, file) — what would happen to every row.
     */
    public function preview(ImportPreviewRequest $request): JsonResponse
    {
        $preview = $this->imports->preview($request->validated('kind'), $request->validated('mode'), (string) $request->file('file')->get(), ['times' => $request->validated('times') ?? 'local'], $request->user());
        $errors = $preview['summary']['error'] ?? 0;

        return ApiResponse::resource(JsonResource::make($preview), $errors > 0 ? 'import.preview_errors' : 'import.preview', ['count' => count($preview['rows']), 'errors' => $errors]);
    }

    /**
     * POST /imports/commit {token} — apply a previewed import (422 when it has errors or is out of date).
     */
    public function commit(ImportCommitRequest $request): JsonResponse
    {
        $result = $this->imports->commit($request->validated('token'), $request->user());
        $changed = array_sum(array_intersect_key($result['summary'], array_flip(['create', 'update', 'deactivate', 'disable', 'delete'])));

        return ApiResponse::success('import.committed', ['count' => $changed, 'kind' => mb_strtolower(ImportService::KINDS[$result['kind']])], ['data' => $result]);
    }
}
