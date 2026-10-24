@props(['chirp'])

<article class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="chirp">
    <div class="flex items-baseline gap-2">
        <flux:heading size="sm">{{ $chirp->user->name }}</flux:heading>
        <flux:text size="sm">
            <time datetime="{{ $chirp->created_at?->toIso8601String() }}">{{ $chirp->created_at?->diffForHumans() }}</time>
            @if ($chirp->updated_at?->gt($chirp->created_at))
                · {{ __('edited') }}
            @endif
        </flux:text>
    </div>
    <p class="mt-2 whitespace-pre-line break-words text-zinc-800 dark:text-zinc-100">{{ $chirp->message }}</p>
</article>
