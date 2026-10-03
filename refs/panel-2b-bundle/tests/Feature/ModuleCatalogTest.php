<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ModuleCatalog;
use Tests\TestCase;

/** The dashboard IS the parity contract — these tests keep it honest. */
class ModuleCatalogTest extends TestCase
{
    public function test_every_section_has_label_and_items(): void
    {
        foreach (ModuleCatalog::sections() as $key => $section) {
            $this->assertArrayHasKey('label', $section, $key);
            $this->assertNotEmpty($section['items'], $key);
        }
    }

    public function test_every_item_is_live_step_or_addon(): void
    {
        foreach (ModuleCatalog::sections() as $sectionKey => $section) {
            foreach ($section['items'] as $item) {
                $this->assertContains($section['audience'] ?? '', ['cpanel', 'whm', 'both'], "{$sectionKey} audience");
                $this->assertContains($item['status'], ['live', 'step', 'addon'], "{$sectionKey}/{$item['name']}");
                $this->assertNotEmpty($item['name']);
                $this->assertNotEmpty($item['step']);

                if ($item['status'] === 'live') {
                    $this->assertNotEmpty($item['route'], "live tile needs a route: {$item['name']}");
                    $this->assertTrue(
                        app('router')->has($item['route']),
                        "route '{$item['route']}' for tile '{$item['name']}' does not exist",
                    );
                }
            }
        }
    }

    public function test_progress_numbers_add_up(): void
    {
        $progress = ModuleCatalog::progress();
        $this->assertSame($progress['total'], $progress['live'] + $progress['planned'] + $progress['addon']);
        $this->assertGreaterThan(0, $progress['live']);
        $this->assertGreaterThanOrEqual(0, $progress['percent']);
        $this->assertLessThanOrEqual(100, $progress['percent']);
    }
}
