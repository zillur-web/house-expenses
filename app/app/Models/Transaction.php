<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['details' => 'array', 'amount' => 'integer'];

    public function member()
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function ledger()
    {
        return $this->hasMany(MemberLedger::class);
    }

    public function items()
    {
        return $this->hasMany(BazarItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
