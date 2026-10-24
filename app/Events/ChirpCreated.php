<?php

namespace App\Events;

use App\Models\Chirp;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A user posted a chirp through the app (not a factory or seeder; see ADR 0007).
 */
class ChirpCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Chirp $chirp) {}
}
