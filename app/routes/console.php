<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

Artisan::command('bazar:admin {email} {--name=Administrator}', function () {
    $password = $this->secret('Choose an administrator password (12+ characters)');
    if (strlen($password ?? '') < 12) {
        $this->error('Password must contain at least 12 characters.');

        return 1;
    }
    if (User::where('email', $this->argument('email'))->exists()) {
        $this->error('Email already exists.');

        return 1;
    }
    $user = User::create(['name' => $this->option('name'), 'email' => $this->argument('email'), 'password' => Hash::make($password)]);
    $user->assignRole(Role::findOrCreate('admin', 'web'));
    $this->info('Administrator created.');
})->purpose('Create a household administrator securely');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
