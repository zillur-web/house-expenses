<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportService
{
    public function preview(string $path, string $mode): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(false);
        $book = $reader->load($path);
        $rows = [];
        $errors = [];
        if ($mode === 'members') {
            $result = app(MemberImportService::class)->preview($book->getSheet(0));
            $book->disconnectWorksheets();

            return $result;
        }
        if ($mode === 'legacy') {
            foreach ($book->getAllSheets() as $sheet) {
                $name = $sheet->getTitle();
                if (! preg_match('/^[A-Z][a-z]{2}-20\d{2}$/', $name)) {
                    $errors[] = "$name: unrecognized month sheet.";

                    continue;
                }$month = Carbon::createFromFormat('!M-Y', $name);
                $offset = trim((string) $sheet->getCell('B2')->getValue()) === 'SL' ? 1 : 0;
                $cols = $offset ? ['L', 'M', 'N'] : ['K', 'L', 'M'];
                $end = $month->copy()->endOfMonth()->toDateString();
                $cash = $this->cached($sheet, ($offset ? 'G104' : 'F103'));
                $members = [];
                $sum = 0;
                foreach ($cols as $col) {
                    $memberName = trim((string) $sheet->getCell($col.(1 + $offset))->getValue());
                    $market = $this->cached($sheet, $col.(58 + $offset));
                    $utilityDue = $this->cached($sheet, $col.(74 + $offset));
                    $sum += $market;
                    $balance = round($market - $utilityDue, 2);
                    $members[] = ['name' => $memberName, 'bazar_balance' => $market, 'utility_due' => $utilityDue, 'balance' => $balance, 'source' => $col.(58 + $offset).' - '.$col.(74 + $offset)];
                }$rows[] = ['sheet' => $name, 'date' => $end, 'cash' => $cash, 'member_bazar_total' => $sum, 'difference' => round($sum - $cash, 3), 'members' => $members];
            }
            usort($rows, fn ($a, $b) => strcmp($b['date'], $a['date']));
            if (! $rows) {
                $errors[] = 'No supported monthly sheets were found.';
            }
        } else {
            $sheet = $book->getSheet(0);
            $data = $sheet->toArray(null, true, false, false);
            $headers = array_map(fn ($v) => strtolower(trim((string) $v)), array_shift($data) ?? []);
            $required = ['type', 'date', 'member_code', 'amount', 'description'];
            foreach ($required as $key) {
                if (! in_array($key, $headers)) {
                    $errors[] = "Missing column: $key";
                }
            }
            if (! $errors) {
                foreach ($data as $i => $cells) {
                    if (! array_filter($cells, fn ($v) => $v !== null && $v !== '')) {
                        continue;
                    }$row = array_combine($headers, array_pad(array_slice($cells, 0, count($headers)), count($headers), null));
                    $row = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);
                    $row['row'] = $i + 2;
                    $v = Validator::make($row, ['type' => 'required|in:payment,expense,opening,adjustment,bill', 'date' => 'required|date_format:Y-m-d', 'amount' => 'required|decimal:0,2|between:-999999999,999999999', 'description' => 'required|string|max:1000', 'member_code' => 'nullable|required_unless:type,expense|exists:members,code', 'payment_method' => 'nullable|in:Cash,Bank,bKash,Nagad,Rocket,Other', 'direction' => 'nullable|in:credit,debit']);
                    $row['errors'] = $v->errors()->all();
                    if (($row['type'] ?? '') === 'expense' && ! ExpenseCategory::where('name', $row['category'] ?? '')->exists()) {
                        $row['errors'][] = 'Expense category does not exist.';
                    }if (! in_array($row['type'] ?? '', ['opening', 'adjustment']) && (float) ($row['amount'] ?? 0) < 0) {
                        $row['errors'][] = 'Amount must be positive.';
                    }$row['key'] = hash('sha256', json_encode(collect($row)->except(['row', 'errors'])->all()));
                    if (DB::table('transactions')->where('import_key', $row['key'])->exists()) {
                        $row['errors'][] = 'Duplicate transaction already imported.';
                    }if (in_array($row['key'], array_column($rows, 'key'))) {
                        $row['errors'][] = 'Duplicate row in this file.';
                    }if ($row['errors']) {
                        $errors[] = 'Row '.$row['row'].': '.implode(' ', $row['errors']);
                    }$rows[] = $row;
                }
            }
        }$book->disconnectWorksheets();

        return ['rows' => $rows, 'errors' => $errors];
    }

    private function cached($sheet, string $cell): float
    {
        $c = $sheet->getCell($cell);
        $v = $c->isFormula() ? $c->getOldCalculatedValue() : $c->getValue();
        if (! is_numeric($v)) {
            throw ValidationException::withMessages(['file' => 'Missing numeric cached value at '.$sheet->getTitle().'!'.$cell]);
        }

return (float) $v;
    }

    public function confirm(int $id, array $options, AccountingService $a): void
    {
        DB::transaction(function () use ($id, $options, $a) {
            DB::table('accounting_locks')->where('id', 1)->lockForUpdate()->first();
            $import = DB::table('imports')->where('id', $id)->lockForUpdate()->first();
            abort_unless($import, 404);
            if ($import->status === 'confirmed') {
                throw ValidationException::withMessages(['file' => 'This file was already imported.']);
            }$payload = json_decode($import->rows, true);
            if (json_decode($import->errors, true)) {
                throw ValidationException::withMessages(['file' => 'Resolve all invalid rows before importing.']);
            }
            if ($payload['mode'] === 'members') {
                app(MemberImportService::class)->import($payload['data'], $a);
            } elseif ($payload['mode'] === 'legacy') {
                $snapshot = collect($payload['data'])->firstWhere('sheet', $options['sheet'] ?? '');
                if (! $snapshot) {
                    throw ValidationException::withMessages(['sheet' => 'Select a source month.']);
                }if (DB::table('transactions')->exists()) {
                    throw ValidationException::withMessages(['file' => 'Legacy opening migration requires an empty ledger to prevent double counting.']);
                }$a->post(['type' => 'cash_opening', 'date' => $snapshot['date'], 'amount' => number_format($snapshot['cash'], 2, '.', ''), 'description' => 'Legacy cash opening: '.$snapshot['sheet'], 'import_key' => hash('sha256', $import->hash.':cash')]);
                foreach ($snapshot['members'] as $i => $row) {
                    $member = Member::whereKey($options['members'][$i] ?? 0)->where('active', true)->first();
                    if (! $member) {
                        throw ValidationException::withMessages(['members' => 'Map each workbook member to an active account.']);
                    }$a->post(['type' => 'opening', 'date' => $snapshot['date'], 'member_id' => $member->id, 'amount' => number_format($row['balance'], 2, '.', ''), 'description' => 'Legacy closing balance: '.$snapshot['sheet'].' / '.$row['name'].' / '.$row['source'], 'import_key' => hash('sha256', $import->hash.':'.$snapshot['sheet'].':'.$i)]);
                }
            } else {
                foreach ($payload['data'] as $row) {
                    if (DB::table('transactions')->where('import_key', $row['key'])->exists()) {
                        throw ValidationException::withMessages(['file' => 'Row '.$row['row'].' has already been imported.']);
                    }$data = ['type' => $row['type'], 'date' => $row['date'], 'amount' => (string) $row['amount'], 'description' => $row['description'], 'payment_method' => ($row['payment_method'] ?? null) ?: 'Cash', 'import_key' => $row['key'], 'direction' => $row['direction'] ?? 'credit'];
                    if (! empty($row['member_code'])) {
                        $data['member_id'] = Member::where('code', $row['member_code'])->firstOrFail()->id;
                    }if ($row['type'] === 'expense') {
                        $data['expense_category_id'] = ExpenseCategory::where('name', $row['category'])->firstOrFail()->id;
                    }if ($row['type'] === 'bill') {
                        $data['monthly_charge'] = (string) $row['amount'];
                    }$a->post($data);
                }
            }
            DB::table('imports')->where('id', $id)->update(['status' => 'confirmed', 'updated_at' => now()]);
            $a->audit('imported','import',$id,null,['filename' => $import->filename, 'mode' => $payload['mode'], 'options' => $options]);
        });
    }
}
