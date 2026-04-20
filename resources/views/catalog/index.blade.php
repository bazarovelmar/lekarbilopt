@extends('catalog.layout')

@section('title', 'Каталог товаров')

@section('content')
    <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">

        {{-- Sidebar категорий --}}
        <aside class="lg:sticky lg:top-20 lg:self-start">
            <div class="bg-white rounded-2xl border border-neutral-200 p-4">
                <h2 class="text-sm font-semibold text-neutral-500 uppercase tracking-wider mb-3">Категории</h2>
                <ul class="space-y-1">
                    <li>
                        <a href="{{ route('catalog.index', array_filter(['q' => $search])) }}"
                           class="flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium transition
                                  {{ ! $categoryId ? 'bg-sky-50 text-sky-800' : 'text-neutral-700 hover:bg-neutral-50' }}">
                            Все товары
                            <span class="text-xs text-neutral-400">{{ $totalCount }}</span>
                        </a>
                    </li>
                    @foreach($parentCategories as $cat)
                        @php
                            $isActive = $categoryId === $cat->id;
                            $params = array_filter([
                                'q' => $search,
                                'category' => $cat->id,
                            ]);
                        @endphp
                        <li>
                            <a href="{{ route('catalog.index', $params) }}"
                               class="flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium transition
                                      {{ $isActive ? 'bg-sky-50 text-sky-800' : 'text-neutral-700 hover:bg-neutral-50' }}">
                                <span class="truncate">{{ $cat->name ?: $cat->entity ?: 'Категория #'.$cat->wb_subject_id }}</span>
                                @if($isActive)
                                    <svg class="w-4 h-4 text-sky-700 shrink-0 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                @endif
                            </a>

                            {{-- подкатегории развёрнуты только у активной --}}
                            @if($isActive && $subcategories->isNotEmpty())
                                <ul class="mt-1 ml-3 pl-3 border-l border-sky-200 space-y-0.5">
                                    @foreach($subcategories as $sub)
                                        @php
                                            $subActive = $subcategoryId === $sub->id;
                                            $subParams = array_filter([
                                                'q' => $search,
                                                'category' => $cat->id,
                                                'subcategory' => $sub->id,
                                            ]);
                                        @endphp
                                        <li>
                                            <a href="{{ route('catalog.index', $subParams) }}"
                                               class="block px-3 py-1.5 rounded-md text-sm transition
                                                      {{ $subActive ? 'text-sky-800 font-medium' : 'text-neutral-600 hover:text-sky-700' }}">
                                                {{ $sub->name ?: $sub->entity ?: 'Подкатегория #'.$sub->wb_subject_id }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </aside>

        {{-- Основная область --}}
        <section>
            {{-- Хлебные крошки + сортировка --}}
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div class="flex flex-wrap items-center gap-2 text-sm text-neutral-500">
                    @if($search)
                        <span>Поиск: <b class="text-neutral-800">«{{ $search }}»</b></span>
                    @endif
                    @if($categoryId && $parentCategories->firstWhere('id', $categoryId))
                        <span class="px-2.5 py-1 rounded-full bg-sky-100 text-sky-800 text-xs font-medium flex items-center gap-1.5">
                            {{ $parentCategories->firstWhere('id', $categoryId)->name ?: 'Категория' }}
                            <a href="{{ route('catalog.index', array_filter(['q' => $search])) }}" class="hover:text-sky-900">✕</a>
                        </span>
                    @endif
                    @if($subcategoryId && $subcategories->firstWhere('id', $subcategoryId))
                        <span class="px-2.5 py-1 rounded-full bg-sky-100 text-sky-800 text-xs font-medium flex items-center gap-1.5">
                            {{ $subcategories->firstWhere('id', $subcategoryId)->name ?: 'Подкатегория' }}
                            <a href="{{ route('catalog.index', array_filter(['q' => $search, 'category' => $categoryId])) }}" class="hover:text-sky-900">✕</a>
                        </span>
                    @endif
                    <span class="text-neutral-400">·</span>
                    <span>Найдено: <b class="text-neutral-800">{{ $products->total() }}</b></span>
                </div>

                <form method="GET" action="{{ route('catalog.index') }}" class="flex items-center gap-2">
                    <input type="hidden" name="q" value="{{ $search }}">
                    @if($categoryId)<input type="hidden" name="category" value="{{ $categoryId }}">@endif
                    @if($subcategoryId)<input type="hidden" name="subcategory" value="{{ $subcategoryId }}">@endif
                    <label class="text-sm text-neutral-500">Сортировать:</label>
                    <select name="sort" onchange="this.form.submit()"
                            class="h-9 px-3 pr-8 rounded-lg bg-white border border-neutral-200 text-sm focus:border-sky-400 focus:ring-2 focus:ring-sky-100 outline-none">
                        <option value="newest" @selected($sort === 'newest')>Сначала новые</option>
                        <option value="title" @selected($sort === 'title')>По названию</option>
                        <option value="brand" @selected($sort === 'brand')>По бренду</option>
                    </select>
                </form>
            </div>

            {{-- Сетка карточек --}}
            @if($products->isEmpty())
                <div class="bg-white rounded-2xl border border-neutral-200 p-12 text-center">
                    <div class="text-5xl mb-3">🔍</div>
                    <h3 class="text-lg font-semibold text-neutral-800 mb-1">Ничего не найдено</h3>
                    <p class="text-neutral-500 text-sm">Попробуйте изменить запрос или сбросить фильтры.</p>
                    <a href="{{ route('catalog.index') }}" class="inline-block mt-4 px-5 py-2 rounded-lg bg-sky-700 hover:bg-sky-800 text-white text-sm font-medium transition">
                        Сбросить фильтры
                    </a>
                </div>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-4">
                    @foreach($products as $product)
                        <a href="{{ route('catalog.show', $product->id) }}"
                           class="group bg-white rounded-2xl border border-neutral-200 overflow-hidden hover:shadow-lg hover:border-sky-300 transition flex flex-col">
                            <div class="relative aspect-square bg-neutral-100 overflow-hidden">
                                <img src="{{ route('catalog.image', $product->id) }}"
                                     alt="{{ $product->title }}"
                                     loading="lazy"
                                     class="w-full h-full object-cover group-hover:scale-105 transition duration-300" />

                                @if($product->brand)
                                    <span class="absolute top-2 left-2 px-2 py-0.5 rounded-md bg-black/70 backdrop-blur text-white text-[11px] font-medium">
                                        {{ $product->brand }}
                                    </span>
                                @endif
                            </div>

                            <div class="p-3 flex-1 flex flex-col">
                                <h3 class="text-sm font-medium text-neutral-900 leading-snug line-clamp-2 group-hover:text-sky-700 transition">
                                    {{ $product->title ?: 'Без названия' }}
                                </h3>

                                @if($product->supplier)
                                    <p class="mt-1 text-xs text-neutral-500 truncate">{{ $product->supplier }}</p>
                                @endif

                                <div class="mt-auto pt-2 flex items-center justify-between">
                                    <span class="text-[11px] text-neutral-400">WB ID: {{ $product->wb_id }}</span>
                                    <span class="text-sky-700 text-xs font-semibold opacity-0 group-hover:opacity-100 transition">Открыть →</span>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>

                {{-- Пагинация --}}
                <div class="mt-8">
                    {{ $products->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
