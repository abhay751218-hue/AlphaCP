<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/** cPanel "Images" — account ke image files ki list (thumbnail server-side nahi). */
final class ImagesController extends Controller
{
    private const EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

    public function index(): View
    {
        $base = rtrim((string) (config('acp.images_base') ?: storage_path('app/images')), '/');
        $images = [];

        if (is_dir($base)) {
            foreach (scandir($base) ?: [] as $f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, self::EXT, true)) {
                    $images[] = $f;
                }
            }
        }

        return view('images.index', ['images' => $images]);
    }
}
