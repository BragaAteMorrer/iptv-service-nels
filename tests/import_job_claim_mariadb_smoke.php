<?php
declare(strict_types=1);

/**
 * Real MariaDB 11.4 syntax and concurrent queue claim regression.
 * Runs ONLY on the isolated, disposable CI database named nsatellite_ci.
 * Never uses DATABASE_URL or an IPTV provider, and never writes production.
 */
require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use IptvService\Command\RunImportJobsCommand;

if (getenv('NSATELLITE_CI_DB_NAME')!=='nsatellite_ci') {
    echo "SKIP: dedicated nsatellite_ci database required; production untouched".PHP_EOL;
    exit(0);
}

$connection=[
    'driver'=>'pdo_mysql',
    'host'=>getenv('NSATELLITE_CI_DB_HOST')?:'127.0.0.1',
    'port'=>(int)(getenv('NSATELLITE_CI_DB_PORT')?:3306),
    'dbname'=>'nsatellite_ci',
    'user'=>getenv('NSATELLITE_CI_DB_USER')?:'ci',
    'password'=>getenv('NSATELLITE_CI_DB_PASSWORD')?:'',
    'charset'=>'utf8mb4'
];
$a=DriverManager::getConnection($connection);
$b=DriverManager::getConnection($connection);
$assert=static function(bool $ok,string $reason):void{
    if(!$ok)throw new RuntimeException('FAIL: '.$reason);
    echo "PASS: ".$reason.PHP_EOL;
};
$assert(
    str_contains(RunImportJobsCommand::CLAIM_SQL,'LIMIT 1 FOR UPDATE SKIP LOCKED')
    && !str_contains(RunImportJobsCommand::CLAIM_SQL,'SKIP LOCKED LIMIT'),
    'claim SQL uses MariaDB locking-clause order'
);

$a->executeStatement('DROP TABLE IF EXISTS import_jobs');
$a->executeStatement(
    "CREATE TABLE import_jobs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_id BIGINT UNSIGNED NOT NULL,
        method VARCHAR(8) NOT NULL DEFAULT 'remote',
        file_path TEXT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'queued',
        attempts SMALLINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        KEY import_jobs_queue(status,id)
    ) ENGINE=InnoDB"
);
$a->executeStatement(
    "INSERT INTO import_jobs(source_id,status) VALUES (101,'queued'),(102,'queued'),(103,'queued')"
);

try {
    $a->beginTransaction();
    $first=$a->fetchAssociative(RunImportJobsCommand::CLAIM_SQL);
    $assert(is_array($first) && (int)$first['source_id']===101,'first worker locks oldest queued job');

    $b->beginTransaction();
    $second=$b->fetchAssociative(RunImportJobsCommand::CLAIM_SQL);
    $assert(is_array($second) && (int)$second['source_id']===102,
        'second worker skips locked job and claims next queued job');
    $assert($first['id']!==$second['id'],'two workers never claim the same job');

    // Same status transition performed by the production worker before COMMIT.
    $a->executeStatement(
        "UPDATE import_jobs SET status='running',attempts=attempts+1,started_at=NOW() WHERE id=?",
        [$first['id']]
    );
    $a->commit();

    $b->executeStatement(
        "UPDATE import_jobs SET status='running',attempts=attempts+1,started_at=NOW() WHERE id=?",
        [$second['id']]
    );
    $b->commit();

    $a->beginTransaction();
    $third=$a->fetchAssociative(RunImportJobsCommand::CLAIM_SQL);
    $assert(is_array($third) && (int)$third['source_id']===103,
        'next invocation claims the remaining queued job');
    $a->executeStatement(
        "UPDATE import_jobs SET status='running',attempts=attempts+1,started_at=NOW() WHERE id=?",
        [$third['id']]
    );
    $a->commit();

    $a->beginTransaction();
    $none=$a->fetchAssociative(RunImportJobsCommand::CLAIM_SQL);
    $assert($none===false,'empty queue returns no job without SQL error');
    $a->commit();

    $statuses=$a->fetchAllAssociative('SELECT source_id,status,attempts FROM import_jobs ORDER BY id');
    $assert(
        count($statuses)===3 &&
        count(array_filter($statuses,static fn(array $r):bool=>
            $r['status']==='running' && (int)$r['attempts']===1))===3,
        'all jobs claimed exactly once'
    );
} finally {
    if($a->isTransactionActive())$a->rollBack();
    if($b->isTransactionActive())$b->rollBack();
    $a->executeStatement('DROP TABLE IF EXISTS import_jobs');
}
echo "PASS: MariaDB 11.4 live queue claim and SKIP LOCKED concurrency".PHP_EOL;
