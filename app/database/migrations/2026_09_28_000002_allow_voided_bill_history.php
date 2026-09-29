<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $t) {
            $t->index('member_id', 'bills_member_lookup');
            $t->dropUnique(['member_id', 'month']);
            $t->index(['member_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $t) {
            $t->dropIndex(['member_id', 'month']);
            $t->unique(['member_id', 'month']);
            $t->dropIndex('bills_member_lookup');
        });
    }
};
