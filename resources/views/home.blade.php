<x-layouts::public :title="__('Chirper')">
    @if (session('success'))
        <flux:callout variant="success" icon="check-circle" class="mb-6" :heading="session('success')" />
    @endif

    @auth
        <form method="POST" action="{{ route('chirps.store') }}" class="space-y-3">
            @csrf
            <flux:textarea
                name="message"
                :label="__('What\'s on your mind?')"
                rows="3"
                maxlength="255"
                required
            >{{ old('message') }}</flux:textarea>
            <flux:button type="submit" variant="primary">{{ __('Chirp') }}</flux:button>
        </form>
    @endauth
</x-layouts::public>
