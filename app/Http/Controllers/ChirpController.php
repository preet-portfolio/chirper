<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreChirpRequest;
use App\Http\Requests\UpdateChirpRequest;
use App\Models\Chirp;
use App\Support\AuthorToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ChirpController extends Controller
{
    public function index(): View
    {
        $chirps = Chirp::live()
            ->latest()
            ->latest('id')
            ->take(50)
            ->get();

        return view('home', ['chirps' => $chirps]);
    }

    public function store(StoreChirpRequest $request, AuthorToken $author): RedirectResponse
    {
        if (! $request->isFromBot()) {
            Chirp::pruneExpired();

            $token = $author->currentOrNew();

            $chirp = new Chirp($request->validated());
            $chirp->author_token_hash = AuthorToken::hash($token);
            $chirp->save();

            Cookie::queue(Cookie::make(AuthorToken::COOKIE, $token, AuthorToken::cookieMinutes(), httpOnly: true));
        }

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
