<?php
declare(strict_types=1);
/** Operator-run CLI backup. Never streams database content to the terminal. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/bootstrap/app.php';
$path=$argv[1]??'';
$parent=realpath(dirname($path)); $root=realpath(dirname(__DIR__));
if ($path==='' || !$parent || !is_dir($parent) || str_starts_with($parent,$root) || is_link($path)) { fwrite(STDERR,"Choose an existing private directory outside the application checkout.\n"); exit(1); }
$file=@fopen($path,'x');
if (!$file) { fwrite(STDERR,"Cannot create backup; destination must not already exist.\n"); exit(1); }
chmod($path,0600);
$env=getenv(); $env['MYSQL_PWD']=getenv('DB_PASSWORD')?:'';
$command=['mariadb-dump','--no-defaults','--single-transaction','--routines','--triggers','--hex-blob',
 '--host='.(getenv('DB_HOST')?:'127.0.0.1'),'--port='.(getenv('DB_PORT')?:'3306'),
 '--user='.(getenv('DB_USER')?:'')];
if (getenv('DB_SOCKET')) $command[]='--socket='.getenv('DB_SOCKET');
$command[]=getenv('DB_NAME')?:'';
$error=tmpfile();
$process=proc_open($command,[0=>['pipe','r'],1=>$file,2=>$error],$pipes,null,$env);
if (!is_resource($process)) { fclose($file); unlink($path); fwrite(STDERR,"Backup process could not start.\n"); exit(1); }
fclose($pipes[0]); $status=proc_close($process); fclose($file); fclose($error);
if ($status!==0) { unlink($path); fwrite(STDERR,"Backup failed. Check database access and mariadb-dump availability. No backup was retained.\n"); exit(1); }
fwrite(STDOUT,"Backup saved with private file permissions. Test restoration in an isolated database before deployment.\n");
