<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Каталог товаров')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-900 antialiased">

<header class="sticky top-0 z-30 bg-white border-b border-neutral-200 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center gap-4">
        <a href="{{ route('catalog.index') }}" class="flex items-center gap-2 shrink-0">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-sky-700 text-white font-bold">LB</span>
            <span class="hidden sm:inline font-semibold text-lg tracking-tight text-sky-800">LBOpt<span class="text-neutral-400">/</span>Catalog</span>
        </a>

        <form action="{{ route('catalog.index') }}" method="GET" class="flex-1 flex items-center">
            <div class="relative w-full">
                <input
                    type="text"
                    name="q"
                    value="{{ $search ?? '' }}"
                    placeholder="Поиск по товарам, бренду, поставщику…"
                    class="w-full h-11 pl-11 pr-4 rounded-xl bg-neutral-100 border border-transparent focus:bg-white focus:border-sky-400 focus:ring-2 focus:ring-sky-200 outline-none transition"
                />
                <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-5 h-5 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                @if(!empty($categoryId))
                    <input type="hidden" name="category" value="{{ $categoryId }}">
                @endif
            </div>
            <button type="submit" class="ml-2 h-11 px-5 rounded-xl bg-sky-700 hover:bg-sky-800 text-white font-medium transition">
                Найти
            </button>
        </form>

        <span class="hidden md:inline text-sm text-neutral-500 whitespace-nowrap">
            Всего товаров: <span class="font-semibold text-neutral-800">{{ $totalCount ?? 0 }}</span>
        </span>

        <form method="POST" action="{{ route('catalog.logout') }}" class="shrink-0">
            @csrf
            <button type="submit"
                    class="h-11 px-4 rounded-xl border border-neutral-200 text-sm text-neutral-600 hover:text-sky-700 hover:border-sky-300 transition"
                    title="Выйти">
                <span class="hidden sm:inline">Выйти</span>
                <svg class="sm:hidden w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
            </button>
        </form>
    </div>
</header>

<main class="max-w-7xl mx-auto px-4 py-6">
    @yield('content')
</main>

<footer class="mt-12 border-t border-neutral-200 bg-white">
    <div class="max-w-7xl mx-auto px-4 py-6 text-sm text-neutral-500 flex justify-between">
        <span>© {{ date('Y') }} LBOpt · каталог товаров</span>
    </div>
</footer>

</body>
</html>
