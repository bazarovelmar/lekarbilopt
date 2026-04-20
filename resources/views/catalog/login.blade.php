<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в каталог · LBOpt</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gradient-to-br from-sky-50 via-neutral-50 to-cyan-50 text-neutral-900 antialiased flex items-center justify-center px-4">

<div class="w-full max-w-md">
    <div class="flex items-center justify-center gap-2 mb-6">
        <span class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-sky-700 text-white font-bold text-lg">LB</span>
        <span class="font-semibold text-xl tracking-tight text-sky-800">LBOpt<span class="text-neutral-400">/</span>Catalog</span>
    </div>

    <div class="bg-white rounded-2xl border border-neutral-200 shadow-sm p-7">
        <h1 class="text-xl font-semibold text-neutral-900 mb-1">Вход в каталог</h1>
        <p class="text-sm text-neutral-500 mb-6">Доступ к каталогу товаров закрыт. Введите логин и пароль.</p>

        <form method="POST" action="{{ route('catalog.login.submit') }}" class="space-y-4">
            @csrf

            <div>
                <label for="login" class="block text-sm font-medium text-neutral-700 mb-1.5">Логин</label>
                <input
                    id="login"
                    type="text"
                    name="login"
                    value="{{ old('login') }}"
                    autocomplete="username"
                    autofocus
                    required
                    class="w-full h-11 px-4 rounded-xl bg-neutral-50 border border-neutral-200 focus:bg-white focus:border-sky-400 focus:ring-2 focus:ring-sky-200 outline-none transition"
                />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-neutral-700 mb-1.5">Пароль</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                    class="w-full h-11 px-4 rounded-xl bg-neutral-50 border border-neutral-200 focus:bg-white focus:border-sky-400 focus:ring-2 focus:ring-sky-200 outline-none transition"
                />
            </div>

            @if ($errors->any())
                <div class="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3.5 py-2.5">
                    {{ $errors->first() }}
                </div>
            @endif

            <button type="submit"
                    class="w-full h-11 rounded-xl bg-sky-700 hover:bg-sky-800 text-white font-medium transition">
                Войти
            </button>
        </form>
    </div>

    <p class="mt-6 text-center text-xs text-neutral-400">
        © {{ date('Y') }} LBOpt
    </p>
</div>

</body>
</html>
