<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreChirpRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ChirpController extends Controller
{
    public function index(): View
    {
        return view('home');
    }

    public function store(StoreChirpRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->chirps()->create($request->validated());

        return redirect()->route('home')->with('success', 'Your chirp has been posted!');
    }
}
