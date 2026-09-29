const fs=require('fs');const edit=(p,f)=>fs.writeFileSync(p,f(fs.readFileSync(p,'utf8')));
edit('app/resources/views/dashboard.blade.php',s=>s.replace('@endsection',"@include('dashboard-details')\n@endsection"));
edit('app/resources/views/transactions.blade.php',s=>s.replace('<details><a href="/transactions/{{$t->id}}/edit">Correct</a><summary','<a href="/transactions/{{$t->id}}/edit">Correct</a><details><summary'));
