<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

class requestPost extends RequestBase {
  private array $data = [];

  public function __construct(PDO $pdo, string $area) {
    parent::__construct($pdo, $area);
    $this->log('requestPost');
  }

  public function setData(array $data): void {
    $this->data = $data;
  }

  public function execute(): void {
    try {
      // CSRF Protection for state-changing operations
      CsrfHelper::requireValidToken();

      $sql = [];
      $sqlquery = 'INSERT INTO `' . $this->getArea() . '`';

      $return = [];

    foreach( $this->data AS $key => $value ) {
      if( $key != 'id' ) {
            $return[ $key ] = $value;

            // Use key directly as database column name
            $dbKey = $key;

            // Skip created_at and updated_at - let database handle these
            if (in_array($dbKey, ['created_at', 'updated_at', 'createdAt', 'updatedAt'])) {
                continue;
            }

            // Convert "0" to NULL for foreign key fields
            if (in_array($dbKey, ['parent_id', 'permission_id']) && ($value === '0' || $value === 0)) {
                $value = null;
            }

            if( is_array($value) ) {
              $dbKey = false;
            } else if( $value === true || $value === 'true' ) {
                $value = 1;
            } else if( $value === false || $value === 'false' ) {
                $value = 0;
            } else if( $value === null ) {
                // NULL without quotes for SQL NULL keyword
                $value = 'NULL';
                $sql[$dbKey] = $value;
                continue;
            } else if( is_numeric($value) ) {
                // Keep numeric values as-is
                $value = $value;
            } else {
                $value = $this->pdo->quote( $value );
            }
            if($dbKey) {
              $sql[$dbKey] = $value;
            }
      }
    }

    $sqlquery.= ' ( `' . implode( '`, `', array_keys( $sql ) ) . '` ) VALUES';
    $sqlquery.= ' ( ' . implode( ', ', $sql ) . ' ) ';

      $this->log(['requestPost::sql', $sql]);
      $this->log(['requestPost::sqlquery', $sqlquery]);

      $qRequest = $this->pdo->prepare($sqlquery);
      $qRequest->execute();

      $this->debugOutput();

      $id = $this->pdo->lastInsertId();
      if ($id) {
        $aRequest = ['id' => (int)$id];
        $requestGet = new requestGet($this->pdo, $this->getArea());
        $requestGet->setRequest($aRequest);
        $requestGet->execute();
      }
    } catch (\Throwable $e) {
      $this->handleError('Error executing POST request', $e);
    }
  }
}
?>
