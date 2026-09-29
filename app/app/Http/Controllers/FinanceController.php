<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionRequest;
use App\Models\ExpenseCategory;
use App\Models\Member;
use App\Models\Transaction;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FinanceController extends Controller
{
    public function dashboard(Request $r)
    {
        $admin = $r->user()->hasRole('admin');
        $member = $r->user()->member;
        $members = $admin ? Member::all() : collect([$member]);
        $balances = $members->map(fn ($m) => ['member' => $m, 'balance' => $m->balance()]);
        $query = Transaction::query();
        if (! $admin) {
            $query->whereHas('ledger', fn ($q) => $q->where('member_id', $member->id));
        }$recent = (clone $query)->with('member')->latest('date')->latest('id')->limit(8)->get();
        $collection = $admin ? Transaction::where('type', 'payment')->sum('amount') : $member->transactions()->where('type', 'payment')->sum('amount');
        $expense = $admin ? Transaction::whereIn('type', ['expense', 'bazar'])->sum('amount') : $member->ledger()->whereHas('transaction', fn ($q) => $q->whereIn('type', ['expense', 'bazar']))->sum('debit');
        $cash = Transaction::whereIn('type', ['payment', 'cash_opening'])->sum('amount') - Transaction::whereIn('type', ['expense', 'bazar'])->sum('amount');
        $months = collect(range(5, 0))->map(function ($i) use ($admin, $member) {
            $month = now()->subMonths($i)->format('Y-m');
            $q = Transaction::where('date', 'like', $month.'%');
            if (! $admin) {
                $q->where('member_id', $member->id);
            }

            return ['month' => $month, 'collection' => (clone $q)->where('type', 'payment')->sum('amount'), 'expense' => $admin ? Transaction::where('date', 'like', $month.'%')->whereIn('type', ['expense', 'bazar'])->sum('amount') : $member->ledger()->where('date', 'like', $month.'%')->whereHas('transaction')->sum('debit')];
        });

        $thisMonth = now()->format('Y-m');
        $monthCollection = $months->last()['collection'];
        $monthExpense = $months->last()['expense'];
        $bazarTotal = $admin ? Transaction::where('type', 'bazar')->sum('amount') : $member->ledger()->whereHas('transaction', fn ($q) => $q->where('type', 'bazar'))->sum('debit');
        $billTotal = (clone $query)->where('type', 'bill')->sum('amount');
        $previousDue = $admin ? 0 : max(0, -$member->balance(now()->startOfMonth()->subDay()->toDateString()));
        $categoryTotals = $admin ? Transaction::with('category')->whereIn('type', ['expense', 'bazar'])->get()->groupBy(fn ($t) => $t->category?->name ?? 'Other')->map(fn ($group) => $group->sum('amount'))->sortDesc() : collect();

        return view('dashboard', compact('admin', 'member', 'members', 'balances', 'recent', 'collection', 'expense', 'cash', 'months', 'monthCollection', 'monthExpense', 'bazarTotal', 'billTotal', 'previousDue', 'categoryTotals'));
    }

    public function transactions(Request $r)
    {
        $type = $r->query('type');
        $q = Transaction::with(['member', 'category'])->when($type, fn ($q) => $q->where('type', $type))->when($r->q, fn ($q) => $q->where('description', 'like', '%'.$r->q.'%'))->when($r->from, fn ($q) => $q->where('date', '>=', $r->from))->when($r->to, fn ($q) => $q->where('date', '<=', $r->to));
        if (! $r->user()->hasRole('admin')) {
            $q->whereHas('ledger', fn ($q) => $q->where('member_id', $r->user()->member->id));
        }$transactions = $q->latest('date')->latest('id')->paginate(20)->withQueryString();

        return view('transactions', compact('transactions', 'type'));
    }

    public function create(Request $r)
    {
        $type = $r->query('type', 'payment');
        abort_unless(in_array($type, ['payment', 'expense', 'bazar', 'bill', 'adjustment', 'opening', 'cash_opening']), 404);

        return view('transaction-form', ['type' => $type, 'members' => Member::where('active', true)->orderBy('name')->get(), 'categories' => ExpenseCategory::orderBy('name')->get()]);
    }

    public function edit(Request $r, Transaction $transaction)
    {
        $input = ['type' => $transaction->type, 'date' => $transaction->date, 'member_id' => $transaction->member_id, 'expense_category_id' => $transaction->expense_category_id, 'amount' => number_format($transaction->amount / 100, 2, '.', ''), 'description' => $transaction->description, 'payment_method' => $transaction->payment_method, 'external_reference' => $transaction->external_reference] + ($transaction->details ?? []);
        $input['items'] = $transaction->items->map(fn ($i) => ['item' => $i->item, 'quantity' => $i->quantity, 'unit_price' => number_format($i->unit_price / 100, 2, '.', '')])->all();
        $input['sharing'] = 'custom';
        $input['shares'] = DB::table('expense_distributions')->where('transaction_id', $transaction->id)->get()->mapWithKeys(fn ($d) => [$d->member_id => number_format($d->amount / 100, 2, '.', '')])->all();
        if (! $input['shares']) {
            $input['sharing'] = 'none';
        }if ($transaction->type === 'bill') {
            $bill = DB::table('bills')->where('transaction_id', $transaction->id)->first();
            foreach (DB::table('bill_items')->where('bill_id', $bill->id)->get() as $item) {
                $key = match ($item->description) {
                    'Monthly charge' => 'monthly_charge','Other charge' => 'other_charge',default => 'discount'
                };
                $input[$key] = number_format(abs($item->amount) / 100, 2, '.', '');
            }
        }$r->session()->flashInput($input);

        return view('transaction-form', ['type' => $transaction->type, 'editing' => $transaction, 'members' => Member::where('active', true)->get(), 'categories' => ExpenseCategory::all()]);
    }

    public function update(TransactionRequest $r, Transaction $transaction, AccountingService $a)
    {
        $r->validate(['correction_reason' => 'required|string|min:5|max:1000']);
        $data = $r->validated();
        $data['attachment'] = $transaction->attachment;
        $path = null;
        if ($r->hasFile('attachment')) {
            $data['attachment'] = $path = $r->file('attachment')->store('vouchers', 'local');
        }try {
            DB::transaction(function () use ($a, $transaction, $data, $r) {
                $a->void($transaction, $r->correction_reason);
                $new = $a->post($data);
                $a->audit('corrected', 'transaction', $transaction->id, ['reference' => $transaction->reference], ['replacement' => $new->reference, 'reason' => $r->correction_reason]);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }

        return redirect('/transactions?type='.$transaction->type)->with('success', 'Correction saved; previous entry retained in audit history.');
    }

    public function store(TransactionRequest $r, AccountingService $a)
    {
        $data = $r->validated();
        $path = null;
        if ($r->hasFile('attachment')) {
            $data['attachment'] = $path = $r->file('attachment')->store('vouchers', 'local');
        }try {
            $t = $a->post($data);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }

        return redirect('/transactions?type='.$t->type)->with('success', 'Transaction recorded.');
    }

    public function void(Request $r, Transaction $transaction, AccountingService $a)
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:1000']);
        $a->void($transaction, $d['reason']);

        return back()->with('success', 'Transaction voided.');
    }

    public function attachment(Request $r, Transaction $transaction)
    {
        abort_unless($r->user()->hasRole('admin'), 403);
        abort_unless($transaction->attachment, 404);

        return Storage::disk('local')->download($transaction->attachment);
    }

    public function categories()
    {
        return view('categories', ['categories' => ExpenseCategory::orderBy('name')->get()]);
    }

    public function category(Request $r, AccountingService $a)
    {
        $d = $r->validate(['name' => 'required|string|max:100|unique:expense_categories']);
        $c = ExpenseCategory::create($d);
        $a->audit('created', 'category', $c->id, null, $d);

        return back()->with('success', 'Category created.');
    }

    public function closing()
    {
        return view('closing', ['periods' => DB::table('monthly_closings')->orderByDesc('month')->get()]);
    }

    public function close(Request $r, AccountingService $a)
    {
        $d = $r->validate(['month' => 'required|date_format:Y-m', 'closed' => 'required|boolean', 'reason' => 'required|min:5|max:1000']);
        $a->close($d['month'], (bool) $d['closed'], $d['reason']);

        return back()->with('success', 'Month status updated.');
    }

    public function audit()
    {
        return view('audit', ['logs' => DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->select('audit_logs.*', 'users.name')->orderByDesc('audit_logs.id')->paginate(25)]);
    }
}
