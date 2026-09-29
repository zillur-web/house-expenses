<?php

namespace App\Services;

use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MemberImportService
{
    public function preview($sheet): array
    {
        $data = $sheet->toArray(null, true, false, false);
        $headers = array_map(fn ($v) => strtolower(trim((string) $v)), array_shift($data) ?? []);
        $errors = [];
        $rows = [];
        foreach (['name', 'email', 'join_date', 'opening_balance'] as $key) {
            if (! in_array($key, $headers)) {
                $errors[] = 'Missing column: '.$key;
            }
        }if ($errors) {
            return compact('rows', 'errors');
        }foreach ($data as $i => $cells) {
            if (! array_filter($cells, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }$row = array_combine($headers, array_pad(array_slice($cells, 0, count($headers)), count($headers), null));
            $row['row'] = $i + 2;
            $validation = Validator::make($row, ['name' => 'required|string|max:255', 'email' => 'required|email|unique:users,email', 'join_date' => 'required|date_format:Y-m-d', 'opening_balance' => 'required|decimal:0,2|between:-999999999,999999999', 'phone' => 'nullable|string|max:30', 'address' => 'nullable|string|max:1000']);
            $row['errors'] = $validation->errors()->all();
            if (in_array(strtolower($row['email'] ?? ''), array_map('strtolower', array_column($rows, 'email')))) {
                $row['errors'][] = 'Duplicate email in workbook.';
            }if ($row['errors']) {
                $errors[] = 'Row '.$row['row'].': '.implode(' ', $row['errors']);
            }$rows[] = $row;
        }

return compact('rows', 'errors');
    }

    public function import(array $rows, AccountingService $a): void
    {
        foreach ($rows as $row) {
            Validator::make($row, ['email' => 'required|email|unique:users,email'])->validate();
            $user = User::create(['name' => $row['name'], 'email' => $row['email'], 'password' => Hash::make(Str::random(40))]);
            $user->assignRole('member');
            $member = Member::create(['user_id' => $user->id, 'code' => 'MB-'.strtoupper(Str::random(8)), 'name' => $row['name'], 'phone' => $row['phone'] ?? null, 'address' => $row['address'] ?? null, 'join_date' => $row['join_date'], 'active' => true]);
            if ((float) $row['opening_balance'] !== 0) {
                $a->post(['type' => 'opening', 'date' => $row['join_date'], 'member_id' => $member->id, 'amount' => (string) $row['opening_balance'], 'description' => 'Imported opening balance']);
            }$a->audit('imported', 'member', $member->id, null, $member->toArray());
        }
    }
}
