<?php

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

class Yali_AI_Writer_JobQueueState {

    public static function get_async_waiting_queue_status($job_type, $result) {
        if (!is_array($result) || empty($result['status'])) {
            return null;
        }

        if ($job_type !== 'material_search') {
            return null;
        }

        if ($result['status'] === 'waiting' || $result['status'] === 'waiting_for_browser') {
            return 'waiting_browser';
        }

        return null;
    }
}
