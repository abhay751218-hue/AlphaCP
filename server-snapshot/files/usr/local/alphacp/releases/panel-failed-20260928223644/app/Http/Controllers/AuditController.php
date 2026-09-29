<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\View\View;

/** Immutable audit trail viewer (every state change lands here). */
class AuditController extends Controller
{
    public function index(): View
    {
        $action   = request()->string('action')->toString() ?: null;
        $severity = request()->string('severity')->toString() ?: null;

        return view('audit.index', [
            'events'   => Audit::recent(100, $action, $severity),
            'action'   => $action,
            'severity' => $severity,
        ]);
    }
}
