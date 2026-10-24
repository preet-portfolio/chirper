<?php

use App\Support\NeonUrl;
use Illuminate\Support\ConfigurationUrlParser;

test('neon urls carry the endpoint id in the password for clients without SNI', function (string $host, string $endpoint) {
    $url = NeonUrl::withEndpointInPassword("postgresql://app:s3cret@{$host}/chirper?sslmode=require");

    $parts = parse_url($url);

    expect(rawurldecode($parts['pass']))->toBe("endpoint={$endpoint}\$s3cret")
        ->and($parts['user'])->toBe('app')
        ->and($parts['host'])->toBe($host)
        ->and($parts['path'])->toBe('/chirper')
        ->and($parts['query'])->toBe('sslmode=require');
})->with([
    'direct' => ['ep-cool-darkness-123456.us-east-2.aws.neon.tech', 'ep-cool-darkness-123456'],
    'pooled' => ['ep-cool-darkness-123456-pooler.us-east-2.aws.neon.tech', 'ep-cool-darkness-123456'],
]);

test('passwords with url-encoded characters survive', function () {
    $url = NeonUrl::withEndpointInPassword('postgres://app:p%40ss%2Fw0rd@ep-x-1.eu-central-1.aws.neon.tech/db');

    expect(rawurldecode(parse_url($url)['pass']))->toBe('endpoint=ep-x-1$p@ss/w0rd');
});

test('the url Laravel parses yields the prefixed password', function () {
    $url = NeonUrl::withEndpointInPassword('postgresql://app:s3cret@ep-a-1-pooler.us-east-2.aws.neon.tech/chirper');

    $config = (new ConfigurationUrlParser)->parseConfiguration(['url' => $url]);

    expect($config['password'])->toBe('endpoint=ep-a-1$s3cret')
        ->and($config['host'])->toBe('ep-a-1-pooler.us-east-2.aws.neon.tech');
});

test('non-neon, already-prefixed and empty urls are left alone', function (mixed $url) {
    expect(NeonUrl::withEndpointInPassword($url))->toBe($url);
})->with([
    'other host' => ['postgresql://app:s3cret@db.example.com/chirper'],
    'already prefixed' => ['postgresql://app:endpoint%3Dep-x-1%24s3cret@ep-x-1.us-east-2.aws.neon.tech/db'],
    'empty' => [''],
    'null' => [null],
    'env boolean' => [true],
]);
