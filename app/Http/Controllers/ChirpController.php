<?php

namespace App\Http\Controllers;

use App\Events\ChirpCreated;
use App\Http\Requests\StoreChirpRequest;
use App\Http\Requests\UpdateChirpRequest;
use App\Models\Chirp;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ChirpController extends Controller
{
    public function index(): View
    {
        $chirps = Chirp::with('user')
            ->latest()
            ->latest('id')
            ->take(50)
            ->get();

        return view('home', ['chirps' => $chirps]);
    }

    public function store(StoreChirpRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $chirp = $user->chirps()->create($request->validated());

        ChirpCreated::dispatch($chirp);

        return redirect()->route('home')->with('success', 'Your chirp has been posted!');
    }

    public function edit(Chirp $chirp): View
    {
        Gate::authorize('update', $chirp);

        return view('chirps.edit', ['chirp' => $chirp]);
    }

    public function update(UpdateChirpRequest $request, Chirp $chirp): RedirectResponse
    {
        $chirp->update($request->validated());

        return redirect()->route('home')->with('success', 'Chirp updated!');
    }

    public function destroy(Chirp $chirp): RedirectResponse
    {
        Gate::authorize('delete', $chirp);

        $chirp->delete();

        return redirect()->route('home')->with('success', 'Chirp deleted!');
    }
}
