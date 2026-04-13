<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

$options = array();

function get_option($key, $default = array()) {
    global $options;
    return $options[$key] ?? $default;
}

function update_option($key, $value) {
    global $options;
    $options[$key] = $value;
    return true;
}

require_once __DIR__ . '/../../shared/services/class-extension-task-state-ledger.php';

function assertLedger($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

Yali_AI_Writer_ExtensionTaskStateLedger::ensure_task('task-1', 'knowledge_search', array('topic_id' => 1), 'pending');
$claim = Yali_AI_Writer_ExtensionTaskStateLedger::mark_claimed('task-1', 'knowledge_search', array('topic_id' => 1), 'sidepanel-1', 'claim-1', time() + 60);
assertLedger($claim['applied'] === true, 'claim should be applied before terminal state');

$complete = Yali_AI_Writer_ExtensionTaskStateLedger::mark_terminal('task-1', 'completed', array('answer' => 'ok'));
assertLedger($complete['applied'] === true, 'completion should be applied');

$duplicate = Yali_AI_Writer_ExtensionTaskStateLedger::mark_terminal('task-1', 'cancelled');
assertLedger($duplicate['applied'] === false, 'terminal state must be idempotent');

echo "extension-task-state-ledger tests passed\n";
