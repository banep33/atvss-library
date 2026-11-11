<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PrimerakKnjige;

class NormalizeInventarski extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'normalize:inventarski {--backup : Write a CSV backup of affected rows} {--dry-run : Do not modify database, only show changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize inventarski_broj values to plain numeric sequence and backup affected rows';

    public function handle(): int
    {
        $this->info('Scanning `primerci_knjiga` for inconsistent `inventarski_broj` values (non-numeric or equal to signatura)...');

        $all = PrimerakKnjige::all();

        // Compute current numeric max from values that are purely digits
        $numericValues = $all->map(function ($p) {
            return preg_match('/^\d+$/', (string)$p->inventarski_broj) ? (int)$p->inventarski_broj : null;
        })->filter()->values()->all();

        $max = 0;
        if (!empty($numericValues)) {
            $max = max($numericValues);
        }

        $this->info("Current max numeric inventarski_broj: {$max}");

        $toFix = $all->filter(function ($p) {
            $inv = (string)$p->inventarski_broj;
            $sig = (string)$p->signatura;
            // Fix when not purely numeric OR when inventarski equals signatura
            return !preg_match('/^\d+$/', $inv) || ($inv !== '' && $inv === $sig);
        });

        if ($toFix->isEmpty()) {
            $this->info('No non-numeric `inventarski_broj` values found. Nothing to do.');
            return 0;
        }

        $backupPath = storage_path('app/normalize_inventarski_backup_'.date('Ymd_His').'.csv');
        // Always write a backup of affected rows before making changes
        if (!$toFix->isEmpty()) {
            $fh = fopen($backupPath, 'w');
            fputcsv($fh, ['id','knjiga_id','old_inventarski_broj','signatura','status','lokacija','datum_unosa']);
            foreach ($toFix as $p) {
                fputcsv($fh, [
                    $p->id,
                    $p->knjiga_id,
                    $p->inventarski_broj,
                    $p->signatura,
                    $p->status,
                    $p->lokacija,
                    $p->datum_unosa,
                ]);
            }
            fclose($fh);
            $this->info("Backup written to: {$backupPath}");
        }

        $this->info('Assigning new numeric `inventarski_broj` values...');
        $next = $max + 1;
        $updated = 0;

        foreach ($toFix as $p) {
            $old = $p->inventarski_broj;
            // assign next numeric string
            $newVal = (string)$next;
            if ($this->option('dry-run')) {
                $this->line("Would update id {$p->id}: '{$old}' -> '{$newVal}'");
            } else {
                $p->inventarski_broj = $newVal;
                $p->save();
                $this->line("Updated id {$p->id}: '{$old}' -> '{$p->inventarski_broj}'");
            }
            $next++;
            $updated++;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry-run complete. {$updated} rows would be updated.");
        } else {
            $this->info("Normalization complete. {$updated} rows updated.");
        }
        $this->info("Backup file: {$backupPath}");

        return 0;
    }
}
