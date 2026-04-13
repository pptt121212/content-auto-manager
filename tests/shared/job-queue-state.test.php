<?php

require_once __DIR__ . '/../../shared/queue/class-job-queue-state.php';

function assertSameValue($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

assertSameValue(
    'waiting_browser',
    Yali_AI_Writer_JobQueueState::get_async_waiting_queue_status('material_search', ['success' => true, 'status' => 'waiting']),
    'material_search waiting result should become waiting_browser'
);

assertSameValue(
    'waiting_browser',
    Yali_AI_Writer_JobQueueState::get_async_waiting_queue_status('material_search', ['success' => true, 'status' => 'waiting_for_browser']),
    'material_search waiting_for_browser result should become waiting_browser'
);

assertSameValue(
    null,
    Yali_AI_Writer_JobQueueState::get_async_waiting_queue_status('material_search', ['success' => true]),
    'material_search without async wait status should not become waiting_browser'
);

assertSameValue(
    null,
    Yali_AI_Writer_JobQueueState::get_async_waiting_queue_status('topic_task', ['success' => true, 'status' => 'waiting']),
    'non-material_search waiting result should not be rewritten by this helper'
);

fwrite(STDOUT, "job-queue-state tests passed\n");
