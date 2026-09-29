<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(Request $r)
    {
        $members = Member::with('user')->when($r->q, fn ($q) => $q->where('name', 'like', '%'.$r->q.'%'))->orderBy('name')->paginate(15)->withQueryString();

        return view('members', compact('members'));
    }

    public function save(Request $r, AccountingService $a, ?Member $member = null)
    {
        $data = $r->validate(['name' => 'required|string|max:255', 'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($member?->user_id)], 'phone' => 'nullable|string|max:30', 'address' => 'nullable|string|max:1000', 'join_date' => 'required|date_format:Y-m-d', 'password' => ($member ? 'nullable' : 'required').'|string|min:12', 'active' => 'required|boolean', 'opening' => 'nullable|decimal:0,2']);
        DB::transaction(function () use ($data, $member, $a) {
            $old = $member?->toArray();
            $u = $member?->user ?? new User;
            $u->fill(['name' => $data['name'], 'email' => $data['email']]);
            if (! empty($data['password'])) {
                $u->password = Hash::make($data['password']);
            }$u->save();
            $u->assignRole('member');
            $m = $member ?? new Member(['code' => 'MB-'.strtoupper(Str::random(8)), 'user_id' => $u->id]);
            $m->fill(collect($data)->only(['name', 'phone', 'address', 'join_date', 'active'])->all())->save();
            if (! $member && ! empty($data['opening'])) {
                $a->post(['type' => 'opening', 'date' => $data['join_date'], 'member_id' => $m->id, 'amount' => $data['opening'], 'description' => 'Opening balance']);
            }$a->audit($member ? 'updated' : 'created', 'member', $m->id, $old, $m->toArray());
        });

        return redirect('/members')->with('success', 'Member saved.');
    }

    public function delete(Member $member, AccountingService $a)
    {
        DB::transaction(function () use ($member, $a) {
            $old = $member->toArray();
            $member->update(['active' => false]);
            $member->delete();
            $a->audit('deleted', 'member', $member->id, $old);
        });

        return back()->with('success', 'Member archived. Financial history retained.');
    }

    public function edit(Member $member)
    {
        return view('member-form',compact('member'));
    }
}
