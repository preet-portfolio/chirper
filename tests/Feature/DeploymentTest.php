<?php

use App\Models\User;

test('urls use https behind a TLS-terminating proxy', function () {
    $this->actingAs(User::factory()->create())
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
        ->get('/')
        ->assertOk()
        ->assertSee('action="https://localhost/chirps"', escape: false);
});

test('a forged X-Forwarded-Host cannot poison generated urls', function () {
    $this->actingAs(User::factory()->create())
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example.com'])
        ->get('/')
        ->assertOk()
        ->assertDontSee('evil.example.com');
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

test('pgsql connection adds the Neon endpoint id for SNI-less clients', function () {
    putenv('DATABASE_URL=postgresql://u:p@ep-a-1-pooler.us-east-2.aws.neon.tech/chirper?sslmode=require');

    try {
        $config = require config_path('database.php');
    } finally {
        putenv('DATABASE_URL');
    }

    expect(rawurldecode(parse_url($config['connections']['pgsql']['url'])['pass']))->toBe('endpoint=ep-a-1$p');
});
