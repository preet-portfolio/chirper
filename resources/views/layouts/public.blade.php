<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <x-app-logo href="{{ route('home') }}" />

        </flux:header>

        <flux:main container class="max-w-2xl">
            <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6" data-test="disclaimer">
                <flux:callout.heading>{{ __('Public test board') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __("Anyone can read what you post here. Don't include personal data: no names, emails, phone numbers, addresses or anything that identifies you or someone else. Chirps are anonymous and are deleted after 24 hours.") }}
                </flux:callout.text>
            </flux:callout>

            {{ $slot }}
        </flux:main>

        @fluxScripts
    </body>
</html>
