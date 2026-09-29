# Bazar.xlsx accounting analysis

Source retained unchanged. Eight sheets cover February–September 2026, newest first. February–April use a one-row and one-column offset compared with May–September.

## Layout and rules

- Main cash journal: serial, Excel date, name/description, deposit, expense, cash running balance, extra, adjusted total. May–September use A:H, rows 2:102; February–April use B:I, rows 3:103.
- Cash running balance = previous cash balance + deposit - expense. September D103/E103 both equal 3,440; F103 is zero.
- Independent member expense allocations occupy K:M (L:N in older sheets). They are not necessarily aligned with the cash journal row. Never join these tables by row number.
- Members named in allocation headers: Zillur, Isteak, Denju. Many expenses use 50% / 25% / 25%, but custom amounts and absences mean this is not universal.
- Member bazar balance = member deposit summary minus expense share summary. September K58 = K57 - K34 = 2,000.50 - 1,555 = 445.50. Negative means due; positive means advance.
- Utility bills are separate: rent (Home), gas, electricity, internet, garbage, service, occasional second electricity charge. September K72 = sum(K64:K71); K74 = K72 - K73. Positive utility due means debt; negative means utility advance.
- Unified ledger credit balance = bazar balance - utility due. September openings: Zillur -489.50, Isteak -1,009.75, Denju +1,549.25.
- Previous month balances appear again as deposits, sometimes negative. These are carry-forwards, not new collections, and must not be posted twice.
- Older sheets include a loan section, adjusted cash minus loan amounts. Loans must not be silently treated as member payments.

## Reconciliation findings

September member bazar balances total 50 BDT but main cash is zero. Expense allocation total is 3,390 while cash expense total is 3,440. April contains fractional-paisa values (0.097 and 429.954). Several dated entries are outside the named month. Some totals use ranges ending before adjacent data rows; many formulas are shared formulas in the OOXML file and cached values must be read with a spreadsheet reader.

These are source differences, not application errors to silently fix. Opening migration shows all monthly summaries and differences for review, rounds final member openings to two decimals and requires explicit confirmation. Historical detailed migration uses a validated normalized template; it does not infer ambiguous member names, expense allocations, carry-forwards or missing transaction dates.

## Application accounting decisions

Money is integer paisa. Member balance is sum(credits - debits), calculated from non-voided transactions. Payments credit members and increase cash. Expenses reduce cash; allocated shares debit members. Bills debit only new charges, while existing shares and previous balances remain in the ledger. Opening balances and noncash adjustments affect members, not collections. Carry-forward is the prior ledger balance, never a duplicate posting. Voiding excludes the original transaction without deleting its audit history. Closing locks the entire accounting period.
