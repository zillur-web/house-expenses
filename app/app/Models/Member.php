<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ledger()
    {
        return $this->hasMany(MemberLedger::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function balance(?string $through = null): int
    {
        return (int) $this->ledger()->whereHas('transaction')->when($through, fn ($q) => $q->where('date', '<=', $through))->selectRaw('COALESCE(SUM(credit-debit),0) AS balance')->value('balance');
    }
}
