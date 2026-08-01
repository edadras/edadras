<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * The nightly backup: the database plus whatever members and clubs uploaded,
 * zipped and pushed to the configured disk. Old archives are pruned so the
 * disk does not fill up silently.
 *
 * MySQL is dumped with mysqldump; SQLite is copied, because the file is the
 * database. Nothing here reaches outside the configured disk.
 */
class RunBackup extends Command
{
    protected $signature = 'gymflow:backup
        {--database-only : Skip the uploaded files}
        {--keep= : How many archives to keep, overriding the configured number}';

    protected $description = 'Back up the database and uploaded files to the configured disk.';

    public function handle(): int
    {
        if (! config('gymflow.backup.enabled')) {
            $this->warn('Backups are switched off. Set GYMFLOW_BACKUP_ENABLED=true to run them.');

            return self::SUCCESS;
        }

        $workingDir = storage_path('app/backup-tmp');

        if (! is_dir($workingDir) && ! mkdir($workingDir, 0775, true) && ! is_dir($workingDir)) {
            $this->error("Could not create {$workingDir}.");

            return self::FAILURE;
        }

        $stamp = now()->format('Y-m-d_His');
        $archivePath = "{$workingDir}/gymflow-{$stamp}.zip";

        try {
            $dump = $this->dumpDatabase($workingDir, $stamp);
            $this->buildArchive($archivePath, $dump);
            $name = $this->store($archivePath, $stamp);
            $this->prune();

            $this->info("Backup written to {$name} (".$this->humanSize(filesize($archivePath)).').');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $this->cleanUp($workingDir);
        }
    }

    /** @return string path to the dump on local disk */
    protected function dumpDatabase(string $workingDir, string $stamp): string
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        return match ($config['driver']) {
            'sqlite' => $this->copySqlite($config, $workingDir, $stamp),
            'mysql', 'mariadb' => $this->dumpMysql($config, $workingDir, $stamp),
            'pgsql' => $this->dumpPostgres($config, $workingDir, $stamp),
            default => throw new RuntimeException("No backup strategy for driver [{$config['driver']}]."),
        };
    }

    protected function copySqlite(array $config, string $workingDir, string $stamp): string
    {
        $target = "{$workingDir}/database-{$stamp}.sqlite";

        // VACUUM INTO gives a consistent copy even while the app is writing.
        DB::statement('VACUUM INTO ?', [$target]);

        return $target;
    }

    protected function dumpMysql(array $config, string $workingDir, string $stamp): string
    {
        $target = "{$workingDir}/database-{$stamp}.sql";

        $process = Process::fromShellCommandline(
            'mysqldump --defaults-extra-file=${:CREDENTIALS} --single-transaction --quick '
            .'--routines --no-tablespaces --host=${:HOST} --port=${:PORT} ${:DATABASE} > ${:TARGET}'
        );

        $credentials = $this->mysqlCredentialsFile($config, $workingDir);

        $process->run(null, [
            'CREDENTIALS' => $credentials,
            'HOST' => $config['host'],
            'PORT' => (string) $config['port'],
            'DATABASE' => $config['database'],
            'TARGET' => $target,
        ]);

        @unlink($credentials);

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump failed: '.trim($process->getErrorOutput()));
        }

        return $target;
    }

    protected function dumpPostgres(array $config, string $workingDir, string $stamp): string
    {
        $target = "{$workingDir}/database-{$stamp}.sql";

        $process = Process::fromShellCommandline(
            'pg_dump --host=${:HOST} --port=${:PORT} --username=${:USERNAME} --no-password ${:DATABASE} > ${:TARGET}'
        );

        $process->run(null, [
            'HOST' => $config['host'],
            'PORT' => (string) $config['port'],
            'USERNAME' => $config['username'],
            'DATABASE' => $config['database'],
            'TARGET' => $target,
            'PGPASSWORD' => (string) $config['password'],
        ]);

        if (! $process->isSuccessful()) {
            throw new RuntimeException('pg_dump failed: '.trim($process->getErrorOutput()));
        }

        return $target;
    }

    /**
     * The password never goes on the command line, where `ps` would show it
     * to every other user on the box.
     */
    protected function mysqlCredentialsFile(array $config, string $workingDir): string
    {
        $path = "{$workingDir}/.my.cnf";

        file_put_contents($path, "[client]\nuser={$config['username']}\npassword=\"{$config['password']}\"\n");
        chmod($path, 0600);

        return $path;
    }

    protected function buildArchive(string $archivePath, string $dump): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not open {$archivePath} for writing.");
        }

        $zip->addFile($dump, 'database/'.basename($dump));

        if (! $this->option('database-only')) {
            $this->addUploads($zip);
        }

        $zip->close();
    }

    protected function addUploads(ZipArchive $zip): void
    {
        $root = storage_path('app/public');

        if (! is_dir($root)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), 'uploads/'.substr($file->getPathname(), strlen($root) + 1));
            }
        }
    }

    protected function store(string $archivePath, string $stamp): string
    {
        $disk = Storage::disk(config('gymflow.backup.disk'));
        $name = trim(config('gymflow.backup.path', 'backups'), '/')."/gymflow-{$stamp}.zip";

        $handle = fopen($archivePath, 'r');
        $disk->put($name, $handle);

        if (is_resource($handle)) {
            fclose($handle);
        }

        return $name;
    }

    /** Keeps the newest N archives and deletes the rest. */
    protected function prune(): void
    {
        $keep = (int) ($this->option('keep') ?? config('gymflow.backup.keep', 14));

        if ($keep <= 0) {
            return;
        }

        $disk = Storage::disk(config('gymflow.backup.disk'));
        $directory = trim(config('gymflow.backup.path', 'backups'), '/');

        $archives = collect($disk->files($directory))
            ->filter(fn (string $file) => str_ends_with($file, '.zip'))
            ->sortDesc()
            ->values();

        foreach ($archives->slice($keep) as $stale) {
            $disk->delete($stale);
            $this->line("Pruned {$stale}.");
        }
    }

    protected function cleanUp(string $workingDir): void
    {
        foreach (glob("{$workingDir}/*") ?: [] as $file) {
            @unlink($file);
        }
    }

    protected function humanSize(int|false $bytes): string
    {
        if ($bytes === false) {
            return 'unknown size';
        }

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1)." {$unit}";
            }

            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }
}
