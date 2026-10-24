<?php

use App\Models\User;

test('urls use https behind a TLS-terminating proxy', function () {
    $this->actingAs(User::factory()->create())
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'chirper.example.com', 'X-Forwarded-Port' => '443'])
        ->get('/')
        ->assertOk()
        ->assertSee('action="https://chirper.example.com/chirps"', escape: false);
});

test('pgsql connection falls back to the DATABASE_URL a hosting integration provides', function () {
    putenv('DATABASE_URL=postgresql://u:p@db.example.com:5432/chirper?sslmode=require');
    putenv('DB_URL');

    try {
        $config = require config_path('database.php');
    } finally {
        putenv('DATABASE_URL');
    }

    expect($config['connections']['pgsql']['url'])
        ->toBe('postgresql://u:p@db.example.com:5432/chirper?sslmode=require');
});
