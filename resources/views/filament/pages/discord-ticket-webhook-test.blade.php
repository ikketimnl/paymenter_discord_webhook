<x-filament::page>
    {{ $this->form }}

    <div class="mt-4">
        <x-filament::button wire:click="sendTest" color="primary">
            Send test
        </x-filament::button>
    </div>
</x-filament::page>
