<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\Paneld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * WHM "Mail Queue Manager" (cPanel #141) — exim queue ka asli haal.
 *
 * Saara kaam ROOT AGENT karta hai (`mail.server` action=queue) — exim -bp
 * se list, -M/-Mrm/-Mf/-Mt se deliver/remove/freeze/thaw, -qf se flush.
 * Message id agent par dobara validate hota hai (shell-injection se bachav).
 */
final class MailQueueController extends Controller
{
    private const ID_PATTERN = '/^[0-9A-Za-z]{6}-[0-9A-Za-z]{6}-[0-9A-Za-z]{2}$/';

    public function index(Request $request): View
    {
        $agentOk = in_array('mail.server', Paneld::taskTypes(), true);
        $queue   = ['ok' => false, 'count' => 0, 'items' => [], 'error' => null, 'note' => null];

        if ($agentOk) {
            $res = Paneld::run('mail.server', ['action' => 'queue', 'op' => 'list'], 25);
            if (is_array($res)) {
                $queue = [
                    'ok'    => (bool) ($res['ok'] ?? false),
                    'count' => (int) ($res['count'] ?? 0),
                    'items' => is_array($res['items'] ?? null) ? $res['items'] : [],
                    'error' => isset($res['error']) ? (string) $res['error'] : null,
                    'note'  => isset($res['note']) ? (string) $res['note'] : null,
                ];
            } else {
                $queue['error'] = 'Agent se jawab nahi mila (timeout) — thodi der baad refresh karo.';
            }
        }

        $frozen = 0;
        foreach ($queue['items'] as $item) {
            if ((bool) ($item['frozen'] ?? false)) {
                $frozen++;
            }
        }

        return view('mail-queue.index', [
            'agentOk' => $agentOk,
            'queue'   => $queue,
            'frozen'  => $frozen,
        ]);
    }

    public function action(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'op' => ['required', 'string', 'in:deliver,remove,freeze,thaw,flush'],
            'id' => ['required_unless:op,flush', 'nullable', 'string', 'regex:' . self::ID_PATTERN],
        ]);

        $op      = (string) $data['op'];
        $payload = ['action' => 'queue', 'op' => $op];
        if ($op !== 'flush') {
            $payload['id'] = (string) $data['id'];
        }

        $res = Paneld::run('mail.server', $payload, 45);
        Audit::log('mailqueue.' . $op, 'info', 'system', null, ['id' => $payload['id'] ?? null]);

        if (is_array($res) && (bool) ($res['ok'] ?? false)) {
            $labels = [
                'deliver' => 'Delivery attempt shuru ho gaya.',
                'remove'  => 'Message queue se remove ho gaya.',
                'freeze'  => 'Message freeze ho gaya.',
                'thaw'    => 'Message thaw (unfreeze) ho gaya.',
                'flush'   => 'Queue flush shuru — exim sab pending mail deliver karne ki koshish kar raha hai.',
            ];

            return redirect()->route('mail-queue.index')->with('success', $labels[$op]);
        }

        $why = is_array($res) ? (string) ($res['error'] ?? 'agent ne fail bataya') : 'agent se jawab nahi mila (timeout)';

        return redirect()->route('mail-queue.index')->withErrors(['op' => ucfirst($op) . ' nahi chala — ' . $why]);
    }
}
