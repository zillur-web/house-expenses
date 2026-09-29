<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\ExpenseCategory;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    private function member(string $name = 'Member'): Member
    {
        $u = User::factory()->create(['name' => $name]);
        $u->assignRole('member');

        return Member::create(['user_id' => $u->id, 'code' => uniqid('MB-'), 'name' => $name, 'join_date' => '2026-09-01', 'active' => true]);
    }

    private function record(array $data): Transaction
    {
        return app(AccountingService::class)->post(array_merge(['date' => '2026-09-15', 'amount' => '100', 'description' => 'Test entry', 'payment_method' => 'Cash'], $data));
    }

    public function test_payment_expense_bill_and_void_do_not_double_count(): void
    {
        $m = $this->member();
        $this->record(['type' => 'opening', 'member_id' => $m->id, 'amount' => '-50']);
        $this->record(['type' => 'payment', 'member_id' => $m->id, 'amount' => '200']);
        $expense = $this->record(['type' => 'expense', 'expense_category_id' => ExpenseCategory::first()->id, 'amount' => '90', 'sharing' => 'equal']);
        $this->assertSame(6000, $m->balance());
        $this->record(['type' => 'bill', 'member_id' => $m->id, 'monthly_charge' => '20']);
        $this->assertSame(4000, $m->balance());
        app(AccountingService::class)->void($expense, 'Incorrect expense');
        $this->assertSame(13000, $m->balance());
        $this->assertDatabaseHas('audit_logs', ['action' => 'voided']);
    }

    public function test_equal_shares_conserve_every_paisa(): void
    {
        $a = $this->member('A');
        $b = $this->member('B');
        $c = $this->member('C');
        $this->record(['type' => 'expense', 'expense_category_id' => ExpenseCategory::first()->id, 'amount' => '100', 'sharing' => 'equal']);
        $this->assertSame(-10000, $a->balance() + $b->balance() + $c->balance());
        $this->assertSame(-3334, $a->balance());
    }

    public function test_bad_distribution_rolls_back_entire_transaction(): void
    {
        $m = $this->member();
        try {
            $this->record(['type' => 'expense', 'amount' => '100', 'expense_category_id' => ExpenseCategory::first()->id, 'sharing' => 'custom', 'shares' => [$m->id => '99']]);
            $this->fail('Must reject allocation mismatch');
        } catch (ValidationException $e) {
            $this->assertDatabaseCount('transactions', 0);
        }
    }

    public function test_closed_month_blocks_create_and_void(): void
    {
        $m = $this->member();
        $t = $this->record(['type' => 'payment', 'member_id' => $m->id]);
        $a = app(AccountingService::class);
        $a->close('2026-09', true, 'Month finalized');
        $this->post('/transactions', ['type' => 'payment', 'date' => '2026-09-16', 'member_id' => $m->id, 'amount' => '10', 'description' => 'Blocked', 'payment_method' => 'Cash'])->assertSessionHasErrors('date');
        try {
            $a->void($t, 'Should be blocked');
            $this->fail('Must reject');
        } catch (ValidationException $e) {
            $this->assertSame(10000, $m->balance());
        }$a->close('2026-09', false, 'Correction authorized');
        $a->void($t, 'Correction after reopening');
        $this->assertSame(0, $m->balance());
    }

    public function test_member_cannot_read_other_accounts_or_mutate_finances(): void
    {
        $a = $this->member('Alice');
        $b = $this->member('Private Bob');
        $this->record(['type' => 'payment', 'member_id' => $b->id, 'description' => 'Bob private deposit']);
        $this->actingAs($a->user);
        $this->get('/reports?member_id='.$b->id)->assertOk()->assertDontSee('Bob private deposit')->assertDontSee('Private Bob');
        $this->get('/transactions')->assertOk()->assertDontSee('Bob private deposit');
        $this->get('/members')->assertForbidden();
        $this->post('/transactions', [])->assertForbidden();
        $this->get('/imports')->assertForbidden();
        $this->get('/reports?report=cash')->assertForbidden();
    }

    public function test_all_screens_render_and_exports_work(): void
    {
        $m = $this->member();
        $this->record(['type' => 'payment', 'member_id' => $m->id]);
        foreach (['/', '/members', '/members/create', '/members/'.$m->id.'/edit', '/transactions', '/categories', '/closing', '/audit', '/imports', '/account'] as $url) {
            $this->get($url)->assertOk();
        }foreach (['payment', 'expense', 'bazar', 'bill', 'opening', 'adjustment'] as $type) {
            $this->get('/transactions/create?type='.$type)->assertOk();
        }foreach (array_keys(ReportController::TYPES) as $type) {
            $this->get('/reports?report='.$type)->assertOk();
        }$this->get('/reports?format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/reports?format=xlsx')->assertOk();
    }

    public function test_bazar_multiplies_quantity_and_price(): void
    {
        $m = $this->member();
        $t = $this->record(['type' => 'bazar', 'expense_category_id' => ExpenseCategory::first()->id, 'sharing' => 'equal', 'items' => [['item' => 'Rice', 'quantity' => '1.250', 'unit_price' => '80.50'], ['item' => 'Eggs', 'quantity' => '2', 'unit_price' => '12.00']]]);
        $this->assertSame(12463, $t->amount);
        $this->assertSame(-12463, $m->balance());
    }

    public function test_legacy_workbook_has_verified_september_openings(): void
    {
        $path = base_path('../Bazar.xlsx');
        if (! file_exists($path)) {
            $this->markTestSkipped('Source workbook not available');
        }$data = app(ImportService::class)->preview($path, 'legacy');
        $this->assertCount(8, $data['rows']);
        $latest = $data['rows'][0];
        $this->assertSame('Sep-2026', $latest['sheet']);
        $this->assertEquals(50, $latest['difference']);
        $this->assertEquals([-489.5, -1009.75, 1549.25], array_column($latest['members'], 'balance'));
    }

    public function test_login_and_inactive_member(): void
    {
        $m = $this->member();
        $m->update(['active' => false]);
        $this->actingAs($m->user)->get('/')->assertForbidden();
        $this->post('/logout')->assertRedirect('/login');
        $this->get('/')->assertRedirect('/login');
    }
}
