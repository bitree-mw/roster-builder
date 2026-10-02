<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BackupUploadRequest;
use App\Http\Requests\RestoreRequest;
use App\Services\BackupService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * /api/v1/backups — download a JSON backup of all operational data, and restore one (administrators only).
 */
class BackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    /**
     * GET /backups/download — the backup file (no accounts, passwords or tokens).
     */
    public function download(Request $request): Response
    {
        Gate::authorize('manage-backups');
        $json = json_encode($this->backups->export($request->user()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return response($json, 200, ['Content-Type' => 'application/json; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="roster-backup-'.now('UTC')->format('Y-m-d-His').'.json"', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * POST /backups/inspect (multipart: file) — validate a backup and compare its record counts with now.
     */
    public function inspect(BackupUploadRequest $request): JsonResponse
    {
        return ApiResponse::resource(JsonResource::make($this->backups->inspect((string) $request->file('file')->get(), $request->user())), 'backup.inspected');
    }

    /**
     * POST /backups/restore {token, confirmation: "RESTORE"} — replace all operational data with the backup.
     */
    public function restore(RestoreRequest $request): JsonResponse
    {
        $counts = $this->backups->restore($request->validated('token'), $request->user());

        return ApiResponse::success('backup.restored', ['count' => array_sum($counts)], ['data' => $counts]);
    }
}
