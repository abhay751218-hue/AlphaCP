<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\CalendarItem;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Calendar & Contacts — name rows via paneld. No CalDAV/CardDAV daemon. */
class CalendarController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $items = $account?->calendarItems()->orderBy('kind')->orderBy('id')->get() ?? collect();

        return view('calendar.index', [
            'account' => $account,
            'calendars' => $items->where('kind', 'calendar')->values(),
            'contacts' => $items->where('kind', 'contact')->values(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['name' => 'Cannot change Calendar on a suspended/terminated account.']);
        }
        $data = $request->validate([
            'kind' => ['required', 'in:calendar,contact'],
            'name' => ['required', 'string', 'max:64'],
        ]);
        $kind = $data['kind'];
        if (MailProvisioner::calendarLimitReached($account, $kind)) {
            return back()->withErrors(['name' => 'Calendar/contact limit of 50 reached.']);
        }
        $name = Mail::tryCalName($data['name']);
        if ($name === null) {
            return back()->withErrors(['name' => 'Naam me pipe/shell nahi. Letters, numbers, space, ._+- only.'])->withInput();
        }
        $exists = CalendarItem::query()
            ->where('account_id', $account->id)
            ->where('kind', $kind)
            ->where('name', $name)
            ->exists();
        if ($exists) {
            return back()->withErrors(['name' => 'Ye naam already exists.'])->withInput();
        }
        CalendarItem::query()->create([
            'account_id' => $account->id,
            'kind' => $kind,
            'name' => $name,
        ]);
        MailProvisioner::enqueueCalendar($account);
        $account->recordEvent('mail.calendar.queued', $kind . ':' . $name);
        Audit::log('mail.calendar', 'info', 'account', $account->id, ['kind' => $kind, 'name' => $name]);

        return redirect()->route('calendar.index')->with('success', 'Calendar is queued.');
    }

    public function destroy(Request $request, CalendarItem $calendar_item): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($calendar_item->account_id !== $account->id) {
            abort(403);
        }
        $kind = $calendar_item->kind;
        $name = $calendar_item->name;
        $calendar_item->delete();
        MailProvisioner::enqueueCalendar($account);
        Audit::log('mail.calendar.remove', 'warning', 'account', $account->id, ['kind' => $kind, 'name' => $name]);

        return redirect()->route('calendar.index')->with('success', 'Hatane ke liye is queued.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'calendarItems']);
    }

    private function requireAccount(Request $request): Account
    {
        $account = $this->accountFor($request);
        if ($account === null) {
            abort(403, 'This login has no hosting account.');
        }

        return $account;
    }
}
