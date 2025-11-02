<?php
/**
 * 数据库操作包装器
 * 确保统一的数据库读写逻辑和事务管理
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_DatabaseWrapper {
    
    /**
     * 数据库连接
     */
    private $wpdb;
    
    /**
     * 事务堆栈
     */
    private $transaction_stack = array();
    
    /**
     * 查询日志
     */
    private $query_log = array();
    
    /**
     * 构造函数
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }
    
    /**
     * 开始事务
     */
    public function begin_transaction() {
        $transaction_id = uniqid('tx_', true);
        $this->transaction_stack[] = $transaction_id;
        
        if (count($this->transaction_stack) === 1) {
            // 只在最外层事务时实际开始事务
            $this->wpdb->query('START TRANSACTION');
            $this->log_query('START TRANSACTION', array(), 'transaction');
        }
        
        return $transaction_id;
    }
    
    /**
     * 提交事务
     */
    public function commit_transaction($transaction_id = null) {
        if ($transaction_id !== null) {
            // 查找并移除指定的事务
            $index = array_search($transaction_id, $this->transaction_stack);
            if ($index === false) {
                throw new Exception('事务不存在');
            }
            array_splice($this->transaction_stack, $index, 1);
        } else {
            // 提交最外层事务
            if (!empty($this->transaction_stack)) {
                array_pop($this->transaction_stack);
            }
        }
        
        if (empty($this->transaction_stack)) {
            // 只在最外层事务时实际提交
            $this->wpdb->query('COMMIT');
            $this->log_query('COMMIT', array(), 'transaction');
        }
        
        return true;
    }
    
    /**
     * 回滚事务
     */
    public function rollback_transaction($transaction_id = null) {
        if ($transaction_id !== null) {
            // 查找并移除指定的事务
            $index = array_search($transaction_id, $this->transaction_stack);
            if ($index === false) {
                throw new Exception('事务不存在');
            }
            array_splice($this->transaction_stack, $index, 1);
        } else {
            // 回滚最外层事务
            if (!empty($this->transaction_stack)) {
                array_pop($this->transaction_stack);
            }
        }
        
        if (empty($this->transaction_stack)) {
            // 只在最外层事务时实际回滚
            $this->wpdb->query('ROLLBACK');
            $this->log_query('ROLLBACK', array(), 'transaction');
        }
        
        return true;
    }
    
    /**
     * 执行查询
     */
    public function query($sql, $params = array(), $operation = 'read') {
        $start_time = microtime(true);
        
        try {
            if (empty($params)) {
                $result = $this->wpdb->query($sql);
            } else {
                $result = $this->wpdb->query($this->wpdb->prepare($sql, $params));
            }
            
            $execution_time = microtime(true) - $start_time;
            
            // 记录查询日志
            $this->log_query($sql, $params, $operation, $execution_time);
            
            // 检查错误
            if ($result === false) {
                $error = $this->wpdb->last_error;
                throw new Exception("数据库查询失败: {$error}");
            }
            
            return $result;
            
        } catch (Exception $e) {
            $execution_time = microtime(true) - $start_time;
            $this->log_query($sql, $params, $operation, $execution_time, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取单行
     */
    public function get_row($sql, $params = array(), $output_type = OBJECT) {
        $start_time = microtime(true);
        
        try {
            if (empty($params)) {
                $result = $this->wpdb->get_row($sql, $output_type);
            } else {
                $result = $this->wpdb->get_row($this->wpdb->prepare($sql, $params), $output_type);
            }
            
            $execution_time = microtime(true) - $start_time;
            $this->log_query($sql, $params, 'read', $execution_time);
            
            return $result;
            
        } catch (Exception $e) {
            $execution_time = microtime(true) - $start_time;
            $this->log_query($sql, $params, 'read', $execution_time, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取多行
     */
    public function get_results($sql, $params = array(), $output_type = OBJECT) {
        $start_time = microtime(true);
        
        try {
            if (empty($params)) {
                $result = $this->wpdb->get_results($sql, $output_type);
            } else {
                $result = $this->wpdb->get_results($this->wpdb->prepare($sql, $params), $output_type);
            }
            
            $execution_time = microtime(true) - $start_time;
            $this->log_query($sql, $params, 'read', $execution_time);
            
            return $result;
            
        } catch (Exception $e) {
            $execution_time = microtime(true) - $start_time;
            $this->log_query($sql, $params, 'read', $execution_time, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取单个值
     */
    public function get_var($sql, $params = array()) {
        $start_time = microtime(true);
        
        try {
            if (empty($params)) {
                $result = $this->wpdb->get_var($sql);
            } else {
                $result = $this->wpdb->get_var($this->wpdb->prepare($sql, $params));
            }
            
            $execution_time = microtime(true) - $start_time;
            $this->log_query($sql, $params, 'read', $execution_time);
            
            return $result;
            
        } catch (Exception $e) {
            $execution_time = microtime(true) - $start_time;
            $this->log_query($sql, $params, 'read', $execution_time, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 插入数据
     */
    public function insert($table, $data, $format = null) {
        $start_time = microtime(true);
        
        try {
            $result = $this->wpdb->insert($table, $data, $format);
            
            $execution_time = microtime(true) - $start_time;
            $this->log_query("INSERT INTO {$table}", $data, 'write', $execution_time);
            
            if ($result === false) {
                $error = $this->wpdb->last_error;
                throw new Exception("数据插入失败: {$error}");
            }
            
            return $this->wpdb->insert_id;
            
        } catch (Exception $e) {
            $execution_time = microtime(true) - $start_time;
            $this->log_query("INSERT INTO {$table}", $data, 'write', $execution_time, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 更新数据
     */
    public function update($table, $data, $where, $format = null, $where_format = null) {
        $start_time = microtime(true);
        
        try {
            $result = $this->wpdb->update($table, $data, $where, $format, $where_format);
            
            $execution_time = microtime(true) - $start_time;
            $this->log_query("UPDATE {$table}", array_merge($data, $where), 'write', $execution_time);
            
            if ($result === false) {
                $error = $this->wpdb->last_error;
                throw new Exception("数据更新失败: {$error}");
            }
            
            return $result;
            
        } catch (Exception $e) {
            $execution_time = microtime(true) - $start_time;
            $this->log_query("UPDATE {$table}", array_merge($data, $where), 'write', $execution_time, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 删除数据
     */
    public function delete($table, $where, $where_format = null) {
        $start_time = microtime(true);
        
        try {
            $result = $this->wpdb->delete($table, $where, $where_format);
            
            $execution_time = microtime(true) - $start_time;
            $this->log_query("DELETE FROM {$table}", $where, 'write', $execution_time);
            
            if ($result === false) {
                $error = $this->wpdb->last_error;
                throw new Exception("数据删除失败: {$error}");
            }
            
            return $result;
            
        } catch (Exception $e) {
            $execution_time = microtime(true) - $start_time;
            $this->log_query("DELETE FROM {$table}", $where, 'write', $execution_time, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 安全更新任务状态
     */
    public function update_task_status($task_id, $new_status, $old_status = null, $error_message = null) {
        $transaction_id = $this->begin_transaction();
        
        try {
            // 验证任务存在
            $task = $this->get_row(
                "SELECT * FROM {$this->wpdb->prefix}content_auto_topic_tasks WHERE id = %d",
                array($task_id)
            );
            
            if (!$task) {
                throw new Exception("任务不存在: {$task_id}");
            }
            
            // 如果需要，验证旧状态
            if ($old_status !== null && $task->status !== $old_status) {
                throw new Exception("任务状态不匹配: 预期 {$old_status}, 实际 {$task->status}");
            }
            
            // 准备更新数据
            $update_data = array(
                'status' => $new_status,
                'updated_at' => current_time('mysql')
            );
            
            if ($error_message !== null) {
                $update_data['error_message'] = $error_message;
            }
            
            // 执行更新
            $result = $this->update(
                "{$this->wpdb->prefix}content_auto_topic_tasks",
                $update_data,
                array('id' => $task_id)
            );
            
            // 触发状态变更钩子
            do_action('content_auto_task_status_changed', $task_id, $task->status, $new_status);
            
            $this->commit_transaction($transaction_id);
            
            return $result;
            
        } catch (Exception $e) {
            $this->rollback_transaction($transaction_id);
            throw $e;
        }
    }
    
    /**
     * 安全更新任务进度
     */
    public function update_task_progress($task_id, $progress_data) {
        $transaction_id = $this->begin_transaction();
        
        try {
            // 验证任务存在
            $task = $this->get_row(
                "SELECT * FROM {$this->wpdb->prefix}content_auto_topic_tasks WHERE id = %d",
                array($task_id)
            );
            
            if (!$task) {
                throw new Exception("任务不存在: {$task_id}");
            }
            
            // 验证进度数据
            $validator = new ContentAuto_DataValidator();
            $errors = $validator->validate_field('progress', $progress_data, $validator->validation_rules['progress']);
            
            if (!empty($errors)) {
                throw new Exception("进度数据验证失败: " . implode(', ', $errors));
            }
            
            // 准备进度数据
            $progress_record = array(
                'task_id' => $task_id,
                'current_item' => $progress_data['current_item'],
                'total_items' => $progress_data['total_items'],
                'generated_topics' => $progress_data['generated_topics'],
                'expected_topics' => $progress_data['expected_topics'],
                'progress_percentage' => $progress_data['progress_percentage'],
                'updated_at' => current_time('mysql')
            );
            
            // 更新或插入进度记录
            $existing_progress = $this->get_row(
                "SELECT * FROM {$this->wpdb->prefix}content_auto_task_progress WHERE task_id = %d",
                array($task_id)
            );
            
            if ($existing_progress) {
                $this->update(
                    "{$this->wpdb->prefix}content_auto_task_progress",
                    $progress_record,
                    array('task_id' => $task_id)
                );
            } else {
                $this->insert(
                    "{$this->wpdb->prefix}content_auto_task_progress",
                    $progress_record
                );
            }
            
            // 触发进度更新钩子
            do_action('content_auto_task_progress_updated', $task_id, $progress_data);
            
            $this->commit_transaction($transaction_id);
            
            return true;
            
        } catch (Exception $e) {
            $this->rollback_transaction($transaction_id);
            throw $e;
        }
    }
    
    /**
     * 创建任务（事务安全）
     */
    public function create_task($task_data) {
        $transaction_id = $this->begin_transaction();
        
        try {
            // 验证任务数据
            $validator = new ContentAuto_DataValidator();
            $errors = $validator->validate_task_data($task_data);
            
            if (!empty($errors)) {
                throw new Exception("任务数据验证失败: " . implode(', ', $errors));
            }
            
            // 准备任务数据
            $task_record = array(
                'topic_task_id' => $task_data['topic_task_id'],
                'rule_id' => $task_data['rule_id'],
                'topic_count_per_item' => $task_data['topic_count_per_item'],
                'total_expected_topics' => $task_data['total_expected_topics'],
                'status' => $task_data['status'],
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            );
            
            // 插入任务
            $task_id = $this->insert(
                "{$this->wpdb->prefix}content_auto_topic_tasks",
                $task_record
            );
            
            // 触发任务创建钩子
            do_action('content_auto_task_created', $task_id, $task_data);
            
            $this->commit_transaction($transaction_id);
            
            return $task_id;
            
        } catch (Exception $e) {
            $this->rollback_transaction($transaction_id);
            throw $e;
        }
    }
    
    /**
     * 记录查询日志
     */
    private function log_query($sql, $params, $operation, $execution_time = 0, $error = null) {
        $log_entry = array(
            'sql' => $sql,
            'params' => $params,
            'operation' => $operation,
            'execution_time' => $execution_time,
            'error' => $error,
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'transaction_depth' => count($this->transaction_stack)
        );
        
        $this->query_log[] = $log_entry;
        
        // 如果查询时间过长，记录警告
        
        // 如果有错误，记录错误
    }
    
    /**
     * 获取查询日志
     */
    public function get_query_log() {
        return $this->query_log;
    }
    
    /**
     * 清空查询日志
     */
    public function clear_query_log() {
        $this->query_log = array();
    }
    
    /**
     * 获取事务状态
     */
    public function get_transaction_status() {
        return array(
            'active' => !empty($this->transaction_stack),
            'depth' => count($this->transaction_stack),
            'transactions' => $this->transaction_stack
        );
    }
    
    /**
     * 锁定任务
     */
    public function lock_task($task_id) {
        $lock_key = "content_auto_task_{$task_id}";
        $lock_value = uniqid();
        
        // 尝试获取锁（30秒超时）
        $result = $this->get_var(
            "SELECT GET_LOCK(%s, 30) as lock_result",
            array($lock_key)
        );
        
        if ($result == 1) {
            return $lock_value;
        }
        
        throw new Exception("无法获取任务锁: {$task_id}");
    }
    
    /**
     * 释放任务锁
     */
    public function unlock_task($task_id, $lock_value) {
        $lock_key = "content_auto_task_{$task_id}";
        
        $result = $this->get_var(
            "SELECT RELEASE_LOCK(%s) as release_result",
            array($lock_key)
        );
        
        return $result == 1;
    }
    
    /**
     * 获取数据库统计信息
     */
    public function get_database_stats() {
        $stats = array(
            'query_count' => count($this->query_log),
            'slow_queries' => 0,
            'failed_queries' => 0,
            'average_execution_time' => 0,
            'total_execution_time' => 0
        );
        
        if (!empty($this->query_log)) {
            $total_time = 0;
            $slow_count = 0;
            $failed_count = 0;
            
            foreach ($this->query_log as $log) {
                $total_time += $log['execution_time'];
                if ($log['execution_time'] > 1.0) {
                    $slow_count++;
                }
                if ($log['error']) {
                    $failed_count++;
                }
            }
            
            $stats['slow_queries'] = $slow_count;
            $stats['failed_queries'] = $failed_count;
            $stats['total_execution_time'] = $total_time;
            $stats['average_execution_time'] = $total_time / count($this->query_log);
        }
        
        return $stats;
    }
}