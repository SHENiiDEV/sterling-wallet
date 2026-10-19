<?php

namespace App\Console\Commands;

use App\Merchants\BankSheetImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('banks:import {file : CSV of the banks sheet} {--apply : Write the changes (without it only the differences are shown)}')]
#[Description('Load bank terms, merchants and their onboarding status per bank from the banks sheet')]
class ImportBankSheetCommand extends Command
{
    public function handle(BankSheetImporter $importer): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $log = $importer->import((string) file_get_contents($path), $apply);

        foreach ($log as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->info($log === [] ? 'Everything already matches.' : ($apply ? count($log).' change(s) applied.' : count($log).' change(s). Run with --apply to write them.'));

        return self::SUCCESS;
    }
}
