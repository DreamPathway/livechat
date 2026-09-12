<?php
/**
 * 数据库操作类
 */
class Database {
    private static $instance = null;
    private $pdo;
    private $prefix;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $this->prefix = DB_PREFIX;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            error_log("[LiveChat DB Connection Failed] DSN: mysql:" . DB_HOST . "/" . DB_NAME . " | Error: " . $msg);
            throw new Exception("数据库连接失败，请检查配置");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getPdo() {
        return $this->pdo;
    }
    
    public function getPrefix() {
        return $this->prefix;
    }
    
    /**
     * 获取表名
     */
    public function table($name) {
        return $this->prefix . $name;
    }
    
    /**
     * 执行SQL查询
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query failed: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($params));
            $msg = $e->getMessage();
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                throw new Exception("数据库查询失败: " . $msg . " | SQL: " . $sql);
            }
            throw new Exception("数据库查询失败，请稍后重试");
        }
    }
    
    /**
     * 获取单条记录
     */
    public function fetch($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * 获取多条记录
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * 获取最后插入ID
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    /**
     * 获取影响行数
     */
    public function rowCount($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * 插入数据
     */
    public function insert($table, $data) {
        $fields = array_keys($data);
        $placeholders = array_map(function($f) { return ':' . $f; }, $fields);

        $sql = "INSERT INTO {$table} (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";

        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("[LiveChat Insert] SQL: " . $sql);
            error_log("[LiveChat Insert] Params: " . json_encode($data));
        }

        $this->query($sql, $data);
        return $this->lastInsertId();
    }
    
    /**
     * 更新数据
     */
    public function update($table, $data, $where, $whereParams = []) {
        $set = [];
        foreach (array_keys($data) as $field) {
            $set[] = "{$field} = :{$field}";
        }

        $sql = "UPDATE {$table} SET " . implode(',', $set) . " WHERE {$where}";
        $mergedParams = array_merge($data, $whereParams);

        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("[LiveChat Update] SQL: " . $sql);
            error_log("[LiveChat Update] Params: " . json_encode($mergedParams));
        }

        $this->query($sql, $mergedParams);
        return true;
    }
    
    /**
     * 删除数据
     */
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $this->query($sql, $params);
        return true;
    }
    
    /**
     * 检查表是否存在
     */
    public function tableExists($tableName) {
        $sql = "SHOW TABLES LIKE ?";
        $result = $this->fetch($sql, [$tableName]);
        return !empty($result);
    }
    
    /**
     * 初始化数据库表（容错模式，单表失败不阻塞其他表）
     */
    public function initTables() {
        $prefix = $this->prefix;
        $errors = [];
        
        // 客户端表
        try {
            $this->query("
                CREATE TABLE IF NOT EXISTS {$prefix}clients (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    client_id VARCHAR(64) NOT NULL UNIQUE,
                    user_id VARCHAR(64) DEFAULT NULL COMMENT '网站用户ID',
                    nickname VARCHAR(128) NOT NULL,
                    email VARCHAR(255) DEFAULT NULL,
                    phone VARCHAR(32) DEFAULT NULL,
                    ip VARCHAR(45) NOT NULL,
                    user_agent TEXT,
                    first_visit INT NOT NULL,
                    last_active INT NOT NULL,
                    is_online TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_client_id (client_id),
                    INDEX idx_user_id (user_id),
                    INDEX idx_last_active (last_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            error_log("[LiveChat] Failed to create {$prefix}clients table: " . $e->getMessage());
            $errors[] = 'clients';
        }
        
        // 消息表（外键依赖 clients 表）
        try {
            $this->query("
                CREATE TABLE IF NOT EXISTS {$prefix}messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    message_id VARCHAR(32) NOT NULL UNIQUE,
                    client_id VARCHAR(64) NOT NULL,
                    sender VARCHAR(64) NOT NULL COMMENT 'sender: client/admin',
                    sender_name VARCHAR(128) NOT NULL,
                    message TEXT,
                    image_url VARCHAR(255) DEFAULT NULL,
                    is_admin TINYINT(1) DEFAULT 0,
                    is_read TINYINT(1) DEFAULT 0,
                    timestamp INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_client_id (client_id),
                    INDEX idx_timestamp (timestamp),
                    INDEX idx_sender (sender),
                    FOREIGN KEY (client_id) REFERENCES {$prefix}clients(client_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            // 外键约束可能因 clients 表不存在而失败，尝试降级为无外键版本
            if (stripos($e->getMessage(), 'foreign key') !== false || stripos($e->getMessage(), 'referenced') !== false) {
                error_log("[LiveChat] Foreign key failed for {$prefix}messages, retrying without FK...");
                try {
                    $this->query("
                        CREATE TABLE IF NOT EXISTS {$prefix}messages (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            message_id VARCHAR(32) NOT NULL UNIQUE,
                            client_id VARCHAR(64) NOT NULL,
                            sender VARCHAR(64) NOT NULL COMMENT 'sender: client/admin',
                            sender_name VARCHAR(128) NOT NULL,
                            message TEXT,
                            image_url VARCHAR(255) DEFAULT NULL,
                            is_admin TINYINT(1) DEFAULT 0,
                            is_read TINYINT(1) DEFAULT 0,
                            timestamp INT NOT NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            INDEX idx_client_id (client_id),
                            INDEX idx_timestamp (timestamp),
                            INDEX idx_sender (sender)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    error_log("[LiveChat] Created {$prefix}messages without foreign key constraint");
                } catch (Exception $fkEx) {
                    error_log("[LiveChat] Failed to create {$prefix}messages table: " . $fkEx->getMessage());
                    $errors[] = 'messages';
                }
            } else {
                error_log("[LiveChat] Failed to create {$prefix}messages table: " . $e->getMessage());
                $errors[] = 'messages';
            }
        }
        
        // 设置表
        try {
            $this->query("
                CREATE TABLE IF NOT EXISTS {$prefix}settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(64) NOT NULL UNIQUE,
                    setting_value TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            error_log("[LiveChat] Failed to create {$prefix}settings table: " . $e->getMessage());
            $errors[] = 'settings';
        }
        
        // 检查是否有旧JSON数据
        if (!in_array('clients', $errors)) {
            $jsonFile = dirname(__FILE__) . '/chat_data.json';
            if (file_exists($jsonFile)) {
                $this->migrateFromJson($jsonFile);
            }
        }
        
        if (!empty($errors)) {
            throw new Exception('部分数据表创建失败 (' . implode(', ', $errors) . ')');
        }
        
        return true;
    }
    
    /**
     * 从JSON迁移数据
     */
    private function migrateFromJson($jsonFile) {
        $jsonData = json_decode(file_get_contents($jsonFile), true);
        if (empty($jsonData)) {
            return;
        }
        
        foreach ($jsonData as $clientId => $clientData) {
            // 检查是否已存在
            $exists = $this->fetch("SELECT id FROM {$this->prefix}clients WHERE client_id = ?", [$clientId]);
            if ($exists) {
                continue;
            }
            
            // 插入客户端
            $this->insert($this->prefix . 'clients', [
                'client_id' => $clientId,
                'nickname' => $clientData['nickname'] ?? $clientData['ip'] ?? 'Unknown',
                'ip' => $clientData['ip'] ?? '0.0.0.0',
                'first_visit' => $clientData['last_active'] ?? time(),
                'last_active' => $clientData['last_active'] ?? time()
            ]);
            
            // 插入消息
            if (!empty($clientData['messages'])) {
                foreach ($clientData['messages'] as $msg) {
                    $this->insert($this->prefix . 'messages', [
                        'message_id' => $msg['id'] ?? uniqid(),
                        'client_id' => $clientId,
                        'sender' => $msg['is_admin'] ? 'admin' : 'client',
                        'sender_name' => $msg['nickname'] ?? ($msg['is_admin'] ? 'Admin' : 'Client'),
                        'message' => $msg['message'] ?? '',
                        'image_url' => $msg['image_url'] ?? null,
                        'is_admin' => $msg['is_admin'] ?? 0,
                        'is_read' => $msg['read'] ?? 0,
                        'timestamp' => $msg['timestamp'] ?? time()
                    ]);
                }
            }
        }
        
        // 备份并删除JSON文件
        rename($jsonFile, $jsonFile . '.bak.' . date('Ymd'));
    }
}

// 初始化数据库连接
function getDB() {
    return Database::getInstance();
}
