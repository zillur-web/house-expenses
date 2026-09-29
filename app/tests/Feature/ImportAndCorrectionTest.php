<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ImportService;
use App\Services\MemberImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class ImportAndCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_form_submission_posts_once(): void
    {
        $m = $this->member();
        $data = ['type' => 'payment', 'date' => '2026-09-01', 'amount' => '25', 'description' => 'One payment', 'member_id' => $m->id, 'submission_key' => (string) Str::uuid()];
        $service = app(AccountingService::class);
        $first = $service->post($data);
        $second = $service->post($data);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(2500, $m->balance());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $u = User::factory()->create();
        $u->assignRole('admin');
        $this->actingAs($u);
    }

    private function member(): Member
    {
        $u = User::factory()->create();
        $u->assignRole('member');

        return Member::create(['user_id' => $u->id, 'name' => $u->name, 'code' => uniqid('MB'), 'join_date' => '2026-09-01', 'active' => true]);
    }

    public function test_correction_replaces_bill_without_losing_history(): void
    {
        $m = $this->member();
        $a = app(AccountingService::class);
        $bill = $a->post(['type' => 'bill', 'date' => '2026-09-01', 'description' => 'Original rent', 'member_id' => $m->id, 'monthly_charge' => '100']);
        $this->get('/transactions/'.$bill->id.'/edit')->assertOk()->assertSee('Original rent');
        $this->put('/transactions/'.$bill->id, ['type' => 'bill', 'date' => '2026-09-01', 'description' => 'Corrected rent', 'member_id' => $m->id, 'monthly_charge' => '150', 'payment_method' => 'Cash', 'correction_reason' => 'Correct rental amount'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(-15000, $m->balance());
        $this->assertSame(2, Transaction::withTrashed()->count());
        $this->assertDatabaseHas('bills', ['transaction_id' => $bill->id, 'month' => '2026-09']);
    }

    public function test_closed_later_month_prevents_backdated_balance_changes(): void
    {
        $m = $this->member();
        $a = app(AccountingService::class);
        $a->close('2026-10', true, 'October complete');
        $this->expectException(ValidationException::class);
        $a->post(['type' => 'payment', 'date' => '2026-09-01', 'member_id' => $m->id, 'amount' => '20', 'description' => 'Backdated']);
    }

    public function test_transaction_import_is_atomic_and_idempotent(): void
    {
        $m = $this->member();
        $rows = [['row' => 2, 'type' => 'payment', 'date' => '2026-09-10', 'member_code' => $m->code, 'amount' => '100.25', 'description' => 'Imported contribution', 'key' => hash('sha256', 'one'), 'errors' => []]];
        $id = DB::table('imports')->insertGetId(['filename' => 'test.csv', 'hash' => hash('sha256', 'test'), 'rows' => json_encode(['mode' => 'transactions', 'data' => $rows]), 'errors' => '[]', 'status' => 'preview', 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        $s = app(ImportService::class);
        $s->confirm($id, [], app(AccountingService::class));
        $this->assertSame(10025, $m->balance());
        try {
            $s->confirm($id, [], app(AccountingService::class));
            $this->fail('Repeated import must fail');
        } catch (ValidationException $e) {
            $this->assertDatabaseCount('transactions', 1);
        }
    }

    public function test_legacy_import_posts_member_and_cash_openings_separately(): void
    {
        $members = [$this->member(), $this->member(), $this->member()];
        $row = ['sheet' => 'Sep-2026', 'date' => '2026-09-30', 'cash' => 25, 'members' => [['name' => 'A', 'balance' => 10, 'source' => 'K58'], ['name' => 'B', 'balance' => 20, 'source' => 'L58'], ['name' => 'C', 'balance' => -5, 'source' => 'M58']]];
        $id = DB::table('imports')->insertGetId(['filename' => 'legacy.xlsx', 'hash' => hash('sha256', 'legacy'), 'rows' => json_encode(['mode' => 'legacy', 'data' => [$row]]), 'errors' => '[]', 'status' => 'preview', 'user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        app(ImportService::class)->confirm($id, ['sheet' => 'Sep-2026', 'members' => array_map(fn ($m) => $m->id, $members)], app(AccountingService::class));
        $this->assertSame(2500, (int) Transaction::where('type', 'cash_opening')->sum('amount'));
        $this->assertSame(1000, $members[0]->balance());
        $this->assertSame(0, (int) Transaction::where('type', 'payment')->sum('amount'));
    }

    public function test_member_import_preview_and_confirmation(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['name', 'email', 'join_date', 'opening_balance'], ['Import Member', 'import@example.com', '2026-09-01', '-12.50']]);
        $service = app(MemberImportService::class);
        $preview = $service->preview($book->getActiveSheet());
        $this->assertEmpty($preview['errors']);
        DB::transaction(fn () => $service->import($preview['rows'], app(AccountingService::class)));
        $this->assertSame(-1250, Member::where('name', 'Import Member')->first()->balance());
    }

    public function test_users_and_opening_cash_screens_render(): void
    {
        $this->get('/users')->assertOk();
        $this->get('/transactions/create?type=cash_opening')->assertOk();
    }
}
