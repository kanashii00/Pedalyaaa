<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        // Audit Logs carries security-sensitive changes for accountability;
        // Activity Logs carries routine operational events. Both read the
        // same table through the shared AuditLogger classification.
        $tab = $request->query('tab') === 'activity' ? 'activity' : 'audit';
        $category = $tab === 'activity'
            ? AuditLogger::CATEGORY_OPERATIONAL
            : AuditLogger::CATEGORY_SECURITY;

        $query = AuditLog::with('user')->where('category', $category);

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('userId')) {
            $query->where('userId', $request->input('userId'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        $logs = $query->latest()->paginate(30)->withQueryString();

        $users = \App\Models\User::orderBy('name')->get();

        // Canonical event lists for the filter; anything recorded in the
        // database but absent here is appended so new event types appear
        // without code changes. Nothing here is hardcoded sample data.
        $canonicalActions = array_merge(...array_values(AuditLogger::groupedActions()));
        $recordedActions = AuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->values()
            ->all();
        $actionOptions = array_values(array_unique(array_merge(
            $canonicalActions,
            array_diff($recordedActions, $canonicalActions)
        )));

        return response()->view('admin.audit', compact('logs', 'users', 'actionOptions', 'tab'));
    }
}
