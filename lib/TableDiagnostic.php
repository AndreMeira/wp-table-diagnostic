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

    if (!$this->table->guessPrimaryKey()) {
      $this->printComment(
        "*No guess for primary key*"
      );
      return $this->diagnostic['primary key'] = false;
    }

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
        $this->table->getAutoIncrementValue()
      );
    }

    if ($column = $this->table->getAutoIncrementColumn()) {
      $this->printComment(
        "Auto increment column:" .
        $this->table->getAutoIncrementColumn()
      );
      return $this->diagnostic['auto increment'] = true;
    }

    $this->printComment("No auto increment column");
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
      return $this->diagnostic['corrupted primary key lines'] = count($lines);
    }
    $this->diagnostic['corrupted primary key lines'] = 0;
  }

  /**
   *
   */
  protected function diagnosticMissingForeignKeys() {
    $this->printComment("Looking for missing foreign keys");
    $this->diagnostic['missing foreign key'] = [];

    foreach ($this->table->guessForeignKeys() as $foreignKey) {
      $this->printComment("Column ${foreignKey} is likely to be a foreign key");
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

  /**
   *
   */
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
      $this->printForeignKeysSQLTemplate($foreignKeys);
    }
  }

  /**
   *
   */
  protected function printAutoIncrementSQL() {
    $tableName  = $this->table->getName();
    $primaryKey = $this->table->getBestPrimaryKeyGuess();

    if (trim($primaryKey) == '') {
      return;
    }

    $colDef = $this->table->getColumnDefinition($primaryKey);
    $autoIncrement = (int) $this->table->getMaxPrimaryKeyValue();

    $this->printSQL("
      ALTER TABLE `${tableName}` MODIFY `${primaryKey}` $colDef  NOT NULL AUTO_INCREMENT;
    ");

    $this->printSQL("
      ALTER TABLE wp_postmeta AUTO_INCREMENT = ${autoIncrement};
    ");
  }

  /**
   *
   */
  protected function printForeignKeysSQLTemplate($foreignKeys) {
    $tableName  = $this->table->getName();
    $primaryKey = $this->table->getBestPrimaryKeyGuess();
    $colDef     = $this->table->getColumnDefinition($primaryKey);
    $autoIncrement = (int) $this->table->getAutoIncrementValue();

    foreach ($foreignKeys as $key) {
      $this->printSQLTemplate("
        ALTER TABLE `${tableName}`
        ADD CONSTRAINT `fk_${tableName}_${key}`
        FOREIGN KEY (`${key}`)
        REFERENCES `...` (`...id`);
      ");

      $this->printSQL("
        CREATE INDEX index_${tableName}_${key}`
        ON `${tableName}` (`${key}`);
      ");

    }
  }

  /**
   *
   */
  protected function printPrimaryKeySQL() {
    $tableName  = $this->table->getName();
    $primaryKey = $this->table->getBestPrimaryKeyGuess();
    $colDef     = $this->table->getColumnDefinition($primaryKey);
    $autoIncrement = (int) $this->table->getAutoIncrementValue();

    $this->printSQL("
      ALTER TABLE `${tableName}` ADD PRIMARY KEY(`${primaryKey}`);
    ");
  }

  /**
   *
   */
  protected function printFixCorruptedPrimaryKeySQL() {
    $tableName  = $this->table->getName();
    $primaryKey = $this->table->getBestPrimaryKeyGuess();
    $columns    = $this->table->listColumns();
    $columns    = array_keys($columns);
    $columns    = array_diff($columns, [$primaryKey]);
    $columns    = implode(', ', $columns);

    $this->printSQL("
      INSERT INTO `${tableName}` (${columns})
      SELECT $columns FROM `${tableName}`
      WHERE `${primaryKey}` = 0 OR `${primaryKey}` IS NULL;
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
    $str = preg_replace('~\s+~', ' ', $str);
    echo str_replace(PHP_EOL, ' ', trim($str)).PHP_EOL;
  }

  /**
   *
   */
  protected function printSQLTemplate($str) {
    $this->printComment('>>> TEMPLATE to be filled');
    $this->printSQL('-- '.trim($str));
  }
}
