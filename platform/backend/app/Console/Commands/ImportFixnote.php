<?php

namespace App\Console\Commands;

use App\Services\Fixnote;
use Illuminate\Console\Command;

class ImportFixnote extends Command
{
    protected $signature = 'shop:import-fixnote {file? : JSON file with an items list of code, name, inspired_by, notes (default: the Fixnote catalogue)}';

    protected $description = 'Add the Fixnote perfumes that are not in the shop yet (existing codes are left as they are)';

    public function handle(Fixnote $fixnote): int
    {
        $file = $this->argument('file');
        if ($file !== null && ! is_file($file)) {
            $this->error("File not found: $file");

            return self::FAILURE;
        }
        try {
            $r = $fixnote->import($file);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Fixnote perfumes added: {$r['created']} (already in the shop: {$r['skipped']}).");

        return self::SUCCESS;
    }
}
