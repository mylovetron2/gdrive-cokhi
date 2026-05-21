<?php
/**
 * Database Connection Class
 * PDO-based database wrapper with prepared statements
 */

class Database {
    private static $instance = null;
    private $connection;
    private $stmt;
    
    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        $this->connect();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Establish database connection
     */
    private function connect() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            
            // Set charset
            $this->connection->exec("SET NAMES '" . DB_CHARSET . "'");
            
        } catch (PDOException $e) {
            $this->handleError("Connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get PDO connection
     */
    public function getConnection() {
        return $this->connection;
    }
    
    /**
     * Prepare SQL query
     */
    public function query($sql) {
        try {
            $this->stmt = $this->connection->prepare($sql);
            return $this;
        } catch (PDOException $e) {
            $this->handleError("Query preparation failed: " . $e->getMessage());
        }
    }
    
    /**
     * Bind values to prepared statement
     */
    public function bind($param, $value, $type = null) {
        if (is_null($type)) {
            switch (true) {
                case is_int($value):
                    $type = PDO::PARAM_INT;
                    break;
                case is_bool($value):
                    $type = PDO::PARAM_BOOL;
                    break;
                case is_null($value):
                    $type = PDO::PARAM_NULL;
                    break;
                default:
                    $type = PDO::PARAM_STR;
            }
        }
        
        $this->stmt->bindValue($param, $value, $type);
        return $this;
    }
    
    /**
     * Execute prepared statement
     */
    public function execute($params = []) {
        try {
            if (!empty($params)) {
                return $this->stmt->execute($params);
            }
            return $this->stmt->execute();
        } catch (PDOException $e) {
            $this->handleError("Execution failed: " . $e->getMessage());
        }
    }
    
    /**
     * Fetch single record
     */
    public function fetch() {
        $this->execute();
        return $this->stmt->fetch();
    }
    
    /**
     * Fetch all records
     */
    public function fetchAll() {
        $this->execute();
        return $this->stmt->fetchAll();
    }
    
    /**
     * Fetch single column
     */
    public function fetchColumn() {
        $this->execute();
        return $this->stmt->fetchColumn();
    }
    
    /**
     * Get row count
     */
    public function rowCount() {
        return $this->stmt->rowCount();
    }
    
    /**
     * Get last insert ID
     */
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        return $this->connection->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->connection->rollBack();
    }
    
    /**
     * Simple SELECT query helper
     */
    public function select($table, $conditions = [], $columns = '*', $orderBy = '', $limit = '') {
        $sql = "SELECT {$columns} FROM {$table}";
        
        if (!empty($conditions)) {
            $where = [];
            foreach ($conditions as $key => $value) {
                $where[] = "{$key} = :{$key}";
            }
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        
        if (!empty($orderBy)) {
            $sql .= " ORDER BY {$orderBy}";
        }
        
        if (!empty($limit)) {
            $sql .= " LIMIT {$limit}";
        }
        
        $this->query($sql);
        
        if (!empty($conditions)) {
            foreach ($conditions as $key => $value) {
                $this->bind(":{$key}", $value);
            }
        }
        
        return $this->fetchAll();
    }
    
    /**
     * Simple INSERT query helper
     */
    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        
        $this->query($sql);
        
        foreach ($data as $key => $value) {
            $this->bind(":{$key}", $value);
        }
        
        return $this->execute();
    }
    
    /**
     * Simple UPDATE query helper
     */
    public function update($table, $data, $conditions) {
        $set = [];
        foreach ($data as $key => $value) {
            $set[] = "{$key} = :{$key}";
        }
        
        $where = [];
        foreach ($conditions as $key => $value) {
            $where[] = "{$key} = :where_{$key}";
        }
        
        $sql = "UPDATE {$table} SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $where);
        
        $this->query($sql);
        
        foreach ($data as $key => $value) {
            $this->bind(":{$key}", $value);
        }
        
        foreach ($conditions as $key => $value) {
            $this->bind(":where_{$key}", $value);
        }
        
        return $this->execute();
    }
    
    /**
     * Simple DELETE query helper
     */
    public function delete($table, $conditions) {
        $where = [];
        foreach ($conditions as $key => $value) {
            $where[] = "{$key} = :{$key}";
        }
        
        $sql = "DELETE FROM {$table} WHERE " . implode(' AND ', $where);
        
        $this->query($sql);
        
        foreach ($conditions as $key => $value) {
            $this->bind(":{$key}", $value);
        }
        
        return $this->execute();
    }
    
    /**
     * Handle database errors
     */
    private function handleError($message) {
        error_log("Database Error: " . $message);
        if (DEBUG_MODE) {
            throw new RuntimeException("Database Error: " . $message);
        } else {
            throw new RuntimeException("An error occurred. Please try again later.");
        }
    }
    
    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization of singleton
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
