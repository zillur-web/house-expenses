# Home Bazar

A working Laravel 12 household accounting application built from `prompt.txt` and analysis of the supplied `Bazar.xlsx`.

## Open the local application

URL: **http://127.0.0.1:8000**

The administrator email and randomly generated password are in **LOCAL-LOGIN.txt**. Change the password under **My account** after signing in.

If the app is stopped, run `Start-HomeBazar.ps1` in PowerShell. It starts the dedicated database on port 3307 and the Laravel development server on port 8000, bound to localhost. PHP and MariaDB use the existing XAMPP installation. The application's database lives in `runtime/mysql`, separate from existing XAMPP databases.

```powershell
.\Start-HomeBazar.ps1
```

## Included functionality

- Admin and member authentication, password change, role management and inactive-account enforcement.
- Member creation, editing, archival, generated member codes, contact details and opening balances.
- Collections with cash, bank, bKash, Nagad, Rocket and other methods.
- Expense categories, private voucher attachments and expense recording.
- Multi-item bazar entries with quantity × unit-price calculations.
- Equal, selected-member, custom-amount and percentage expense allocation, conserving every paisa.
- Monthly bills with new charges and discounts; previous dues, advances, payments and existing expense shares are derived from the ledger.
- Noncash member adjustments and separate opening cash entries.
- Audited corrections and voids, with complete financial history retained.
- Period closing/reopening, snapshots and protection against backdated changes affecting closed periods.
- Dashboard balances, monthly totals, category costs, collection charts and outstanding balances.
- Twelve reports with date/month/year/member/category/method filters where applicable, print, PDF and Excel output.
- Member isolation enforced server-side, including reports and downloads.
- Member/opening-balance import, normalized transaction import, duplicate detection, validation preview, explicit confirmation and import history.
- A dedicated `Bazar.xlsx` closing-balance migration preview.

## First use

1. Sign in with the local administrator account.
2. Create your members or upload `member-template.csv` from Excel migration. Imported member passwords are random; set each member's password from Edit member before giving them access.
3. For the supplied workbook, choose **Bazar.xlsx · closing balance migration**, upload the source, review all eight monthly summaries, choose the cutover month, and map the three names to member accounts. This requires an empty ledger and imports member opening balances and the separate cash opening.
4. Record new collections, bazar entries, expenses and monthly charges.
5. Review statements and close finished accounting months.

Do not import both historical transactions and the same closing balances; that would count the same accounting history twice. The legacy import deliberately prevents this by requiring an empty ledger.

## Workbook findings and migration boundary

See `docs/WORKBOOK-ANALYSIS.md` for cell references and reconciliation details. September's member bazar balances sum to BDT 50 while cash is zero. Some earlier values contain fractions smaller than one paisa. Carry-forward deposits and independent allocation tables cannot safely be treated as new transactions or joined by row number.

The original workbook is unchanged. Legacy migration imports a reviewed snapshot, **not a reconstruction of every historical transaction**. To migrate detailed history, use the normalized transaction template after reconciling ambiguous source entries. The application does not silently invent missing member mappings, expense splits or dates.

Normalized transaction columns: `type,date,member_code,amount,description,payment_method,category,direction`. Types: payment, expense, opening, adjustment, bill. Dates must be `YYYY-MM-DD`; amounts use a decimal point and at most two decimal places. `member_code` is required except for expenses. For expenses, category must already exist; these imports are initially unallocated and can be corrected to add allocations. Bill amounts become monthly charges. A signed opening amount represents advance (positive) or due (negative). Member template columns: `name,email,join_date,opening_balance,phone,address`.

## Accounting design

`transactions` is the shared, typed financial journal for payments, expenses, bazar, bills, openings and adjustments. This avoids duplicated date, amount, reference and audit fields across separate journal tables. Related tables store member ledger lines, expense allocations, bazar items and bill items. Money is stored as integer paisa; balances are derived from non-voided ledger lines. Member balance = credits − debits. Positive means advance; negative means due.

Current cash = opening cash + collections − expenses. Bills, shares and member opening balances do not themselves move cash. Bazar entries are counted once as an expense. Bill reports include existing expense shares without reposting them. A monthly closing carries the ledger forward without creating duplicate entries.

Financial writes use database transactions and a shared row lock to serialize household accounting updates. File attachments are private and accessible to administrators only. Financial corrections void and replace entries atomically. User passwords are never included in audit payloads.

## Development and checks

Requirements: PHP 8.2+, Composer, MySQL 8 or MariaDB 10.4+, and PHP extensions bcmath, curl, dom, fileinfo, gd, mbstring, pdo_mysql, xml and zip. SQLite is used only for isolated automated tests. Bootstrap is stored locally, so the UI has no CDN dependency.

```powershell
cd app
composer install
php artisan migrate --seed
php artisan test
php artisan serve --host=127.0.0.1 --port=8000
```

For a fresh installation, copy `.env.example` to `.env`, set a MySQL connection, generate the key with `php artisan key:generate`, migrate/seed, then create the administrator with `php artisan bazar:admin you@example.com`. The command prompts for a password.

Tests cover money conservation, bazar rounding, transaction rollback, bill corrections, closing locks, backdated writes, member isolation, import duplication, opening migration, all primary screens and PDF/Excel responses. `tools/http-smoke.cjs` checks the running local MySQL-backed app with an actual login session.

## Deployment

The running server is for local use. For hosting, point the web server document root to `app/public`, configure HTTPS, a dedicated database user, `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, a production `APP_URL`, backups for the database and private uploads, and normal Laravel server permissions. Use `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache` and `php artisan view:cache` during deployment.

Do not deploy `LOCAL-LOGIN.txt`, `runtime`, the source workbook, temporary analysis files or the local setup helpers. Do not reuse local development credentials. No external production deployment has been performed. Browser visual QA could not be performed because no browser connection was available; HTTP and server-rendered views were tested.
