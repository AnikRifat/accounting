<?php

namespace App\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;

class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function authenticate(): Redirector|RedirectResponse|null
    {
        $this->email = strtolower(trim($this->email));
        $data = $this->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:255']]);
        $key = 'admin-login:'.hash('sha256', $this->email.'|'.request()->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return null;
        }
        if (! Auth::attempt([...$data, 'is_active' => true], $this->remember) || ! Gate::allows('admin.access')) {
            Auth::logout();
            RateLimiter::hit($key, 60);
            $this->addError('email', 'The provided credentials are incorrect or admin access is unavailable.');

            return null;
        }
        RateLimiter::clear($key);
        session()->regenerate();
        $this->reset('password');

        return redirect()->intended(route('admin.dashboard'));
    }

    public function render(): View
    {
        return view('livewire.auth.login')->layout('layouts.guest');
    }
}
