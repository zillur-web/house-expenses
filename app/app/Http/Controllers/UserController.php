<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index()
    {
        return view('users', ['users' => User::with('roles', 'member')->orderBy('name')->get()]);
    }

    public function store(Request $r, AccountingService $a)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'email' => 'required|email|unique:users', 'password' => 'required|min:12']);
        DB::transaction(function () use ($d, $a) {
            $u = User::create(['name' => $d['name'], 'email' => $d['email'], 'password' => Hash::make($d['password'])]);
            $u->assignRole('admin');
            $a->audit('created', 'administrator', $u->id, null, ['name' => $u->name, 'email' => $u->email]);
        });

        return back()->with('success', 'Administrator created.');
    }

    public function role(Request $r, User $user, AccountingService $a)
    {
        $d = $r->validate(['role' => 'required|in:admin,member']);
        DB::transaction(function () use ($d, $user, $a) {
            $admins = User::role('admin')->lockForUpdate()->get();
            if ($d['role'] === 'member' && (! $user->member || ! $user->member->active)) {
                throw ValidationException::withMessages(['role' => 'Only an active member account can be assigned the member role.']);
            }if ($d['role'] === 'member' && $user->hasRole('admin') && $admins->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'Keep at least one administrator.']);
            }$old = $user->getRoleNames()->all();
            $user->syncRoles([$d['role']]);
            $a->audit('permissions_changed', 'user', $user->id, ['roles' => $old], ['role' => $d['role']]);
        });

        return back()->with('success','Role updated.');
    }
}
