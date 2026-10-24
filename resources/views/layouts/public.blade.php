<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <x-app-logo href="{{ route('home') }}" />

            <flux:spacer />

            <flux:navbar class="space-x-0.5 py-0!">
                @auth
                    <flux:navbar.item :href="route('profile.edit')">{{ auth()->user()->name }}</flux:navbar.item>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <flux:navbar.item as="button" type="submit" data-test="logout-button">{{ __('Log out') }}</flux:navbar.item>
                    </form>
                @else
                    <flux:navbar.item :href="route('login')">{{ __('Log in') }}</flux:navbar.item>
                    <flux:navbar.item :href="route('register')">{{ __('Register') }}</flux:navbar.item>
                @endauth
            </flux:navbar>
        </flux:header>

        <flux:main container class="max-w-2xl">
            {{ $slot }}
        </flux:main>

        @fluxScripts
    </body>
</html>
