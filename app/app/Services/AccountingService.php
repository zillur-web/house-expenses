<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberLedger;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountingService
{
    public function audit(string $action, string $entity, $id, $old = null, $new = null): void
    {
        DB::table('audit_logs')->insert(['user_id' => auth()->id(), 'action' => $action, 'entity' => $entity, 'entity_id' => $id, 'old_values' => $old ? json_encode($old) : null, 'new_values' => $new ? json_encode($new) : null, 'ip_address' => request()->ip(), 'created_at' => now()]);
    }

    public function open(string $date): void
    {
        DB::table('accounting_locks')->where('id', 1)->lockForUpdate()->first();
        $month = substr($date, 0, 7);
        DB::table('monthly_closings')->insertOrIgnore(['month' => $month, 'closed' => false, 'created_at' => now(), 'updated_at' => now()]);
        $period = DB::table('monthly_closings')->where('month', $month)->lockForUpdate()->first();
        if ($period->closed || DB::table('monthly_closings')->where('closed', true)->where('month', '>', $month)->exists()) {
            throw ValidationException::withMessages(['date' => 'This month or a later month is closed. Reopen affected closed months before changing earlier transactions.']);
        }
    }

    public function post(array $data): Transaction
    {
        return DB::transaction(function () use ($data) {
            $this->open($data['date']);
            if (! empty($data['submission_key'])) {
                $existing = Transaction::withTrashed()->where('submission_key', $data['submission_key'])->first();
                if ($existing) {
                    if ($existing->trashed()) {
                        throw ValidationException::withMessages(['description' => 'This submitted entry was voided. Start a new entry.']);
                    }

                    return $existing;
                }
            }
            $type = $data['type'];
            $amount = Money::parse($data['amount'] ?? 0);
            $items = [];
            if ($type === 'bazar') {
                $amount = 0;
                foreach ($data['items'] as $item) {
                    $price = Money::parse($item['unit_price']);
                    $total = (int) bcadd(bcmul((string) $item['quantity'], (string) $price, 3), '0.5', 0);
                    $items[] = ['item' => $item['item'], 'quantity' => $item['quantity'], 'unit_price' => $price, 'total' => $total];
                    $amount += $total;
                }
            }
            if ($type === 'bill') {
                $amount = Money::parse($data['monthly_charge'] ?? 0) + Money::parse($data['other_charge'] ?? 0) - Money::parse($data['discount'] ?? 0);
            }
            if (! in_array($type, ['opening', 'adjustment', 'cash_opening']) && $amount < 0) {
                throw ValidationException::withMessages(['amount' => 'Amount cannot be negative.']);
            }
            if (! empty($data['member_id'])) {
                Member::whereKey($data['member_id'])->where('active', true)->firstOrFail();
            }
            $t = Transaction::create(['reference' => (string) Str::uuid(), 'type' => $type, 'date' => $data['date'], 'member_id' => $data['member_id'] ?? null, 'expense_category_id' => $data['expense_category_id'] ?? null, 'amount' => $amount, 'payment_method' => $data['payment_method'] ?? 'Cash', 'external_reference' => $data['external_reference'] ?? null, 'description' => $data['description'], 'attachment' => $data['attachment'] ?? null, 'details' => collect($data)->only(['shop', 'paid_by', 'direction', 'sharing', 'note'])->all(), 'created_by' => auth()->id() ?? $data['created_by'], 'import_key' => $data['import_key'] ?? null, 'submission_key' => $data['submission_key'] ?? null]);
            if (in_array($type, ['payment', 'opening', 'adjustment', 'bill'])) {
                $credit = in_array($type, ['payment', 'opening']) || ($type === 'adjustment' && ($data['direction'] ?? 'credit') === 'credit');
                $this->entry($t, $t->member_id, $credit ? -$amount : $amount);
            }
            foreach ($items as $item) {
                $t->items()->create($item);
            }
            if (in_array($type, ['expense', 'bazar']) && ($data['sharing'] ?? 'none') !== 'none') {
                $mode = $data['sharing'];
                $selected = $data['shares'] ?? [];
                $selected = array_filter($selected, fn ($v) => $v !== null && $v !== '' && (float) $v > 0);
                if ($mode === 'equal') {
                    $selected = Member::where('active', true)->pluck('id')->mapWithKeys(fn ($id) => [$id => 1])->all();
                }if (! $selected) {
                    throw ValidationException::withMessages(['shares' => 'Choose members to share this expense.']);
                }$valid = Member::where('active', true)->whereIn('id', array_keys($selected))->count();
                if ($valid !== count($selected)) {
                    throw ValidationException::withMessages(['shares' => 'Invalid or inactive member.']);
                }if ($mode === 'percentage' && abs(array_sum($selected) - 100) > 0.00001) {
                    throw ValidationException::withMessages(['shares' => 'Percentages must total 100.']);
                }$shares = $mode === 'custom' ? array_map(fn ($v) => Money::parse($v), $selected) : Money::split($amount, $mode === 'selected' ? array_fill_keys(array_keys($selected), 1) : $selected);
                if (array_sum($shares) !== $amount) {
                    throw ValidationException::withMessages(['shares' => 'Shares must exactly equal the expense.']);
                }foreach ($shares as $id => $share) {
                    DB::table('expense_distributions')->insert(['transaction_id' => $t->id, 'member_id' => $id, 'amount' => $share]);
                    $this->entry($t, $id, $share);
                }
            }
            if ($type === 'bill') {
                $month = substr($data['date'], 0, 7);
                if (DB::table('bills')->join('transactions', 'transactions.id', '=', 'bills.transaction_id')->whereNull('transactions.deleted_at')->where('bills.member_id', $t->member_id)->where('month', $month)->exists()) {
                    throw ValidationException::withMessages(['date' => 'This member already has a bill for this month.']);
                }$bill = DB::table('bills')->insertGetId(['member_id' => $t->member_id, 'transaction_id' => $t->id, 'month' => $month, 'created_at' => now(), 'updated_at' => now()]);
                foreach (['monthly_charge' => 'Monthly charge', 'other_charge' => 'Other charge', 'discount' => 'Discount'] as $key => $label) {
                    DB::table('bill_items')->insert(['bill_id' => $bill, 'description' => $label, 'amount' => Money::parse($data[$key] ?? 0) * ($key === 'discount' ? -1 : 1)]);
                }
            }
            $this->audit('created', 'transaction', $t->id, null, $t->toArray());

            return $t;
        });
    }

    private function entry(Transaction $t, int $member, int $debit): void
    {
        MemberLedger::create(['member_id' => $member, 'transaction_id' => $t->id, 'date' => $t->date, 'debit' => max(0, $debit), 'credit' => max(0, -$debit), 'description' => mb_substr($t->description, 0, 255)]);
    }

    public function void(Transaction $t, string $reason): void
    {
        DB::transaction(function () use ($t, $reason) {
            $this->open($t->date);
            $t = Transaction::whereKey($t->id)->lockForUpdate()->firstOrFail();
            $old = $t->toArray();
            $t->delete();
            $this->audit('voided', 'transaction', $t->id, $old, ['reason' => $reason]);
        });
    }

    public function close(string $month, bool $closed, string $reason): void
    {
        DB::transaction(function () use ($month, $closed, $reason) {
            DB::table('accounting_locks')->where('id', 1)->lockForUpdate()->first();
            DB::table('monthly_closings')->insertOrIgnore(['month' => $month, 'closed' => false, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('monthly_closings')->where('month', $month)->lockForUpdate()->first();
            $end = Carbon::parse($month.'-01')->endOfMonth()->toDateString();
            $summary = ['collection' => Transaction::where('type', 'payment')->where('date', 'like', $month.'%')->sum('amount'), 'expenses' => Transaction::whereIn('type', ['expense', 'bazar'])->where('date', 'like', $month.'%')->sum('amount'), 'balances' => Member::all()->mapWithKeys(fn ($m) => [$m->id => $m->balance($end)])->all()];
            DB::table('monthly_closings')->where('id', $row->id)->update(['closed' => $closed, 'summary' => json_encode($summary), 'user_id' => auth()->id(), 'updated_at' => now()]);
            $this->audit($closed ? 'closed' : 'reopened', 'month', $row->id, (array) $row, ['summary' => $summary, 'reason' => $reason]);
        });
    }
}
