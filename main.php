<?php

require_once __DIR__.'/lib/FindTables.php';
require_once __DIR__.'/lib/Table.php';
require_once __DIR__.'/lib/TableDiagnostic.php';

$conf = file_get_contents(__DIR__.'/conf/config.json');
$conf = json_decode($conf, true);

$database = $conf['database'];
$host     = $conf['host'];

$dsn      = "mysql:host=${host};dbname=${database}";
$username = $conf['username'];
$password = $conf['password'];
$options  = array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8');

$pdo = new PDO(
  $dsn, $username, $password, $options
);

$tableFinder = new FindTables($pdo, [
  "schema" => $database
]);

foreach ($tableFinder->listAll() as $tableName) {
  $table = new Table($pdo, [
      "schema" => $database,
      "table" => $tableName
  ]);

  $diagnostic = new TableDiagnostic($table);
  $diagnostic->runDiagnostic();
  echo PHP_EOL, PHP_EOL;
}
