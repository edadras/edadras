<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The nightly archive: it has to actually contain the data. */
class BackupTest extends TestCase
{
    public function test_it_does_nothing_while_backups_are_switched_off(): void
    {
        config(['gymflow.backup.enabled' => false]);
        Storage::fake('backups');

        $this->artisan('gymflow:backup')->assertSuccessful();

        $this->assertEmpty(Storage::disk('backups')->allFiles());
    }

    public function test_it_writes_an_archive_holding_the_database(): void
    {
        $disk = Storage::fake('backups');
        config([
            'gymflow.backup.enabled' => true,
            'gymflow.backup.disk' => 'backups',
            'gymflow.backup.path' => 'nightly',
        ]);

        $this->artisan('gymflow:backup --database-only')->assertSuccessful();

        $files = collect($disk->allFiles())->filter(fn ($f) => str_ends_with($f, '.zip'))->values();

        $this->assertCount(1, $files);
        $this->assertStringStartsWith('nightly/gymflow-', $files[0]);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($disk->path($files[0])) === true);
        $this->assertGreaterThan(0, $zip->numFiles);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertNotEmpty(array_filter($names, fn ($n) => str_starts_with($n, 'database/')));
    }

    public function test_it_prunes_down_to_the_number_it_is_told_to_keep(): void
    {
        $disk = Storage::fake('backups');
        config([
            'gymflow.backup.enabled' => true,
            'gymflow.backup.disk' => 'backups',
            'gymflow.backup.path' => 'nightly',
        ]);

        foreach (['2020-01-01_000000', '2020-01-02_000000', '2020-01-03_000000'] as $stamp) {
            $disk->put("nightly/gymflow-{$stamp}.zip", 'old');
        }

        $this->artisan('gymflow:backup --database-only --keep=2')->assertSuccessful();

        $this->assertCount(2, $disk->files('nightly'));
    }

    public function test_the_working_directory_is_left_clean(): void
    {
        Storage::fake('backups');
        config(['gymflow.backup.enabled' => true, 'gymflow.backup.disk' => 'backups']);

        $this->artisan('gymflow:backup --database-only')->assertSuccessful();

        $this->assertEmpty(glob(storage_path('app/backup-tmp/*')) ?: []);
    }
}
