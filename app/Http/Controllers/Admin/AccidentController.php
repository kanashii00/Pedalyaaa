<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accident;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class AccidentController extends Controller
{
    public function index(): Response
    {
        $incidents = Accident::with(['bicycle', 'rider'])
            ->whereIn('type', ['accident', 'impact_detected'])
            ->latest()
            ->paginate(20);

        return response()->view('admin.accidents', compact('incidents'));
    }

    public function acknowledge(int $id): RedirectResponse
    {
        $incident = Accident::findOrFail($id);

        $incident->update([
            'acknowledged' => true,
            'actionTaken' => 'Acknowledged by administrator',
        ]);

        AuditLog::record('accident_acknowledged', auth()->id(), [
            'accidentId' => $incident->id,
            'type' => $incident->type,
        ]);

        // Keep the right-side toast while dropping the duplicate in-page
        // success banner: the layout skips the banner whenever a structured
        // toast payload is present and shows only the toast (same pattern as
        // the bicycle-added and return-confirmed flows). The toast itself is
        // unchanged — same "Success" title, message, close button and
        // auto-dismiss — and the table's Acknowledged badge shows the result.
        return redirect()->back()->with([
            'success' => 'Accident report acknowledged.',
            'toast' => [
                'title' => 'Success',
                'message' => 'Accident report acknowledged.',
                'type' => 'success',
            ],
        ]);
    }
}
