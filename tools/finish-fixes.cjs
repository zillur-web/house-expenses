const fs=require('fs');
function edit(p,f){fs.writeFileSync(p,f(fs.readFileSync(p,'utf8')))}
edit('app/phpunit.xml',s=>s.replace('<!-- <env name="DB_CONNECTION" value="sqlite"/> -->','<env name="DB_CONNECTION" value="sqlite" force="true"/>').replace('<!-- <env name="DB_DATABASE" value=":memory:"/> -->','<env name="DB_DATABASE" value=":memory:" force="true"/>'));
edit('app/tests/Feature/AccountingTest.php',s=>s.replaceAll("'expense_category_id'=>1","'expense_category_id'=>ExpenseCategory::first()->id"));
edit('app/app/Services/ImportService.php',s=>s.replace("$row['payment_method']?:'Cash'","($row['payment_method']??null)?:'Cash'"));
edit('app/config/app.php',s=>s.replace("'timezone' => 'UTC'","'timezone' => 'Asia/Dhaka'"));
edit('tools/analyze.php',s=>s.replace("$s->xpath('.//m:t')","$s->xpath('.//*[local-name()=\"t\"]')"));
