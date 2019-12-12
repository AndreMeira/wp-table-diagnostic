<?php

/**
 * @author ANDRE MEIRA
 */
class FindTables {

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
  protected $tableWithPK = [];

  /**
   *
   */
  protected $tableWithNoPK = [];

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
  public function listTableWithPrimaryKey($reload = false) {
    return $this->$tableWithPK && !$reload
      ? $this->$tableWithPK
      : $this->fetchTableWithPrimaryKey();
  }

  /**
   *
   */
  public function listTableWithNoPrimaryKey($reload = false) {
    return $this->$tableWithNoPK && !$reload
      ? $this->$tableWithNoPK
      : $this->fetchTableWithNoPrimaryKey();
  }

  /**
   *
   */
  public function listAll() {
    return $this->pdo->query("SHOW TABLES");
  }

  /**
   *
   */
  protected function fetchTableWithPrimaryKey() {
    $sql = "SELECT k.table_name, k.column_name
      FROM information_schema.table_constraints t
      JOIN information_schema.key_column_usage k
      USING(constraint_name,table_schema,table_name)
      WHERE t.constraint_type='PRIMARY KEY'
        AND t.table_schema = :schema";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([':schema' => $this->options['schema']]);
    $result = $stmt->fetchAll(PDO::FETCH_OBJ);

    foreach ($result as $i => $table) {
      $this->$tableWithPK[$table->table_name] = $table->column_name;
    }
    return $this->$tableWithPK;
  }

  /**
   *
   */
  protected function fetchTableWithNoPrimaryKey() {
    $result = $this->pdo->query("SHOW TABLES");
    $tableWithPK = array_keys($this->listTableWithPrimaryKey());
    return $this->tableWithNoPrimaryKey = array_diff($result, $tableWithPK);
  }

  /**
   *
   */
  protected function verifyOptions() {
    if (array_key_exists('schema', $this->options)) {
      return;
    }

    throw new \Exception("You need to provide a schema", 1);
  }
}
