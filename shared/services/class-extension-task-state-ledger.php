<?php

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

class Yali_AI_Writer_ExtensionTaskStateLedger {
    const OPTION_KEY = 'cam_extension_task_state_ledger';
    const MAX_HISTORY = 20;

    public static function get_all() {
        $ledger = get_option(self::OPTION_KEY, array());
        return is_array($ledger) ? $ledger : array();
    }

    public static function get($task_id) {
        $ledger = self::get_all();
        return $ledger[$task_id] ?? null;
    }

    public static function ensure_task($task_id, $task_type, $payload = array(), $state = 'pending', $extra = array(), $record_history = true) {
        $ledger = self::get_all();
        $entry = $ledger[$task_id] ?? array(
            'task_id' => $task_id,
            'task_type' => $task_type,
            'payload' => $payload,
            'state' => $state,
            'created_at' => time(),
            'updated_at' => time(),
            'attempt_count' => 0,
            'active_claim' => null,
            'last_result' => null,
            'last_error' => '',
            'terminal_at' => 0,
            'history' => array(),
        );

        if (!empty($task_type)) {
            $entry['task_type'] = $task_type;
        }
        if (!empty($payload)) {
            $entry['payload'] = $payload;
        }

        $entry = array_merge($entry, $extra);
        $entry['updated_at'] = time();
        if ($record_history) {
            $entry = self::append_history($entry, array(
                'event' => 'ensure',
                'state' => $entry['state'],
            ));
        }

        $ledger[$task_id] = $entry;
        update_option(self::OPTION_KEY, $ledger);
        return $entry;
    }

    public static function sync_queue_task($task_id, $task) {
        $state = $task['status'] ?? 'pending';
        $current = self::get($task_id);
        if ($current && ($current['state'] ?? '') === $state && ($current['task_type'] ?? '') === ($task['type'] ?? 'unknown')) {
            return $current;
        }

        return self::ensure_task(
            $task_id,
            $task['type'] ?? 'unknown',
            $task['payload'] ?? array(),
            $state,
            array(
                'queue_status' => $state,
                'notification_count' => intval($task['notification_count'] ?? 0),
                'last_notified_at' => intval($task['last_notified_at'] ?? 0),
            ),
            false
        );
    }

    public static function mark_claimed($task_id, $task_type, $payload, $claimant_id, $claim_token, $expires_at, $extra = array()) {
        $entry = self::ensure_task($task_id, $task_type, $payload);
        if (self::is_terminal_state($entry['state'] ?? '')) {
            return array('applied' => false, 'entry' => $entry, 'reason' => 'terminal');
        }

        $entry['state'] = 'claimed';
        $entry['queue_status'] = 'claimed';
        $entry['attempt_count'] = intval($entry['attempt_count'] ?? 0) + 1;
        $entry['active_claim'] = array(
            'claimant_id' => $claimant_id,
            'claim_token' => $claim_token,
            'claimed_at' => time(),
            'expires_at' => intval($expires_at),
        );
        $entry = array_merge($entry, $extra);
        $entry['updated_at'] = time();
        $entry = self::append_history($entry, array(
            'event' => 'claimed',
            'claimant_id' => $claimant_id,
            'claim_token' => $claim_token,
        ));

        self::persist_entry($task_id, $entry);
        return array('applied' => true, 'entry' => $entry);
    }

    public static function mark_reclaimed_pending($task_id) {
        $entry = self::get($task_id);
        if (!$entry) {
            return null;
        }
        if (self::is_terminal_state($entry['state'] ?? '')) {
            return $entry;
        }

        $entry['state'] = 'pending';
        $entry['queue_status'] = 'pending';
        $entry['active_claim'] = null;
        $entry['updated_at'] = time();
        $entry = self::append_history($entry, array('event' => 'claim_reclaimed', 'state' => 'pending'));
        self::persist_entry($task_id, $entry);
        return $entry;
    }

    public static function mark_terminal($task_id, $state, $result = null, $error = '', $extra = array()) {
        $entry = self::get($task_id);
        if (!$entry) {
            $entry = self::ensure_task($task_id, $extra['task_type'] ?? 'unknown', $extra['payload'] ?? array(), $state);
        }

        if (self::is_terminal_state($entry['state'] ?? '')) {
            return array('applied' => false, 'entry' => $entry, 'reason' => 'terminal');
        }

        $entry['state'] = $state;
        $entry['queue_status'] = $state;
        $entry['active_claim'] = null;
        $entry['updated_at'] = time();
        $entry['terminal_at'] = time();
        if ($result !== null) {
            $entry['last_result'] = $result;
        }
        if ($error !== '') {
            $entry['last_error'] = $error;
        }
        $entry = array_merge($entry, $extra);
        $entry = self::append_history($entry, array(
            'event' => 'terminal',
            'state' => $state,
            'error' => $error,
        ));

        self::persist_entry($task_id, $entry);
        return array('applied' => true, 'entry' => $entry);
    }

    public static function mark_state($task_id, $state, $extra = array()) {
        $entry = self::get($task_id);
        if (!$entry) {
            return null;
        }
        if (self::is_terminal_state($entry['state'] ?? '')) {
            return $entry;
        }

        $entry['state'] = $state;
        $entry['queue_status'] = $state;
        $entry = array_merge($entry, $extra);
        $entry['updated_at'] = time();
        $entry = self::append_history($entry, array('event' => 'state', 'state' => $state));
        self::persist_entry($task_id, $entry);
        return $entry;
    }

    public static function is_terminal_state($state) {
        return in_array($state, array('completed', 'failed', 'cancelled', 'timed_out', 'deleted'), true);
    }

    private static function persist_entry($task_id, $entry) {
        $ledger = self::get_all();
        $ledger[$task_id] = $entry;
        update_option(self::OPTION_KEY, $ledger);
    }

    private static function append_history($entry, $history_item) {
        if (!isset($entry['history']) || !is_array($entry['history'])) {
            $entry['history'] = array();
        }

        $history_item['at'] = time();
        $entry['history'][] = $history_item;
        if (count($entry['history']) > self::MAX_HISTORY) {
            $entry['history'] = array_slice($entry['history'], -self::MAX_HISTORY);
        }

        return $entry;
    }
}
