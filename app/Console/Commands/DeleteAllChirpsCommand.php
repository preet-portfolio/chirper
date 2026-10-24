<?php

namespace App\Console\Commands;

use App\Models\Chirp;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

#[Signature('chirps:delete-all {--force : Run without confirmation in production}')]
#[Description('Delete every chirp (the demo reset)')]
class DeleteAllChirpsCommand extends Command
{
    use ConfirmableTrait;

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        // A query delete, not truncate: see docs/adr/0006-delete-all-chirps.md.
        $count = Chirp::query()->delete();

        $this->info("Successfully deleted {$count} chirps.");

        return self::SUCCESS;
    }
}
