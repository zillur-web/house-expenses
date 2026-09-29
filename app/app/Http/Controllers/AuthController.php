<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($data)) {
            return back()->withErrors(['email' => 'The email or password is incorrect.'])->onlyInput('email');
        }$r->session()->regenerate();

        return redirect()->intended('/');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }

    public function password(Request $r)
    {
        $d = $r->validate(['current_password' => 'required|current_password', 'password' => 'required|confirmed|min:12']);
        $r->user()->update(['password' => Hash::make($d['password'])]);
        $r->session()->regenerate();

        return back()->with('success', 'Password changed.');
    }
}
