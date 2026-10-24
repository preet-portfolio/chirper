@props(['chirp'])

<article class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="chirp">
    <div class="flex items-baseline gap-2">
        <flux:heading size="sm">{{ __('Anonymous') }}</flux:heading>
        <flux:text size="sm">
            <time datetime="{{ $chirp->created_at?->toIso8601String() }}">{{ $chirp->created_at?->diffForHumans() }}</time>
            @if ($chirp->updated_at?->gt($chirp->created_at))
                · {{ __('edited') }}
            @endif
        </flux:text>
        <div class="ms-auto flex items-center gap-3 text-sm">
            @can('update', $chirp)
                <flux:link :href="route('chirps.edit', $chirp)">{{ __('Edit') }}</flux:link>
            @endcan
            @can('delete', $chirp)
                <form method="POST" action="{{ route('chirps.destroy', $chirp) }}" onsubmit="return confirm(@js(__('Delete this chirp?')))">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-red-600 hover:underline dark:text-red-400">{{ __('Delete') }}</button>
                </form>
            @endcan
        </div>
    </div>
    <p class="mt-2 whitespace-pre-line break-words text-zinc-800 dark:text-zinc-100">{{ $chirp->message }}</p>
</article>
