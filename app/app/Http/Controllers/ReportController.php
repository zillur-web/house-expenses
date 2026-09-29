<?php

namespace App\Http\Controllers;

use App\Exports\ReportExport;
use App\Models\ExpenseCategory;
use App\Models\Member;
use App\Models\MemberLedger;
use App\Models\Transaction;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public const TYPES = ['statement' => 'Member statement', 'balances' => 'Member balances', 'collection' => 'Collection report', 'expense' => 'Expense report', 'bazar' => 'Bazar report', 'bills' => 'Monthly bills', 'due' => 'Due report', 'advance' => 'Advance report', 'income' => 'Income vs expense', 'monthly' => 'Monthly summary', 'category' => 'Expense categories', 'cash' => 'Cash / account statement'];

    public function index(Request $r)
    {
        $r->validate(['report' => 'nullable|in:'.implode(',', array_keys(self::TYPES)), 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'month' => 'nullable|date_format:Y-m', 'year' => 'nullable|integer|between:2000,2100', 'member_id' => 'nullable|integer|exists:members,id', 'category_id' => 'nullable|integer|exists:expense_categories,id', 'format' => 'nullable|in:pdf,xlsx', 'payment_method' => 'nullable|in:Cash,Bank,bKash,Nagad,Rocket,Other']);
        $admin = $r->user()->hasRole('admin');
        $type = $r->query('report', 'statement');
        if (! $admin && ! in_array($type, ['statement', 'balances', 'collection', 'bills', 'due', 'advance', 'monthly'])) {
            abort(403);
        }$memberId = $admin ? $r->member_id : $r->user()->member->id;
        $from = $r->from;
        $to = $r->to;
        if ($r->year) {
            $from = $r->year.'-01-01';
            $to = $r->year.'-12-31';
        }if ($r->month) {
            $from = $r->month.'-01';
            $to = Carbon::parse($from)->endOfMonth()->toDateString();
        }
        $members = Member::withTrashed()->when($memberId, fn ($q) => $q->whereKey($memberId))->get();
        $q = Transaction::with(['member', 'category'])->when($from, fn ($q) => $q->where('date', '>=', $from))->when($to, fn ($q) => $q->where('date', '<=', $to))->when($r->category_id, fn ($q) => $q->where('expense_category_id', $r->category_id))->when($r->payment_method, fn ($q) => $q->where('payment_method', $r->payment_method));
        $rows = [];
        $columns = [];
        $money = fn ($v) => Money::format($v);
        if (in_array($type, ['balances', 'due', 'advance'])) {
            $columns = ['Member ID', 'Member', 'Balance BDT', 'Due BDT', 'Advance BDT'];
            foreach ($members as $m) {
                $b = $m->balance($to);
                if ($type === 'due' && $b >= 0 || $type === 'advance' && $b <= 0) {
                    continue;
                }$rows[] = [$m->code, $m->name, $money($b), $money(max(0, -$b)), $money(max(0, $b))];
            }
        } elseif ($type === 'statement') {
            $columns = ['Date', 'Member', 'Description', 'Reference', 'Debit BDT', 'Credit BDT', 'Balance BDT'];
            foreach ($members as $m) {
                $b = $from ? $m->balance(Carbon::parse($from)->subDay()->toDateString()) : 0;
                if ($from) {
                    $rows[] = [$from, $m->name, 'Opening balance', '', 0, 0, $money($b)];
                }$entries = $m->ledger()->with('transaction')->whereHas('transaction')->when($from, fn ($q) => $q->where('date', '>=', $from))->when($to, fn ($q) => $q->where('date', '<=', $to))->orderBy('date')->orderBy('id')->get();
                foreach ($entries as $e) {
                    $b += $e->credit - $e->debit;
                    if ($r->payment_method && $e->transaction->payment_method !== $r->payment_method) {
                        continue;
                    }
                    if ($r->category_id && $e->transaction->expense_category_id != $r->category_id) {
                        continue;
                    }
                    $rows[] = [$e->date, $m->name, $e->description, $e->transaction->reference, $money($e->debit), $money($e->credit), $money($b)];
                }
            }
        } elseif ($type === 'bills') {
            $columns = ['Month', 'Member', 'Previous due BDT', 'New charges BDT', 'Expense share BDT', 'Paid BDT', 'Due BDT', 'Advance BDT'];
            $bills = (clone $q)->where('type', 'bill')->when($memberId, fn ($q) => $q->where('member_id', $memberId))->orderBy('date')->get();
            foreach ($bills as $b) {
                $start = substr($b->date, 0, 7).'-01';
                $end = Carbon::parse($start)->endOfMonth()->toDateString();
                $m = $b->member;
                $prev = $m->balance(Carbon::parse($start)->subDay()->toDateString());
                $share = $m->ledger()->whereBetween('date', [$start, $end])->whereHas('transaction', fn ($q) => $q->whereIn('type', ['expense', 'bazar']))->sum('debit');
                $paid = $m->transactions()->where('type', 'payment')->whereBetween('date', [$start, $end])->sum('amount');
                $balance = $m->balance($end);
                $rows[] = [substr($start, 0, 7), $m->name, $money(max(0, -$prev)), $money($b->amount), $money($share), $money($paid), $money(max(0, -$balance)), $money(max(0, $balance))];
            }
        } elseif (in_array($type, ['income', 'monthly', 'category'])) {
            $columns = [$type === 'category' ? 'Category' : 'Month', 'Collection BDT', 'Expense BDT', 'Net BDT'];
            $data = (clone $q)->when($memberId, fn ($q) => $q->whereHas('ledger', fn ($q) => $q->where('member_id', $memberId)))->get();
            $groups = $data->groupBy(fn ($t) => $type === 'category' ? ($t->category?->name ?? 'Uncategorized') : substr($t->date, 0, 7));
            foreach ($groups as $label => $group) {
                $income = $group->where('type', 'payment')->sum('amount');
                $expense = $group->whereIn('type', ['expense', 'bazar'])->sum('amount');
                if ($memberId) {
                    $expense = MemberLedger::where('member_id', $memberId)->whereIn('transaction_id', $group->pluck('id'))->sum('debit');
                }$rows[] = [$label, $money($income), $money($expense), $money($income - $expense)];
            }
        } elseif ($type === 'cash') {
            $columns = ['Date', 'Reference', 'Description', 'Method', 'In BDT', 'Out BDT', 'Balance BDT'];
            $cashQuery = Transaction::whereIn('type', ['payment', 'cash_opening', 'expense', 'bazar'])
                ->when($r->payment_method, fn ($q) => $q->where('payment_method', $r->payment_method))
                ->when($r->category_id, fn ($q) => $q->where('expense_category_id', $r->category_id))
                ->when($memberId, fn ($q) => $q->where('member_id', $memberId));
            $net = fn ($t) => in_array($t->type, ['payment', 'cash_opening']) ? $t->amount : -$t->amount;
            $balance = $from ? (clone $cashQuery)->where('date', '<', $from)->get()->sum($net) : 0;
            if ($from) {
                $rows[] = [$from, '', 'Opening balance', '', 0, 0, $money($balance)];
            }
            foreach ($cashQuery->when($from, fn ($q) => $q->where('date', '>=', $from))->when($to, fn ($q) => $q->where('date', '<=', $to))->orderBy('date')->orderBy('id')->get() as $t) {
                $change = $net($t);
                $balance += $change;
                $rows[] = [$t->date, $t->reference, $t->description, $t->payment_method, $money(max(0, $change)), $money(max(0, -$change)), $money($balance)];
            }
        } else {
            $types = match ($type) {
                'collection' => ['payment'],'expense' => ['expense', 'bazar'],'bazar' => ['bazar'],default => ['payment', 'cash_opening', 'expense', 'bazar']
            };
            $columns = ['Date', 'Reference', 'Member / Category', 'Description', 'Method', 'In BDT', 'Out BDT'];
            $data = (clone $q)->whereIn('type', $types)->when($memberId, fn ($q) => $q->where('member_id', $memberId))->orderBy('date')->orderBy('id')->get();
            foreach ($data as $t) {
                $rows[] = [$t->date, $t->reference, $t->member?->name ?? $t->category?->name, $t->description, $t->payment_method, $money(in_array($t->type, ['payment', 'cash_opening']) ? $t->amount : 0), $money(! in_array($t->type, ['payment', 'cash_opening']) ? $t->amount : 0)];
            }
        }
        $title = self::TYPES[$type];
        if ($r->format === 'xlsx') {
            return Excel::download(new ReportExport($columns, $rows), $type.'.xlsx');
        }if ($r->format === 'pdf') {
            return Pdf::loadView('report-pdf', compact('title', 'columns', 'rows'))->setPaper('a4', 'landscape')->download($type.'.pdf');
        }

        return view('reports', ['title' => $title, 'columns' => $columns, 'rows' => $rows, 'types' => self::TYPES, 'type' => $type, 'members' => $admin ? Member::all() : collect(), 'categories' => ExpenseCategory::all()]);
    }
}
