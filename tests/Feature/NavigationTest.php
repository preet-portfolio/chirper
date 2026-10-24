<?php

use App\Models\User;

test('the starter kit dashboard no longer exists', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertNotFound();
});

test('settings pages link back to the timeline', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('href="'.route('home').'"', escape: false);
});

test('public header links a signed-in user to their settings', function () {
    $this->actingAs(User::factory()->create(['name' => 'Ada']))
        ->get(route('home'))
        ->assertSee('href="'.route('profile.edit').'"', escape: false);
});
