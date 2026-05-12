{{-- Cola en modo lista: un row por archivo. Reutilizable por cualquier theme.
     El theme aporta las clases visuales vía $listClasses (opcional). --}}
@php
    $cls = $listClasses ?? [];
    $row = $cls['row']  ?? 'flex items-center gap-2 px-2 py-1.5 bg-white border border-gray-200 rounded-sm';
    $name = $cls['name'] ?? 'text-xs text-gray-900 truncate';
    $size = $cls['size'] ?? 'text-[10px] font-mono text-gray-400 tabular-nums flex-shrink-0';
    $remove = $cls['remove'] ?? 'p-1 text-gray-400 hover:text-red-600';
    $statusOk    = $cls['statusOk']    ?? 'text-emerald-600';
    $statusErr   = $cls['statusErr']   ?? 'text-red-600';
    $statusUp    = $cls['statusUp']    ?? 'text-gray-500';
@endphp

<div class="space-y-1.5">
    <template x-for="(item, i) in queue" :key="item.id">
        <div class="{{ $row }}">
            <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                <path stroke-linecap="round" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8" fill="none" stroke="currentColor" stroke-width="1.75"/>
            </svg>
            <p class="{{ $name }} flex-1 min-w-0" x-text="item.name"></p>
            <p class="{{ $size }}" x-text="item.size < 1024 ? item.size + ' B' : (item.size < 1048576 ? (item.size / 1024).toFixed(1) + ' KB' : (item.size / 1048576).toFixed(1) + ' MB')"></p>

            <template x-if="item.status === 'uploading'">
                <svg class="animate-spin w-3.5 h-3.5 {{ $statusUp }}" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
            </template>
            <template x-if="item.status === 'done'">
                <svg class="w-4 h-4 {{ $statusOk }}" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                </svg>
            </template>
            <template x-if="item.status === 'error'">
                <button type="button" @click.stop="retryItem(i)" title="Reintentar" class="{{ $statusErr }} hover:opacity-70">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </button>
            </template>
            <template x-if="item.status === 'pending'">
                <button type="button" @click.stop="removeItem(i)" title="Quitar" class="{{ $remove }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </template>
        </div>
    </template>
</div>
