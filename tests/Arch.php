<?php

use App\Providers\TelescopeServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Architecture rules
|--------------------------------------------------------------------------
|
| Static rules over app/ (and the autoloaded Database\ namespaces). They read
| source, so they never boot the app. Rules target namespaces rather than
| individual classes, so new notifications, listeners or console commands
| only have to sit in their conventional namespace to pass.
|
*/

arch('env() is only called from config/')
    ->expect('env')
    ->not->toBeUsed();

arch('no debugging helpers are left behind')
    ->expect(['dd', 'ddd', 'dump', 'ray', 'var_dump', 'die', 'exit'])
    ->not->toBeUsed();

arch('controllers extend the base Controller')
    ->expect('App\Http\Controllers')
    ->classes()
    ->toExtend('App\Http\Controllers\Controller')
    ->ignoring('App\Http\Controllers\Controller');

arch('controllers do not query through the DB facade')
    ->expect('App\Http\Controllers')
    ->not->toUse(DB::class);

arch('models extend Eloquent Model')
    ->expect('App\Models')
    ->classes()
    ->toExtend(Model::class);

arch('form requests live in App\Http\Requests and extend FormRequest')
    ->expect('App\Http\Requests')
    ->classes()
    ->toExtend(FormRequest::class)
    ->toHaveSuffix('Request');

arch('nothing outside App\Http\Requests extends FormRequest')
    ->expect('App')
    ->classes()
    ->not->toExtend(FormRequest::class)
    ->ignoring('App\Http\Requests');

arch('policies have the Policy suffix')
    ->expect('App\Policies')
    ->classes()
    ->toHaveSuffix('Policy');

arch('PHP preset: no debug, echo or legacy functions')
    ->preset()->php();

arch('security preset: no weak hashing, insecure randomness or eval')
    ->preset()->security();

// Framework conventions per namespace (Notifications extend Notification,
// Console\Commands extend Command and have handle(), Listeners have handle(),
// controllers expose only resource methods, ...). It overlaps some rules
// above; those stay so each convention has its own named, readable failure.
// The one sanctioned provider-to-provider reference: AppServiceProvider registers
// Telescope's provider in local only (Laravel's documented local-only pattern).
arch('Laravel preset: framework namespace conventions')
    ->preset()->laravel()
    ->ignoring(TelescopeServiceProvider::class);

// Not enabled: preset()->strict(). It demands declare(strict_types=1) and
// final on every class, which the Livewire starter kit (Fortify actions,
// providers, User model) doesn't follow, and final models/controllers fight
// Laravel's own extension points (mocking, partial mocks, proxies).
