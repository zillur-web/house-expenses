<?php
// Local-only setup: never use these credentials or database settings in production.
$root=dirname(__DIR__);$pdo=new PDO('mysql:host=127.0.0.1;port=3307','root','');
$pdo->exec('CREATE DATABASE IF NOT EXISTS home_bazar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$password=bin2hex(random_bytes(20));$quoted=$pdo->quote($password);
$pdo->exec("CREATE USER IF NOT EXISTS 'home_bazar'@'127.0.0.1' IDENTIFIED BY $quoted");
$pdo->exec("ALTER USER 'home_bazar'@'127.0.0.1' IDENTIFIED BY $quoted");
$pdo->exec("GRANT ALL ON home_bazar.* TO 'home_bazar'@'127.0.0.1'");
$env=file_get_contents($root.'/app/.env');
foreach(['APP_NAME'=>'"Home Bazar"','APP_URL'=>'http://127.0.0.1:8000','APP_DEBUG'=>'false','DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3307','DB_DATABASE'=>'home_bazar','DB_USERNAME'=>'home_bazar','DB_PASSWORD'=>$password] as $key=>$value){$pattern='/^#?\s*'.preg_quote($key,'/').'=.*/m';if(preg_match($pattern,$env))$env=preg_replace($pattern,$key.'='.$value,$env);else $env.="\n$key=$value\n";}
file_put_contents($root.'/app/.env',$env);echo "Local MySQL connection configured.\n";
