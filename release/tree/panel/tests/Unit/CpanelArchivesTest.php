<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Backup;
use App\Support\CpanelArchives;
use Tests\TestCase;

class CpanelArchivesTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir() . '/acp-cpanel-archives-' . bin2hex(random_bytes(4));
        mkdir($this->home . '/incoming', 0755, true);
        config(['acp.home' => $this->home]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->home)) {
            foreach ((array) glob($this->home . '/incoming/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->home . '/incoming');
            @rmdir($this->home);
        }
        parent::tearDown();
    }

    public function test_it_finds_cpanel_archives_in_the_drop_dir(): void
    {
        $archive = $this->home . '/incoming/cpmove-alicehost.tar.gz';
        file_put_contents($archive, str_repeat('x', 1048576)); // 1 MB

        $found = CpanelArchives::candidates();
        $paths = array_column($found, 'path');

        $this->assertContains($archive, $paths);
        $row = $found[array_search($archive, $paths, true)];
        $this->assertGreaterThan(0.0, $row['size_mb']);
    }

    public function test_it_ignores_files_that_are_not_cpanel_archives(): void
    {
        file_put_contents($this->home . '/incoming/notes.txt', 'hello');
        file_put_contents($this->home . '/incoming/cpmove-alicehost.zip', 'zip');
        file_put_contents($this->home . '/incoming/cpmove-bobhost.tar.gz', 'ok');

        $paths = array_column(CpanelArchives::candidates(), 'path');

        $this->assertSame([$this->home . '/incoming/cpmove-bobhost.tar.gz'], $paths);
    }

    public function test_the_candidate_list_is_capped(): void
    {
        for ($i = 0; $i < 30; $i++) {
            file_put_contents(sprintf('%s/incoming/cpmove-user%d.tar.gz', $this->home, $i), 'x');
        }

        $this->assertCount(25, CpanelArchives::candidates());
    }

    public function test_the_archive_path_validator_stays_strict(): void
    {
        $this->assertSame('/home/cpmove-alicehost.tar.gz', Backup::tryArchivePath('/home/cpmove-alicehost.tar.gz'));
        $this->assertSame('/home/x/y/backup-1.tar', Backup::tryArchivePath('/home/x/y/backup-1.tar'));
        $this->assertNull(Backup::tryArchivePath('home/cpmove-alicehost.tar.gz'));
        $this->assertNull(Backup::tryArchivePath('/home/../etc/cpmove-alicehost.tar.gz'));
        $this->assertNull(Backup::tryArchivePath('/home/cpmove-alicehost.zip'));
        $this->assertNull(Backup::tryArchivePath('/home/cpmove-alicehost.tar.gz|/bin/sh'));
        $this->assertNull(Backup::tryArchivePath("/home/cpmove-alice\0host.tar.gz"));
    }
}
