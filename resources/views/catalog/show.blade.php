@extends('catalog.layout')

@section('title', $product->title ?: 'Товар')

@section('content')
    <nav class="text-sm text-neutral-500 mb-4 flex flex-wrap gap-2 items-center">
        <a href="{{ route('catalog.index') }}" class="hover:text-sky-700">Каталог</a>
        @if($category)
            <span>/</span>
            <a href="{{ route('catalog.index', ['category' => $category->id]) }}" class="hover:text-sky-700">
                {{ $category->name ?: 'Категория' }}
            </a>
        @endif
        @if($subcategory)
            <span>/</span>
            <a href="{{ route('catalog.index', ['category' => $category?->id, 'subcategory' => $subcategory->id]) }}" class="hover:text-sky-700">
                {{ $subcategory->name }}
            </a>
        @endif
    </nav>

    <div class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-6 bg-white rounded-2xl border border-neutral-200 p-6">
        <div class="aspect-square bg-neutral-100 rounded-xl overflow-hidden">
            <img src="{{ route('catalog.image', $product->id) }}"
                 alt="{{ $product->title }}"
                 class="w-full h-full object-cover" />
        </div>

        <div class="flex flex-col">
            @if($product->brand)
                <div class="text-sm font-medium text-sky-700 mb-1">{{ $product->brand }}</div>
            @endif
            <h1 class="text-2xl font-semibold text-neutral-900 leading-tight">
                {{ $product->title ?: 'Без названия' }}
            </h1>

            <dl class="mt-5 grid grid-cols-[140px_1fr] gap-y-2 gap-x-4 text-sm">
                <dt class="text-neutral-500">WB ID</dt>
                <dd class="text-neutral-800 font-medium">{{ $product->wb_id }}</dd>

                @if($product->supplier)
                    <dt class="text-neutral-500">Поставщик</dt>
                    <dd class="text-neutral-800">{{ $product->supplier }}</dd>
                @endif

                @if($category)
                    <dt class="text-neutral-500">Категория</dt>
                    <dd class="text-neutral-800">{{ $category->name }}</dd>
                @endif

                @if($subcategory)
                    <dt class="text-neutral-500">Подкатегория</dt>
                    <dd class="text-neutral-800">{{ $subcategory->name }}</dd>
                @endif
            </dl>

            @if(!empty($product->characteristics))
                <div class="mt-6">
                    <h2 class="text-sm font-semibold text-neutral-500 uppercase tracking-wider mb-3">Характеристики</h2>
                    <ul class="space-y-1 text-sm">
                        @foreach($product->characteristics as $char)
                            @php
                                $key = is_array($char) ? ($char['key'] ?? null) : null;
                                $value = is_array($char) ? ($char['value'] ?? null) : null;
                                if (is_array($value)) {
                                    $value = implode(', ', $value);
                                }
                            @endphp
                            @if($key && $value !== null && $value !== '')
                                <li class="flex justify-between gap-4 py-1.5 border-b border-neutral-100 last:border-0">
                                    <span class="text-neutral-500">{{ $key }}</span>
                                    <span class="text-neutral-800 text-right">{{ $value }}</span>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-auto pt-6 flex gap-3">
                <a href="{{ route('catalog.index') }}"
                   class="px-5 py-2.5 rounded-xl border border-neutral-300 text-neutral-700 text-sm font-medium hover:bg-neutral-50 transition">
                    ← К каталогу
                </a>
                <a href="https://www.wildberries.ru/catalog/{{ $product->wb_id }}/detail.aspx"
                   target="_blank" rel="noopener"
                   class="px-5 py-2.5 rounded-xl bg-sky-700 hover:bg-sky-800 text-white text-sm font-medium transition">
                    Открыть на WB ↗
                </a>
            </div>
        </div>
    </div>
@endsection
