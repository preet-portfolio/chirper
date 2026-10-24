<x-layouts::public :title="__('Edit chirp')">
    <form method="POST" action="{{ route('chirps.update', $chirp) }}" class="space-y-3">
        @csrf
        @method('PUT')
        <flux:textarea
            name="message"
            :label="__('Edit your chirp')"
            rows="3"
            maxlength="255"
            required
        >{{ old('message', $chirp->message) }}</flux:textarea>
        <div class="flex gap-2">
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            <flux:button :href="route('home')" variant="ghost">{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</x-layouts::public>
