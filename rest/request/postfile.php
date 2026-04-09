<?php
declare(strict_types=1);

if( !STOKEN ) die('SEC');

define('FILE_SIZE_MAX', 5000000);
define('FILE_TYPES', ["jpg", "png", "jpeg", "gif"]);

class requestPostfile extends RequestBase {
    private array $data = [];
    private int $uploadOk = 1;

    public function __construct(PDO $pdo, string $area) {
        parent::__construct($pdo, $area);
        $this->log('requestPostfile');
    }

    public function setData(array $data): void {
        $this->data = $data;
    }

    private function executeItem(string $position, array $value): void {
        $ext = implode('', array_slice( explode('.', $this->data[$position]["name"]), -1, 1) );
        $name = uniqid().'.'.$ext;
        $target_file = "../../dist/uploads/" . $name;

        // Check if file already exists
        while(file_exists($target_file)) {
            $name = uniqid().'.'.$ext;
            $target_file = "../../dist/uploads/" . $name;
        }

        $imageFileType = strtolower( pathinfo( $this->data[$position]["name"], PATHINFO_EXTENSION ) );

        // Allow certain file formats
        if (!in_array($imageFileType, FILE_TYPES)) {
            throw new InvalidArgumentException("File type not allowed: {$imageFileType}");
        }

        // Check file size
        if ($this->data[$position]["size"] > FILE_SIZE_MAX) {
            throw new InvalidArgumentException("File too large: " . $this->data[$position]["size"]);
        }

        if ($this->uploadOk === 1) {
            if (move_uploaded_file($this->data[$position]["tmp_name"], $target_file)) {
                
                $set = array();
                if( array_key_exists( 'id', $set ) ) {
                    $set[ 'id' ] = (int)$_POST['id'];
                }
                $set['name'] = $name;
                
                $set['date'] = date('Y-m-d');
                $set['time'] = date('H.i.s');
        
                // Use PREFIX constant instead of undefined $prefix variable
                if( array_key_exists( 'id', $set ) ) {
                    $sql = 'UPDATE `' . PREFIX . '_files` SET ';

                    $setParts = [];
                    foreach( $set AS $k => $v ) {
                        $setParts[] = '`' . $k . '` = ' . $this->pdo->quote($v);
                    }
                    $sql.= implode(', ', $setParts);
                    $sql.= ' WHERE `files_id` = ' . (int)$set['id'];
                }
                else {
                    $sql = 'INSERT INTO `' . PREFIX . '_files`' ;
                    $sql.= ' ( `' . implode( '`, `', array_keys( $set ) ) . '` ) VALUES';
                    $sql.= ' ( ' . implode( ', ', array_map([$this->pdo, 'quote'], $set) ) . ' ) ';
                }
            
                $qRequest = $this->pdo->prepare( $sql ); // Prevent MySQl injection. $stmt means statement
                $qRequest->execute();
            
                if( !array_key_exists( 'id', $set ) ) {
                    $set[ 'id' ] = $this->pdo->lastInsertId();
                }
                
                echo json_encode($set);

            } else {
                throw new RuntimeException("Failed to move uploaded file");
            }
        } else {
            throw new RuntimeException("Upload validation failed");
        }
    }

    public function execute(): void {
        try {
            foreach ($this->data as $position => $value) {
                $this->executeItem((string)$position, $value);
            }

            $this->debugOutput();
        } catch (\Throwable $e) {
            $this->handleError('Error executing file upload', $e);
        }
    }
}
?>