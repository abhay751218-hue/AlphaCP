<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OptimizeSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel "Optimize Website" — content compression setting. */
final class OptimizeController extends Controller
{
    public function index(Request $request): View
    {
        $rec = OptimizeSetting::query()->where('user_id', $request->user()->id)->first();

        return view('optimize.index', ['level' => $rec?->level ?? 'disabled']);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['level' => 'required|in:disabled,all,html']);

        OptimizeSetting::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['level' => $data['level']],
        );

        return redirect('/optimize-website');
    }
}
