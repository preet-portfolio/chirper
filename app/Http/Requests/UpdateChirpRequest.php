<?php

namespace App\Http\Requests;

use App\Models\Chirp;
use Illuminate\Support\Facades\Gate;

class UpdateChirpRequest extends StoreChirpRequest
{
    /**
     * Authorize before validating, so non-authors get a 403 rather than validation errors.
     */
    public function authorize(): bool
    {
        /** @var Chirp $chirp */
        $chirp = $this->route('chirp');

        return Gate::allows('update', $chirp);
    }
}
