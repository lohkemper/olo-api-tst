<?php
declare(strict_types=1);

if (!STOKEN) die('SEC');

class requestGet extends RequestBase {
  private array $files = [];
  private array $tableLinks = [];
  private array $fields = [];
  private array $endresultFields = [];
  private array $select = [];
  private array $request = [];

  public function __construct(PDO $pdo, string $area) {
    parent::__construct($pdo, $area);
    $this->setColums();

    $this->log('requestGet::setColums');
    $this->log(['requestGet::fields', $this->fields]);
    $this->log(['requestGet::endresultFields', $this->endresultFields]);
    $this->log(['requestGet::select', $this->select]);
  }

  private function setColums(): void {
    // Validate table name to prevent SQL injection (only alphanumeric and underscore)
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->area)) {
      throw new \InvalidArgumentException('Invalid table name: ' . $this->area);
    }

    $qRequestMeta = $this->pdo->prepare( "SHOW COLUMNS FROM `" . $this->area ."`" );
    $qRequestMeta->execute();

    while ( $result = $qRequestMeta->fetch()) {
      $endresultFields2 = array();
      foreach( $result AS $key => $value ) {
        if( (int)$key !== $key ) {
          $endresultFields2[ $key ] = $value;

          if( $key == 'Field' ) {
            $fieldname = "`" . $this->area ."`." . $value ;


            if( substr($value, 0, strlen(PREFIX)+1 ) == PREFIX.'_') {
              $valueShort = substr($value, strlen(PREFIX)+1);

              if( substr($value, -5, 5) == '_file') {
                $this->files[] = $value;
                $fieldname = "`" . $this->area . "`." . $value . ' AS ' . $valueShort;
              } else {
                $this->tableLinks[] = $value;
                $fieldname = "`" . $this->area . "`." . $value . ' AS ' . $valueShort;
              }
            }

            $this->select[] = $fieldname;
          }
        }
      }

      $this->endresultFields[ $endresultFields2['Field'] ] = $endresultFields2;
    }
  }

  public function setRequest(array $request): void {
    $this->request = $request;
  }

  public function execute(): void {
    try {
      $sql = [];
      $sqlquery = '';

      $mainTable = "`" . $this->area . "`";
      $sql['SELECT'] = implode(', ', $this->select);
      $sql['FROM'] = $mainTable;

    foreach( $this->files AS $fileField ) {
      $sql['FROM'].= " LEFT JOIN mbc_files AS `mbc_files_".$fileField."` ON `" . $this->area ."`.".$fileField." = `mbc_files_".$fileField."`.files_id ";
      $sql['SELECT'].= ", `mbc_files_".$fileField."`.files_id AS " . $fileField."__file_id" ;
      $sql['SELECT'].= ", `mbc_files_".$fileField."`.name AS " . $fileField."__file_name" ;
      $sql['SELECT'].= ", `mbc_files_".$fileField."`.realname AS " . $fileField."__file_realname" ;
      $sql['SELECT'].= ", `mbc_files_".$fileField."`.type AS " . $fileField."__file_type" ;
      $sql['SELECT'].= ", `mbc_files_".$fileField."`.size AS " . $fileField."__file_size" ;
      $sql['SELECT'].= ", `mbc_files_".$fileField."`.date AS " . $fileField."__file_date" ;
      $sql['SELECT'].= ", `mbc_files_".$fileField."`.time AS " . $fileField."__file_time" ;
    }
    foreach( $this->tableLinks AS $linkField ) {
      // Determine primary key for linked table (e.g., "mbc_users" -> "users_id")
      $linkFieldShort = str_replace(PREFIX . '_', '', $linkField);
      $linkPrimaryKey = $linkFieldShort . '_id';
      $sql['FROM'].= " LEFT JOIN ".$linkField." ON `" . $this->area ."`.".$linkField." = `".$linkField."`.".$linkPrimaryKey." ";
      $sql['SELECT'].= ", `".$linkField."`.".$linkPrimaryKey." AS " . $linkField."__id" ;
    }

    $sql['WHERE'] = array();
    $whereParams = array();

    // Handle ID parameter - find the primary key column name
    if (array_key_exists('id', $this->request) && !empty($this->request['id'])) {
      // Determine the primary key column name (typically {tablename}_id)
      $areaShort = str_replace(PREFIX . '_', '', $this->area);
      $primaryKeyColumn = $areaShort . '_id';

      // Check if the primary key column exists in the table
      if (array_key_exists($primaryKeyColumn, $this->endresultFields)) {
        $idValue = $this->request['id'];
        if (is_array($idValue)) {
          $placeholders = array_fill(0, count($idValue), '?');
          $sql['WHERE'][] = '`' . $primaryKeyColumn . '` IN (' . implode(',', $placeholders) . ')';
          $whereParams = array_merge($whereParams, $idValue);
        } else {
          $sql['WHERE'][] = '`' . $primaryKeyColumn . '` = ?';
          $whereParams[] = $idValue;
        }
      }
    }

    foreach( $this->request AS $key => $value ) {
      if( !in_array( $key, array('area','limit','groupby','page','id','subroute') ) ) {
        // Validate column name to prevent SQL injection
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
          continue;
        }

        if( is_array( $value ) ) {
          // Use placeholders for array values
          $placeholders = array_fill(0, count($value), '?');
          $sql['WHERE'][] = '`' . $key . '` IN (' . implode(',', $placeholders) . ')';
          $whereParams = array_merge($whereParams, $value);
        } else {
          $sql['WHERE'][] = '`' . $key . "` = ?";
          $whereParams[] = $value;
        }
      }
    }

    $sqlquery = 'SELECT ' . $sql['SELECT'] . ' FROM ' . $sql['FROM'] ;

    /*
     * WHERE
     */
    if( count( $sql['WHERE'] ) > 0 ) {
      $sqlquery.= ' WHERE ' . $mainTable . '.' . implode( ' AND ' . $mainTable . '.' , $sql['WHERE'] );
    }

    /*
     * LIMIT
     */
    if( array_key_exists( 'limit', $this->request ) && $this->request['limit'] != '' && preg_match( '/[0-9]+-[0-9]+/Uis', $this->request['limit'] ) ) {
      $sqlquery.= ' LIMIT ' . str_replace( '-', ',',  $this->request['limit'] );
    }

    /*
     * GROUPE BY
     */
    if( array_key_exists( 'groupby', $this->request ) && $this->request['groupby'] != '' ) {
      $sqlquery.= ' GROUP BY ' . str_replace( '-', ',',  $this->request['groupby'] );
    }

    $this->log(['requestGet::sql', $sql]);
    $this->log(['requestGet::sqlquery', $sqlquery]);
    $this->log(['requestGet::whereParams', $whereParams]);

    if(DEBUG) {
      echo '<pre>' . print_r($sql, true) . '</pre>';
    }

    $qRequest = $this->pdo->prepare( $sqlquery );
    $qRequest->execute($whereParams);

    $endresult = array();

    while ( $result = $qRequest->fetch()) {
      $endresult2 = array();

      foreach( $result AS $key => $value ) {
        if( (int)$key !== $key ) {

          $keyparts = explode( '|', str_replace( '__', '|', $key ) );

          if(count($keyparts) == 2) {
            if( in_array( $keyparts[0], $this->files ) ) {
              $keyparts = explode( '|', str_replace( '__', '|', $key ) );

              if( array_key_exists( $keyparts[0], $this->endresultFields ) && count( $keyparts )==2 ) {
                if( $value != NULL ) {
                  if(substr($keyparts[0],0, strlen(PREFIX)+1) == PREFIX ."_") {
                    $keyparts[0] = substr($keyparts[0],strlen(PREFIX)+1);
                  }
                  if( !array_key_exists( $keyparts[0], $endresult2 ) || !is_array( $endresult2[ $keyparts[0] ] ) ) {
                    $endresult2[ $keyparts[0] ] = array();
                  }
                  $endresult2[ $keyparts[0] ][ $keyparts[1] ] = $value;
                }
              }
            } else if( in_array( $keyparts[0], $this->tableLinks ) ) {
              $keyparts = explode( '|', str_replace( '__', '|', $key ) );

              if( array_key_exists( $keyparts[0], $this->endresultFields ) && count( $keyparts )==2 ) {
                if( $value != NULL ) {
                  if(substr($keyparts[0],0, strlen(PREFIX)+1) == PREFIX ."_") {
                    $keyparts[0] = substr($keyparts[0],strlen(PREFIX)+1);
                  }
                  if( !array_key_exists( $keyparts[0], $endresult2 ) || !is_array( $endresult2[ $keyparts[0] ] ) ) {
                    $endresult2[ $keyparts[0] ] = array();
                  }
                  $endresult2[ $keyparts[0] ][ $keyparts[1] ] = $value;
                }
              }

            }
          }
          else {
            $endresult2[ $key ] = $value;
          }
        }
      }
      $endresult[] = $endresult2;
    }

    // Always return results as array for consistent API responses (ISO 25010 - Kompatibilität)
    $json = json_encode( $endresult );

      $json = preg_replace('|"([1-9][0-9]*)"|Uis', '\1', $json);

      echo $json;

      if (DEBUG) {
        echo '<table>';
        foreach ($this->logs as $key => $value) {
          echo '<tr>';
          echo '<td>' . htmlspecialchars($value[0]) . '</td>';
          echo '<td>' . (is_array($value[1]) ? htmlspecialchars($value[1][0] ?? '') : htmlspecialchars($value[1])) . '<br>';
          echo '<pre>' . htmlspecialchars(print_r($value[1][1] ?? $value[1], true)) . '</pre></td>';
          echo '</tr>';
        }
        echo '</table>';
      }
    } catch (\Throwable $e) {
      $this->handleError('Error executing GET request', $e);
    }
  }
}

?>
