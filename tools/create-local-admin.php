<?php
require __DIR__.'/../app/vendor/autoload.php';
$app=require __DIR__.'/../app/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$email='admin@homebazar.local';if(App\Models\User::where('email',$email)->exists()){echo "Local administrator already exists.\n";exit;}
$password='Hb!'.bin2hex(random_bytes(8));$user=App\Models\User::create(['name'=>'Household Admin','email'=>$email,'password'=>Illuminate\Support\Facades\Hash::make($password)]);$user->assignRole('admin');
file_put_contents(__DIR__.'/../LOCAL-LOGIN.txt',"Home Bazar local account\nURL: http://127.0.0.1:8000\nEmail: $email\nPassword: $password\nChange this password from My account after signing in.\n");echo "Local administrator created; credentials saved in LOCAL-LOGIN.txt.\n";
