<?php

/**
 * @author ANDRE MEIRA
 */
class TableDiagnostic {

  /**
   *
   */
  private $table;

  private $diagnostic = [];

  /**
   *
   */
  public function __construct(Table $table) {
    $this->table = $table;
  }

  /**
   *
   */
  public function runDiagnostic() {
    $tableName = $this->table->getName();
    $this->printComment("Diagnostic table: ${tableName}");
    $this->diagnosticPrimaryKey();
    $this->diagnosticAutoIncrement();
    $this->diagnosticZeroValuePrimaryKey();
    $this->diagnosticMissingForeignKeys();
    $this->diagnosticZeroValueForeignKeys();

    $this->printSQLRepair();
  }

  /**
   *
   */
  protected function diagnosticPrimaryKey() {
    $this->printComment("Looking for missing primary key");

    if ($this->table->hasPrimaryKey()) {
      $this->printComment(
        "Primary key OK: " .
        $this->table->getBestPrimaryKeyGuess()
      );

      return $this->diagnostic['primary key'] = true;
    }

    $this->printComment(
      "Primary key not found"
    );

    $this->printComment(
      "Primary key most likely to be: " .
      $this->table->getBestPrimaryKeyGuess()
    );

    $this->printComment(
      "Primary key could be: " .
      implode(', ', $this->table->guessPrimaryKey())
    );

    return $this->diagnostic['primary key'] = false;
  }

  /**
   *
   */
  protected function diagnosticAutoIncrement() {
    $this->printComment("Looking for auto increment");

    if ($this->table->hasAutoIncrement()) {
      $this->printComment(
        "Auto increment found:" .
        $this->table->getAutoIncrementColumn()
      );
      return $this->diagnostic['auto increment'] = true;
    }

    $this->printComment("No auto increment");
    $this->diagnostic['auto increment'] = false;
  }

  /**
   *
   */
  protected function diagnosticZeroValuePrimaryKey() {
    $this->printComment("Looking for corrupted primary key");

    foreach ($this->table->findZeroValuePrimaryKey() as $col => $lines) {
      $count = count($lines);
      $this->printComment("Column ${col} has ${count} corrupted valued lines");
      $this->diagnostic['corrupted primary key lines'] = count($lines);
    }
  }

  /**
   *
   */
  protected function diagnosticMissingForeignKeys() {
    $this->printComment("Looking for missing foreign keys");
    $this->diagnostic['missing foreign key'] = [];

    foreach ($this->table->guessForeignKeys() as $foreignKey) {
      $this->printComment("Column ${col} is likely to be a foreign key");
      $this->diagnostic['missing foreign key'][] = $foreignKey;
    }
  }

  /**
   *
   */
  protected function diagnosticZeroValueForeignKeys() {
    $this->printComment("Looking for corrupted foreign keys");

    foreach ($this->table->findZeroValueForeignKey() as $col => $lines) {
      $count = count($lines);
      $this->printComment("Column ${col} has ${count} corrupted valued lines");
      $this->diagnostic['corrupted foreign key lines'][$col] = count($lines);
    }
  }

  protected function printSQLRepair() {
    if (!$this->diagnostic['auto increment']) {
      $this->printAutoIncrementSQL();
    }

    if ($this->diagnostic['corrupted primary key lines']) {
      $this->printFixCorruptedPrimaryKeySQL();
    }

    if (!$this->diagnostic['primary key']) {
      $this->printPrimaryKeySQL();
    }

    if ($foreignKeys = $this->diagnostic['missing foreign key']) {
      $this->printForeignKeysSQLTemplate();
    }
  }

  protected function printAutoIncrementSQL() {
    $tableName  = $this->table->getName();
    $primaryKey = $this->table->getBestPrimaryKeyGuess();
    $colDef     = $this->table->getColumnDefinition($primaryKey);
    $autoIncrement = (int) $this->table->getAutoIncrementValue();

    $this->printSQL("
      ALTER TABLE `${tableName}` MODIFY `${primaryKey}` $colDef  NOT NULL AUTO_INCREMENT;
    ");

    $this->printSQL("
      ALTER TABLE wp_postmeta AUTO_INCREMENT = ${autoIncrement};
    ");
  }

  protected function printForeignKeysSQLTemplate($foreignKeys) {
    $tableName  = $this->table->getName();
    $primaryKey = $this->table->getBestPrimaryKeyGuess();
    $colDef     = $this->table->getColumnDefinition($primaryKey);
    $autoIncrement = (int) $this->table->getAutoIncrementValue();

    foreach ($foreignKeys as $key) {
      $this->printSQL("
        ALTER TABLE `${tableName} `
        ADD CONSTRAINT `fk_${tableName}_${key}`
        FOREIGN KEY (`${key}`)
        REFERENCES `...` (`...id`);
      ");
    }
  }

  protected function printPrimaryKeySQL() {
    $tableName  = $this->table->getName();
    $primaryKey = $this->table->getBestPrimaryKeyGuess();
    $colDef     = $this->table->getColumnDefinition($primaryKey);
    $autoIncrement = (int) $this->table->getAutoIncrementValue();

    $this->printSQL("
      ALTER TABLE `${tableName}` ADD PRIMARY KEY(`${primaryKey}`);
    ");
  }

  protected function printFixCorruptedPrimaryKeySQL() {
    $tableName  = $this->table->getName();
    $primaryKey = $this->table->getBestPrimaryKeyGuess();
    $columns    = $this->table->listColumns();
    $columns    = array_keys($columns);
    $columns    = array_diff($columns, [$primaryKey]);
    $columns    = implode(', ', $columns);

    $this->printSQL("
      INSERT INTO `${tableName}` (${columns})
      SELECT $columns FROM `${tableName}`;
    ");

    $this->printSQL("
      DELETE FROM `${tableName}`
      WHERE `${primaryKey}` = 0
      OR `${primaryKey}` IS NULL;
    ");
  }

  /**
   *
   */
  protected function printComment($str) {
    echo '-- '. $str.PHP_EOL;
  }

  /**
   *
   */
  protected function printSQL($str) {
    echo str_replace(PHP_EOL, ' ', $str).PHP_EOL;
  }

  /**
   *
   */
  protected function printSQLTemplate($str) {
    echo str_replace(PHP_EOL, ' ', '-- '.$str).PHP_EOL;
  }
}
