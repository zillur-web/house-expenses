<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('phone')->nullable();
            $t->text('address')->nullable();
            $t->date('join_date');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('monthly_closings', function (Blueprint $t) {
            $t->id();
            $t->string('month', 7)->unique();
            $t->boolean('closed')->default(false);
            $t->json('summary')->nullable();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->timestamps();
        });
        Schema::create('transactions', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->string('type')->index();
            $t->date('date')->index();
            $t->foreignId('member_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('expense_category_id')->nullable()->constrained()->restrictOnDelete();
            $t->bigInteger('amount');
            $t->string('payment_method')->default('Cash');
            $t->string('external_reference')->nullable();
            $t->text('description');
            $t->string('attachment')->nullable();
            $t->json('details')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->string('import_key')->nullable()->unique();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('member_ledgers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->restrictOnDelete();
            $t->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $t->date('date')->index();
            $t->bigInteger('debit')->default(0);
            $t->bigInteger('credit')->default(0);
            $t->string('description');
            $t->timestamps();
            $t->unique(['member_id', 'transaction_id']);
        });
        Schema::create('bazar_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $t->string('item');
            $t->decimal('quantity', 12, 3);
            $t->bigInteger('unit_price');
            $t->bigInteger('total');
            $t->timestamps();
        });
        Schema::create('expense_distributions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $t->foreignId('member_id')->constrained()->restrictOnDelete();
            $t->bigInteger('amount');
            $t->unique(['transaction_id', 'member_id']);
        });
        Schema::create('bills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->restrictOnDelete();
            $t->foreignId('transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->string('month', 7);
            $t->unique(['member_id', 'month']);
            $t->timestamps();
        });
        Schema::create('bill_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bill_id')->constrained()->restrictOnDelete();
            $t->string('description');
            $t->bigInteger('amount');
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('action');
            $t->string('entity');
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('ip_address')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('imports', function (Blueprint $t) {
            $t->id();
            $t->string('filename');
            $t->string('hash')->unique();
            $t->json('rows');
            $t->json('errors');
            $t->string('status')->default('preview');
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['imports', 'audit_logs', 'bill_items', 'bills', 'expense_distributions', 'bazar_items', 'member_ledgers', 'transactions', 'monthly_closings', 'expense_categories', 'members'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
