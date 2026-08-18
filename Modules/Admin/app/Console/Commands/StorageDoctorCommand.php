<?php

namespace Modules\Admin\Console\Commands;

use App\Support\PublicUrl;
use Illuminate\Console\Command;
use Modules\Admin\Models\Attachment;
use Modules\Expense\Models\ExpenseAttachment;

/**
 * «الصورة بترجع 404» — say WHICH of the four joints is broken instead of
 * guessing, and never prescribe `storage:link --force` (on this project the
 * public disk's root IS `public/storage`, so that command replaces the real
 * directory with a symlink and destroys every uploaded receipt).
 *
 *   1. the configured URL prefix   (filesystems.disks.public.url)
 *   2. the on-disk root            (public_path('storage') — a real dir here)
 *   3. the physical file           (root + the row's storage key)
 *   4. the URL the API serves      (derived, so it follows 1 automatically)
 *
 * Read-only.
 */
class StorageDoctorCommand extends Command
{
    protected $signature = 'asab:storage-doctor
        {--sample=3 : How many recent attachments to resolve end to end}';

    protected $description = 'Explain why an uploaded attachment 404s (URL prefix, disk root, missing file)';

    public function handle(): int
    {
        $prefix = (string) config('filesystems.disks.public.url');
        $root = (string) config('filesystems.disks.public.root');

        $this->line('');
        $this->info('— configuration —');
        $this->line('  APP_URL              : '.(string) config('app.url'));
        $this->line('  ASSET_URL            : '.((string) config('app.asset_url') ?: '(unset)'));
        $this->line('  public disk url      : '.$prefix);
        $this->line('  public disk root     : '.$root);

        $ok = true;

        if (str_contains(substr($prefix, 8), '//')) {
            $ok = false;
            $this->error('  ✗ the URL prefix contains a doubled slash — set APP_URL/ASSET_URL without a trailing slash, then `php artisan config:clear`.');
        }

        if (! is_dir($root)) {
            $ok = false;
            $this->error('  ✗ the disk root does not exist: '.$root);
            $this->line('    Create it (mkdir -p) and re-upload. Do NOT run `storage:link --force` — it deletes this directory.');
        } elseif (is_link($root)) {
            $this->warn('  ! the disk root is a SYMLINK. This project writes uploads directly into public/storage;');
            $this->warn('    a symlink here means earlier uploads may live on the other side of it.');
        } else {
            $this->line('  ✓ disk root exists and is a real directory');
        }

        $this->line('');
        $this->info('— sample attachments —');

        $limit = max(1, (int) $this->option('sample'));

        try {
            $rows = $this->sample($limit);
        } catch (\Illuminate\Database\QueryException $e) {
            // The configuration half above is the useful half and needs no DB —
            // do not lose it to an unreachable database.
            $this->warn('  could not read the attachment tables: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->warn('  no attachments on record yet — nothing to resolve.');

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        foreach ($rows as [$source, $key]) {
            $file = rtrim($root, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $key);
            $exists = is_file($file);
            $ok = $ok && $exists;

            $this->line('  '.$source.' → '.$key);
            $this->line('    file : '.($exists ? '✓ present' : '✗ MISSING').' — '.$file);
            $this->line('    url  : '.PublicUrl::for($key));
        }

        $this->line('');
        $this->line('Open the printed URL in a browser. A 200 means the chain is whole;');
        $this->line('a 404 with the file present means the web server is not serving that');
        $this->line('prefix — set ASSET_URL to the prefix that IS reachable (e.g. https://host/public).');
        $this->line('');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Recent storage keys from both worlds: the dashboard mirror and the mobile
     * expense uploads (the two that produced the reported 404).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function sample(int $limit): array
    {
        $rows = [];

        foreach (Attachment::query()->latest('uploaded_at')->limit($limit)->get(['storage_key']) as $a) {
            if (trim((string) $a->storage_key) !== '') {
                $rows[] = ['asab_attachments ', $a->storage_key];
            }
        }

        foreach (ExpenseAttachment::query()->latest('created_at')->limit($limit)->get(['file_path']) as $a) {
            if (trim((string) $a->file_path) !== '') {
                $rows[] = ['expense_attachments', $a->file_path];
            }
        }

        return $rows;
    }
}
