<?php
declare(strict_types=1);

/** Test-only CLI entry point: never load a deployment .env or use a TCP database. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$socket=(string)getenv('OCCUPANCY_TEST_SOCKET');
$directory=realpath(dirname($socket));
if (!$directory || !str_starts_with(basename($directory),'shelter-tests-')
    || !is_file($directory.'/occupancy-test-instance')
    || file_get_contents($directory.'/occupancy-test-instance')!=='disposable occupancy tests') {
    fwrite(STDERR,"Disposable test socket required\n"); exit(1);
}
function db(): mysqli {
    static $database=null;
    if ($database===null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $database=new mysqli('localhost','root','','shelter_test',0,(string)getenv('OCCUPANCY_TEST_SOCKET'));
        $database->set_charset('utf8mb4');
    }
    return $database;
}
function requirePermission(string $permission): void {}
function verifyCsrf(): void {}
function escape(string $value): string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function view(string $title,string $body): void {}
require dirname(__DIR__).'/app/ResidentManagement.php';
$_SESSION=['user_id'=>1];
$_POST=['action'=>'checkin','resident_id'=>$argv[1]??'','bed_id'=>$argv[2]??''];
$gate=$directory.'/occupancy-start';
file_put_contents($directory.'/ready-'.getmypid(),'ready');
$deadline=microtime(true)+10;
while (!is_file($gate)) {
    if (microtime(true)>$deadline) { fwrite(STDERR,"Test start gate timed out\n"); exit(1); }
    usleep(1000);
}
register_shutdown_function(static function(): void {
    echo json_encode(['status'=>http_response_code()?:200])."\n";
});
occupancyManagement('POST');
