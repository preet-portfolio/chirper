<x-layouts::public :title="__('Chirper')">
    @if (session('success'))
        <flux:callout variant="success" icon="check-circle" class="mb-6" :heading="session('success')" />
    @endif

        <form method="POST" action="{{ route('chirps.store') }}" class="space-y-3">
            @csrf
            <flux:textarea
                name="message"
                error:name="message"
                :label="__('What\'s on your mind?')"
                rows="3"
                maxlength="255"
                required
            >{{ old('message') }}</flux:textarea>
            {{-- Honeypot: hidden from people, filled in by bots (StoreChirpRequest::isFromBot). --}}
            <div class="hidden" aria-hidden="true">
                <label>{{ __('Leave this empty') }} <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>
            <flux:button type="submit" variant="primary">{{ __('Chirp') }}</flux:button>
        </form>

    <section class="mt-8 space-y-4">
        @forelse ($chirps as $chirp)
            <x-chirp :chirp="$chirp" />
        @empty
            <flux:text class="text-center">{{ __('No chirps yet. Be the first to chirp!') }}</flux:text>
        @endforelse
    </section>
</x-layouts::public>
