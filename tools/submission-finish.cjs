const fs=require('fs');const edit=(p,f)=>fs.writeFileSync(p,f(fs.readFileSync(p,'utf8')));
edit('app/app/Services/AccountingService.php',s=>s.replace("'import_key' => $data['import_key'] ?? null]","'import_key' => $data['import_key'] ?? null, 'submission_key' => $data['submission_key'] ?? null]"));
edit('app/app/Http/Requests/TransactionRequest.php',s=>s.replace("return ['type'", "return ['submission_key'=>'nullable|uuid', 'type'"));
edit('app/resources/views/transaction-form.blade.php',s=>s.replace('<input type="hidden" name="type"','<input type="hidden" name="submission_key" value="{{old(\'submission_key\',(string)\\Illuminate\\Support\\Str::uuid())}}"><input type="hidden" name="type"'));
