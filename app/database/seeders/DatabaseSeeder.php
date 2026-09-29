<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (['admin', 'member'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        foreach (['Bazar', 'Electricity', 'Gas', 'Water', 'Internet', 'Rent', 'Maintenance', 'Cook', 'Cleaning', 'Transport', 'Other'] as $name) {
            ExpenseCategory::firstOrCreate(['name' => $name]);
        }
    }
}
