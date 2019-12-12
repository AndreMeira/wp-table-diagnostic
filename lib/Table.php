<?php

/**
 * @author ANDRE MEIRA
 */
class Table {

  /**
   *
   */
  private $pdo;

  /**
   *
   */
  protected $options = [];

  /**
   *
   */
  protected $columns = [];

  /**
   *
   */
  protected $autoIncrement = null;

  /**
   *
   */
  protected $primaryKey = [];

  /**
   *
   */
  protected $trustedPrimaryKey = "";

  /**
   *
   */
  protected $foreignKeys = [];

  /**
   *
   */
  protected $trustedForeignKey = [];

  /**
   *
   */
  protected $brokenLines = [];

  /**
   *
   */
  public function __construct(PDO $pdo, $options = []) {
    $this->pdo = $pdo;
    $this->options = $options;
    $this->verifyOptions();
  }

  /**
   *
   */
  public function getName() {
    return $this->options['table'];
  }

  /**
   *
   */
  public function hasAutoIncrement() {
    $this->getAutoIncrementValue();
    return $this->autoIncrement !== null;
  }

  /**
   *
   */
  public function getAutoIncrementValue() {
    if ($this->autoIncrement !== null) {
      return $this->autoIncrement;
    }

    $sql = "SELECT `auto_increment`
      FROM  INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = :schema
      AND   TABLE_NAME   = :table
      AND auto_increment is not null";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
      ':schema' => $this->options['schema'],
      ':table'  => $this->options['table'],
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $this->autoIncrement = (int) $result['auto_increment'];
    }

    return max($this->autoIncrement, 1);
  }

  /**
   *
   */
  public function getMaxPrimaryKeyValue() {
    if ($key  = $this->getBestPrimaryKeyGuess()) {
      $table  = $this->options['table'];
      $sql    = "SELECT max(`${key}`) as num FROM  ${table}";
      $result = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
      return $result ? $result['num'] : 1;
    }

    return 1;
  }


  /**
   *
   */
  public function getColumnDefinition($col) {
    $col = strtolower($col);
    $columns = $this->listColumns();
    return $columns[$col]['Type'];
  }

  /**
   *
   */
  public function getAutoIncrementColumn() {
    $this->listColumns();

    foreach ($this->columns as $column) {
      if (strpos((string) $column['Extra'], 'auto_increment') !== false) {
        return $column['Field'];
      }
    }
  }

  /**
   *
   */
  public function hasPrimaryKey() {
    if ($this->trustedPrimaryKey) {
      return true;
    }

    $sql = "SELECT k.table_name, k.column_name
      FROM information_schema.table_constraints t
      JOIN information_schema.key_column_usage k
      USING(constraint_name,table_schema,table_name)
      WHERE t.constraint_type='PRIMARY KEY'
        AND t.table_schema = :schema
        AND k.table_name = :table";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
      ':schema' => $this->options['schema'],
      ':table'  => $this->options['table'],
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        $this->primaryKey = [$result['column_name']];
        $this->trustedPrimaryKey = $result['column_name'];
    }

    return (bool) $result;
  }

  /**
   *
   */
  public function getForeignKeys() {
    if ($this->trustedForeignKey) {
      return $this->trustedForeignKey;
    }

    $sql = "SELECT
        table_name,
        column_name,
        constraint_name,
        referenced_table_name,
        referenced_column_name
    FROM
        information_schema.key_column_usage
    WHERE
    	REFERENCED_TABLE_SCHEMA = :schema
      AND REFERENCED_TABLE_NAME = :table";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
      ':schema' => $this->options['schema'],
      ':table'  => $this->options['table'],
    ]);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($result as $foreignKey) {
      $this->trustedForeignKey[$foreignKey['column_name']] = $foreignKey;
    }

    return $this->trustedForeignKey;

  }

  /**
   *
   */
  public function listColumns($reload = false) {
    if (!$this->columns || FALSE != $reload) {
      $table = $this->options['table'];
      $result = $this->pdo->query("desc ${table}")->fetchAll();

      foreach ($result as $column) {
        $key = strtolower($column['Field']);
        $this->columns[$key] = $column;
      }
    }
    return $this->columns;
  }

  /**
   *
   */
  public function findZeroValuePrimaryKey() {
    if ($key = $this->getBestPrimaryKeyGuess()) {
      $key = $this->getBestPrimaryKeyGuess();
      $result[$key] = $this->findZeroValueForColumn($key);
      return $result;
    }
    return [];
  }

  /**
   *
   */
  public function findZeroValueForeignKey() {
    $result = [];
    $foreignKeys = array_merge($this->foreignKeys, $this->trustedForeignKey);

    foreach ($foreignKeys as $key) {
      $result[$key] = $this->findZeroValueForColumn($key);
    }

    return $result;
  }

  /**
   *
   */
  public function getBestPrimaryKeyGuess() {
    $this->guessPrimaryKey();
    return @$this->primaryKey[0];
  }

  /**
   *
   */
  public function guessPrimaryKey() {
    $hasPK = $this->hasPrimaryKey();
    $countPK = count($this->primaryKey);
    if ($hasPK || $countPK) {
      return $this->primaryKey;
    }

    $tableWithNoPrefix = $this->getTrimedTableName();
    $columns = $this->listColumns();

    foreach ($columns as $column) {
      $this->pushToResultIfIsLikelyPrimaryKey(
        $column['Field'], $tableWithNoPrefix
      );
    }
    return $this->primaryKey;
  }

  /**
   *
   */
  public function guessForeignKeys() {
    $trusted = $this->getForeignKeys();
    $columns = $this->listColumns();
    $columns = array_column($columns, 'Field');
    $columns = array_diff($columns, array_keys($trusted));

    foreach ($columns as $column) {
      $this->pushToResultIfIsLikelyForeignKey($column);
    }

    return $this->foreignKeys;
  }

  /**
   *
   */
  public function findZeroValueForColumn($column = null) {
    $column = $column ?: $this->getBestPrimaryKeyGuess();

    if (!preg_match('~^\w+$~', $column)) {
      throw new \Exception("Column name invalid ${column}", 1);
    }

    $table  = $this->options['table'];

    $result = $this->pdo->query("
      select * from ${table}
      where `${column}` = 0
      or `${column}` is null
    ")->fetchAll();

    return $this->brokenLines[$column] = $result;
  }

  /**
   *
   */
  protected function pushToResultIfIsLikelyPrimaryKey($column, $table) {
    $column = strtolower($column);

    if ($column === 'id') {
      return $this->primaryKey[] = $column;
    }

    if ($column === $table.'_id') {
      return $this->primaryKey[] = $column;
    }

    if ($column === rtrim($table, 's').'_id') {
      return $this->primaryKey[] = $column;
    }

    if ($column === 'meta_id' && strpos($table, 'meta') > 0) {
      return $this->primaryKey[] = $column;
    }

    // no complicated cases
    if ($this->primaryKey) {
      return;
    }

    if (strpos($column, "_id") === false) {
      return;
    }

    $prefix = preg_replace('~_id$~', '', $column);

    if (strpos($table, $prefix) === 0) {
      return $this->primaryKey[] = $column;
    }
  }

  protected function pushToResultIfIsLikelyForeignKey($column) {
    $column = strtolower($column);

    if ($column === $this->getBestPrimaryKeyGuess()) {
      return;
    }

    if (preg_match('~_id$~', $column)) {
      return $this->foreignKeys[] = $column;
    }
  }

  /**
   *
   */
   protected function getTrimedTableName() {
     $table  = $this->options['table'];
     $prefix = $this->options['prefix'];

     if (!$prefix) {
       return $table;
     }

     $trimmed = preg_replace("~^${prefix}~", '', $table);
     // prevent sql injection
     return preg_match('~^\w+$~', $trimmed) ? $trimmed : $table;
   }

  /**
   *
   */
  protected function verifyOptions() {
    if (!array_key_exists('schema', $this->options)) {
      throw new \Exception("You need to provide a table", 1);
    }

    if (!array_key_exists('table', $this->options)) {
      throw new \Exception("You need to provide a table", 1);
    }

    // prevent sql injection
    if (!preg_match('~^\w+$~', $this->options['table'])) {
      throw new \Exception("table name is invalid", 1);
    }
  }
}
