<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class CatalogAuthController extends Controller
{
    private const LOGIN = 'lekarbilopt';
    private const PASSWORD = 'lek%l_pt1234saf';

    public function showLogin(Request $request)
    {
        if ($request->session()->get('catalog_auth') === true) {
            return redirect()->route('catalog.index');
        }

        return view('catalog.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $key = 'catalog-login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'login' => "Слишком много попыток. Попробуйте через {$seconds} сек.",
            ]);
        }

        $loginOk = hash_equals(self::LOGIN, (string) $request->input('login'));
        $passwordOk = hash_equals(self::PASSWORD, (string) $request->input('password'));

        if (! $loginOk || ! $passwordOk) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([
                'login' => 'Неверный логин или пароль.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('catalog_auth', true);

        $intended = $request->session()->pull('intended');

        return redirect($intended ?: route('catalog.index'));
    }

    public function logout(Request $request)
    {
        $request->session()->forget('catalog_auth');
        $request->session()->regenerate();

        return redirect()->route('catalog.login');
    }
}
