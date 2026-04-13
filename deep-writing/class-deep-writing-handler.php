<?php
/**
 * Deep Writing Handler
 * 
 * Handles cross-environment deep-writing tasks:
 * - Creates tasks via plugin→extension channel
 * - Manages topic shadow state (queued/used/unused)
 * - Handles completion callbacks (creates drafts)
 * - Handles release/delete callbacks (restores status)
 * 
 * This is a lightweight implementation that does NOT use the article task queue.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-extension-task-state-ledger.php';

/**
 * Deep Writing Handler Class
 */
class Yali_AI_Writer_DeepWritingHandler {

    // Task type constant for the extension queue
    const TASK_TYPE_DEEP_WRITING = 'deep_writing';

    // Option keys for shadow state (lightweight tracking)
    const OPTION_KEY_SHADOW_STATE = 'yali_deep_writing_shadow_state';
    const OPTION_KEY_TOPIC_TASK_MAP = 'yali_deep_writing_topic_task_map';
    const OPTION_KEY_TASK_QUEUE = 'cam_extension_task_queue';

    // Processing lock TTL in seconds (5 minutes) - prevents stale locks from crashed callbacks
    const PROCESSING_LOCK_TTL = 300;

    /**
     * Initialize the handler
     */
    public static function init() {
        // Hook into the deep writing initiation action from form-handlers.php
        add_action('yali_ai_writer_deep_writing_initiated', array(__CLASS__, 'handle_deep_writing_initiated'), 10, 1);
        
        // Hook into task completion from extension
        add_action('cam_extension_task_completed', array(__CLASS__, 'handle_task_completed'), 10, 2);
        
        // Register REST endpoints for release/delete callbacks
        add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
    }

    /**
     * Handle deep writing initiation
     * 
     * @param array $topic_ids Array of topic IDs to process
     */
    public static function handle_deep_writing_initiated($topic_ids) {
        if (empty($topic_ids) || !is_array($topic_ids)) {
            error_log('[DeepWriting] No topic IDs provided');
            return;
        }

        require_once YALI_AI_WRITER_PLUGIN_DIR . 'rest-api/controllers/class-base-controller.php';
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'rest-api/controllers/class-task-controller.php';
        $task_controller = new ContentAutoManager\RestApi\Controllers\Task_Controller('content-auto-manager/v1');

        global $wpdb;
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';

        foreach ($topic_ids as $topic_id) {
            $topic_id = intval($topic_id);
            if ($topic_id <= 0) {
                continue;
            }

            // Get topic data with all fields needed for WritingRequest
            $topic = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$topics_table} WHERE id = %d",
                $topic_id
            ), ARRAY_A);

            if (!$topic) {
                error_log("[DeepWriting] Topic not found: {$topic_id}");
                continue;
            }

            // Only process unused topics
            if ($topic['status'] !== YALI_AI_WRITER_TOPIC_UNUSED) {
                error_log("[DeepWriting] Topic {$topic_id} is not in 'unused' status (current: {$topic['status']})");
                continue;
            }

            // Get publish rules for additional fields (target_length, language, etc.)
            $publish_rules = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}yali_ai_writer_publish_rules WHERE id = 1", ARRAY_A);

            require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-reference-material-service.php';
            $reference_service = new Yali_AI_Writer_ReferenceMaterialService();
            $resolved_reference_material = $reference_service->get_reference_material($topic, $publish_rules);

            // Parse SEO keywords from comma-separated string to array
            $keywords = array();
            if (!empty($topic['seo_keywords'])) {
                $keywords = array_map('trim', explode(',', $topic['seo_keywords']));
                $keywords = array_filter($keywords);
            }

            // Create a task for this specific topic with enriched payload
            $task_payload = array(
                // Core identification
                'topic_id' => $topic_id,
                'topic_title' => $topic['title'],
                'matched_category' => $topic['matched_category'] ?? '',
                
                // WritingRequest fields (required for extension to build WritingRequest)
                'topic' => $topic['title'],                          // Maps to WritingRequest.topic
                'keywords' => $keywords,                             // Maps to WritingRequest.keywords (array)
                'seo_keywords' => $topic['seo_keywords'] ?? '',      // Raw SEO keywords string
                'publish_language' => $publish_rules['publish_language'] ?? 'zh-CN',  // Maps to WritingRequest.language
                'language' => $publish_rules['publish_language'] ?? 'zh-CN',          // Alias for compatibility
                'target_length' => $publish_rules['target_length'] ?? '不少于2000字',   // Maps to WritingRequest.targetLength
                'knowledge_depth' => $publish_rules['knowledge_depth'] ?? '未设置',     // Maps to WritingRequest.knowledgeDepth
                'reader_role' => $publish_rules['reader_role'] ?? '未设置',             // Maps to WritingRequest.readerRole
                'source_angle' => $topic['source_angle'] ?? '',                         // Maps to WritingRequest.sourceAngle
                'user_value' => $topic['user_value'] ?? '',                             // Maps to WritingRequest.userValue
                
                'reference_material' => $resolved_reference_material,
                
                // Callback endpoints
                'callback_url' => rest_url('content-auto-manager/v1/deep-writing/callback'),
                'release_url' => rest_url('content-auto-manager/v1/deep-writing/release'),
                
                // Ordering metadata for queue preservation
                'order_weight' => intval($topic['priority_score'] ?? 3)
            );

            // Use the task controller to add to queue
            $task_id = $task_controller->add_task(self::TASK_TYPE_DEEP_WRITING, $task_payload);

        if ($task_id) {
                Yali_AI_Writer_ExtensionTaskStateLedger::ensure_task($task_id, self::TASK_TYPE_DEEP_WRITING, $task_payload, 'pending');
                // Update topic status to queued
                $updated = $wpdb->update(
                    $topics_table,
                    array('status' => YALI_AI_WRITER_TOPIC_QUEUED),
                    array('id' => $topic_id)
                );

                if ($updated !== false) {
                    // Store shadow state and task mapping
                    self::set_shadow_state($topic_id, 'queued', $task_id);
                    self::set_topic_task_mapping($topic_id, $task_id);
                    
                    error_log("[DeepWriting] Created task {$task_id} for topic {$topic_id}");
                } else {
                    error_log("[DeepWriting] Failed to update topic status for {$topic_id}");
                }
            } else {
                error_log("[DeepWriting] Failed to create task for topic {$topic_id}");
            }
        }
    }

    /**
     * Handle task completion from extension
     *
     * @param string $task_id The task ID
     * @param array $result The task result data
     * @return array Structured result with success status and details
     */
    public static function handle_task_completed($task_id, $result) {
        // Check if this is a deep writing task
        $task = self::get_task_from_queue($task_id);
        if (!$task || $task['type'] !== self::TASK_TYPE_DEEP_WRITING) {
            return array('success' => false, 'error' => 'Not a deep writing task', 'handled' => false, 'retryable' => false);
        }

        error_log("[DeepWriting] Handling completion for task {$task_id}");

        $payload = $task['payload'] ?? array();
        $topic_id = intval($payload['topic_id'] ?? 0);

        if ($topic_id <= 0) {
            error_log("[DeepWriting] Invalid topic ID in task payload");
            return array('success' => false, 'error' => 'Invalid topic ID', 'handled' => true, 'retryable' => false);
        }

        // Idempotency check: skip if already processed successfully
        if (self::is_task_processed($task_id, $topic_id)) {
            error_log("[DeepWriting] Task {$task_id} for topic {$topic_id} already processed, skipping");
            $existing_post_id = self::get_topic_post_id($topic_id);
            return array(
                'success' => true,
                'post_id' => $existing_post_id,
                'already_processed' => true,
                'handled' => true,
                'retryable' => false
            );
        }

        // Acquire processing lock with TTL (prevents stale locks from crashed callbacks)
        $lock_result = self::acquire_processing_lock($task_id, $topic_id);
        if (!$lock_result['acquired']) {
            error_log("[DeepWriting] Could not acquire processing lock for task {$task_id}: {$lock_result['error']}");
            return array(
                'success' => false,
                'error' => $lock_result['error'],
                'handled' => true,
                'retryable' => true // Stale lock cleared, retry may succeed
            );
        }

        // Verify the topic is still in queued status
        $current_status = self::get_topic_status($topic_id);
        if ($current_status !== YALI_AI_WRITER_TOPIC_QUEUED) {
            error_log("[DeepWriting] Topic {$topic_id} is not in queued status (current: {$current_status}), skipping");
            self::clear_task_processing($task_id, $topic_id);
            return array(
                'success' => false,
                'error' => "Topic not in queued status (current: {$current_status})",
                'handled' => true,
                'retryable' => false // Permanent state mismatch, don't retry
            );
        }

        // Attempt draft sync with structured result
        $sync_result = self::attempt_draft_sync($topic_id, $result, $payload);

        if (!$sync_result['success']) {
            // FAILURE FLOW: Draft sync failed - keep topic occupied for retry
            $error = $sync_result['error'];
            $is_retryable = $sync_result['retryable'] ?? true;

            error_log("[DeepWriting] Draft sync failed for topic {$topic_id}: {$error}");

            // IMPORTANT: Do NOT restore topic to unused - keep it occupied (queued)
            // Do NOT clear topic-task mapping - preserves association for retry
            // Do NOT mark topic as used - sync hasn't succeeded yet

            // Record lightweight shadow-state failure marker for retry semantics
            self::record_sync_failure($topic_id, $task_id, $error);

            // Clear processing mark to allow future retry attempts
            self::clear_task_processing($task_id, $topic_id);

            return array(
                'success' => false,
                'error' => $error,
                'retryable' => $is_retryable,
                'handled' => true
            );
        }

        // SUCCESS FLOW: Draft created or already exists
        $post_id = $sync_result['post_id'];

        $article_saved = self::save_article_record($topic_id, $post_id, $result);
        if (!$article_saved) {
            error_log("[DeepWriting] CRITICAL: Draft {$post_id} article record save failed for topic {$topic_id}");
            if (empty($sync_result['already_exists'])) {
                self::cleanup_orphaned_draft($post_id);
            }
            self::record_sync_failure($topic_id, $task_id, 'Article record save failed after draft creation');
            self::clear_task_processing($task_id, $topic_id);

            return array(
                'success' => false,
                'error' => empty($sync_result['already_exists'])
                    ? 'Draft created but article record save failed - cleaned up orphaned draft'
                    : 'Existing draft found but article record save failed',
                'retryable' => true,
                'handled' => true
            );
        }

        // CRITICAL: Update topic status to used - must succeed for true success
        $topic_updated = self::mark_topic_used($topic_id);

        if (!$topic_updated) {
            // Topic status update failed - this is a critical inconsistency
            error_log("[DeepWriting] CRITICAL: Draft {$post_id} created but topic {$topic_id} status update failed");

            if (empty($sync_result['already_exists'])) {
                self::cleanup_orphaned_draft($post_id);
                self::delete_article_record($topic_id, $post_id);
            }

            // Record failure for retry
            self::record_sync_failure($topic_id, $task_id, 'Topic status update failed after draft creation');

            // Clear processing mark to allow retry
            self::clear_task_processing($task_id, $topic_id);

            return array(
                'success' => false,
                'error' => 'Draft created but topic status update failed - cleaned up orphaned draft',
                'retryable' => true, // Retryable - transient DB issue
                'handled' => true
            );
        }

        // Mark as processed for idempotency
        self::mark_task_processed($task_id, $topic_id, $post_id);

        // Update queue item status to completed
        self::update_queue_status($task_id, 'completed');

        // Clear shadow state and topic-task mapping
        self::clear_shadow_state($topic_id);
        self::clear_topic_task_mapping($topic_id);

        error_log("[DeepWriting] Successfully synced draft post {$post_id} for topic {$topic_id}");

        return array(
            'success' => true,
            'post_id' => $post_id,
            'already_exists' => $sync_result['already_exists'],
            'handled' => true,
            'retryable' => false
        );
    }

    /**
     * Register REST API routes for callbacks
     */
    public static function register_rest_routes() {
        register_rest_route('content-auto-manager/v1', '/deep-writing/callback', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_callback_completion'),
            'permission_callback' => array(__CLASS__, 'check_callback_permission'),
        ));

        register_rest_route('content-auto-manager/v1', '/deep-writing/release', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_callback_release'),
            'permission_callback' => array(__CLASS__, 'check_callback_permission'),
        ));
    }

    /**
     * Check callback permission
     */
    public static function check_callback_permission() {
        // Check for valid API key or admin permission
        $api_key = $_SERVER['HTTP_X_CAM_API_KEY'] ?? '';
        $valid_key = get_option('cam_extension_api_key', '');

        if (!empty($api_key) && !empty($valid_key) && $api_key === $valid_key) {
            return true;
        }

        return current_user_can('manage_options');
    }

    /**
     * REST callback for task completion
     *
     * Returns explicit JSON result describing whether WordPress draft sync succeeded.
     * Extension can retry based on the response.
     *
     * Success response: { "success": true, "post_id": 123, "already_exists": false }
     * Failure response: { "success": false, "error": "...", "retryable": true }
     */
    public static function rest_callback_completion($request) {
        $params = $request->get_json_params();
        $task_id = sanitize_text_field($params['task_id'] ?? '');
        $result = $params['result'] ?? array();
        $claimant_id = sanitize_text_field($params['claimant_id'] ?? '');
        $claim_token = sanitize_text_field($params['claim_token'] ?? '');

        if (empty($task_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Missing task_id',
                'retryable' => false
            ), 400);
        }

        $ledger_entry = Yali_AI_Writer_ExtensionTaskStateLedger::get($task_id);
        if ($ledger_entry && Yali_AI_Writer_ExtensionTaskStateLedger::is_terminal_state($ledger_entry['state'] ?? '')) {
            if (($ledger_entry['state'] ?? '') === 'completed') {
                return new WP_REST_Response(array('success' => true, 'already_exists' => true), 200);
            }

            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Task already reached terminal state: ' . ($ledger_entry['state'] ?? 'unknown'),
                'retryable' => false
            ), 200);
        }

        if ($ledger_entry) {
            $active_claim = $ledger_entry['active_claim'] ?? array();
            if (!empty($active_claim)) {
                $claim_matches = ($active_claim['claimant_id'] ?? '') === $claimant_id
                    && ($active_claim['claim_token'] ?? '') === $claim_token;

                if (!$claim_matches) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'error' => 'Claim mismatch for deep writing task',
                        'retryable' => false
                    ), 200);
                }
            }
        }

        try {
            $handler_result = self::handle_task_completed($task_id, $result);

            if ($handler_result['success']) {
                Yali_AI_Writer_ExtensionTaskStateLedger::mark_terminal($task_id, 'completed', $result, '');
            } else {
                Yali_AI_Writer_ExtensionTaskStateLedger::mark_state($task_id, 'claimed', array(
                    'last_error' => $handler_result['error'] ?? 'deep writing sync failed'
                ));
            }

            if ($handler_result['success']) {
                $response_data = array(
                    'success' => true,
                    'post_id' => $handler_result['post_id'],
                    'already_exists' => $handler_result['already_exists'] ?? false
                );
                $status_code = 200;
            } else {
                $response_data = array(
                    'success' => false,
                    'error' => $handler_result['error'] ?? 'Unknown error during draft sync',
                    'retryable' => $handler_result['retryable'] ?? true
                );
                $status_code = 200;
            }

            return new WP_REST_Response($response_data, $status_code);
        } catch (\Throwable $e) {
            $task = self::get_task_from_queue($task_id);
            $payload = $task['payload'] ?? array();
            $topic_id = intval($payload['topic_id'] ?? 0);

            if ($topic_id > 0) {
                self::record_sync_failure($topic_id, $task_id, 'Deep writing callback exception: ' . $e->getMessage());
                self::clear_task_processing($task_id, $topic_id);
            }

            error_log('[DeepWriting] Callback exception for task ' . $task_id . ': ' . $e->getMessage());

            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Deep writing callback exception: ' . $e->getMessage(),
                'retryable' => false,
            ), 200);
        }
    }

    /**
     * REST callback for task release/delete
     */
    public static function rest_callback_release($request) {
        $params = $request->get_json_params();
        $task_id = sanitize_text_field($params['task_id'] ?? '');
        $reason = sanitize_text_field($params['reason'] ?? 'unknown');
        $claimant_id = sanitize_text_field($params['claimant_id'] ?? '');
        $claim_token = sanitize_text_field($params['claim_token'] ?? '');

        if (empty($task_id)) {
            return new WP_REST_Response(array('error' => 'Missing task_id'), 400);
        }

        $ledger_entry = Yali_AI_Writer_ExtensionTaskStateLedger::get($task_id);
        if ($ledger_entry && Yali_AI_Writer_ExtensionTaskStateLedger::is_terminal_state($ledger_entry['state'] ?? '')) {
            return new WP_REST_Response(array(
                'success' => true,
                'status_restored' => false,
                'skipped' => true,
                'reason' => 'already_terminal'
            ), 200);
        }

        if ($ledger_entry) {
            $active_claim = $ledger_entry['active_claim'] ?? array();
            if (!empty($active_claim)) {
                $claim_matches = ($active_claim['claimant_id'] ?? '') === $claimant_id
                    && ($active_claim['claim_token'] ?? '') === $claim_token;
                if (!$claim_matches) {
                    return new WP_REST_Response(array(
                        'success' => true,
                        'status_restored' => false,
                        'skipped' => true,
                        'reason' => 'claim_mismatch'
                    ), 200);
                }
            }
        }

        // Find the topic associated with this task
        $topic_id = self::get_topic_id_by_task($task_id);
        
        if ($topic_id) {
            error_log("[DeepWriting] Releasing task {$task_id} for topic {$topic_id}, reason: {$reason}");

            // Restore topic status to unused
            self::restore_topic_status($topic_id);

            // Update queue item status so it doesn't reappear in pending
            self::update_queue_status($task_id, 'cancelled');
            Yali_AI_Writer_ExtensionTaskStateLedger::mark_terminal($task_id, 'cancelled', null, $reason);

            // Clean up shadow state
            self::clear_shadow_state($topic_id);
            self::clear_topic_task_mapping($topic_id);

            return new WP_REST_Response(array(
                'success' => true,
                'topic_id' => $topic_id,
                'status_restored' => true
            ), 200);
        } else {
            error_log("[DeepWriting] Could not find topic for task {$task_id}");
            Yali_AI_Writer_ExtensionTaskStateLedger::mark_terminal($task_id, 'cancelled', null, 'topic_not_found:' . $reason);
            return new WP_REST_Response(array(
                'success' => true,
                'status_restored' => false,
                'message' => 'Topic not found, but acknowledged'
            ), 200);
        }
    }

    /**
     * Create a WordPress draft post
     * 
     * @param int $topic_id The topic ID
     * @param string $title The post title
     * @param string $content The post content
     * @param string $featured_image Optional featured image URL
     * @param array $payload The original task payload
     * @return int|false The post ID or false on failure
     */
    private static function create_draft_post($topic_id, $title, $content, $featured_image, $payload) {
        // Get matched category from payload or topic
        $matched_category = $payload['matched_category'] ?? '';
        $html_content = self::prepare_post_content($content);
        
        // Get publish rules for default settings
        global $wpdb;
        $publish_rules = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}yali_ai_writer_publish_rules WHERE id = 1", ARRAY_A);
        
        // Prepare post data
        $post_data = array(
            'post_title'   => sanitize_text_field($title),
            'post_content' => $html_content,
            'post_status'  => 'draft',
            'post_type'    => 'post',
            'post_author'  => isset($publish_rules['author_id']) ? intval($publish_rules['author_id']) : get_current_user_id()
        );

        // Insert the post
        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            error_log("[DeepWriting] Failed to create post: " . $post_id->get_error_message());
            return false;
        }

        // Set categories using only the topic's matched_category (no fallback)
        $category_ids = self::resolve_category_ids($matched_category);
        if (!empty($category_ids)) {
            wp_set_post_categories($post_id, $category_ids);
        }

        // Handle featured image if provided (optional enhancement)
        if (!empty($featured_image)) {
            // Featured image is optional - don't fail if it can't be set
            self::attach_featured_image($post_id, $featured_image);
        }

        self::attach_inline_images_to_post($post_id, $html_content);

        // Store metadata linking to topic
        update_post_meta($post_id, '_yali_deep_writing_topic_id', $topic_id);
        update_post_meta($post_id, '_yali_deep_writing_source', 'extension');

        return $post_id;
    }

    /**
     * Resolve category IDs from matched category name
     *
     * @param string $matched_category The category name
     * @return array Array of category IDs (empty if no match)
     */
    private static function resolve_category_ids($matched_category) {
        $category_ids = array();

        if (!empty($matched_category)) {
            // Try to find category by name
            $category = get_term_by('name', $matched_category, 'category');
            if (!$category) {
                // Try by slug
                $category = get_term_by('slug', sanitize_title($matched_category), 'category');
            }
            if ($category && !is_wp_error($category)) {
                $category_ids[] = $category->term_id;
            }
        }

        return $category_ids;
    }

    private static function prepare_post_content($content) {
        if (!class_exists('Yali_AI_Writer_ContentFilter')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/content-processing/class-content-filter.php';
        }

        if (!class_exists('Yali_AI_Writer_MarkdownConverter')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/content-processing/class-markdown-converter.php';
        }

        $content_filter = new Yali_AI_Writer_ContentFilter();
        $filtered_content = $content_filter->filter_content($content);

        $markdown_converter = new Yali_AI_Writer_MarkdownConverter();
        return $markdown_converter->markdown_to_html($filtered_content);
    }

    /**
     * Attempt to sync draft to WordPress
     *
     * Creates a WordPress draft post from deep-writing result.
     * Returns structured result array for retry semantics.
     *
     * @param int $topic_id The topic ID
     * @param array $result The task result data containing title, content, featured_image
     * @param array $payload The original task payload
     * @return array Structured result with keys:
     *   - success (bool): Whether draft sync succeeded
     *   - post_id (int|null): The created post ID on success, null on failure
     *   - error (string|null): Error message on failure, null on success
     *   - already_exists (bool): True if draft already exists (idempotency)
     *   - retryable (bool): Whether this error is retryable
     */
    private static function attempt_draft_sync($topic_id, $result, $payload) {
        $title = $result['title'] ?? '';
        $content = $result['content'] ?? '';
        $featured_image = $result['featured_image'] ?? '';

        // Minimal-field acceptance: title is the only hard requirement
        if (empty($title)) {
            return array(
                'success' => false,
                'post_id' => null,
                'error' => 'Missing required field: title',
                'already_exists' => false,
                'retryable' => false // Permanent error - won't fix on retry
            );
        }

        // Content can be empty - WordPress accepts empty content drafts
        // This supports minimal-field acceptance semantics

        $existing_post_id = self::get_topic_post_id($topic_id);
        if ($existing_post_id) {
            error_log("[DeepWriting] Draft already exists for topic {$topic_id} (post_id: {$existing_post_id})");
            return array(
                'success' => true,
                'post_id' => $existing_post_id,
                'error' => null,
                'already_exists' => true,
                'retryable' => false
            );
        }

        // Create WordPress draft
        $post_id = self::create_draft_post($topic_id, $title, $content, $featured_image, $payload);

        if ($post_id) {
            return array(
                'success' => true,
                'post_id' => $post_id,
                'error' => null,
                'already_exists' => false,
                'retryable' => false
            );
        } else {
            // Draft creation failed - this could be transient (DB issues, storage, etc.)
            return array(
                'success' => false,
                'post_id' => null,
                'error' => 'Failed to create WordPress draft post',
                'already_exists' => false,
                'retryable' => true // Transient error - may succeed on retry
            );
        }
    }

    /**
     * Attach featured image to post (optional, non-blocking)
     *
     * Supports:
     * - attachment_id: Existing attachment ID to set as featured
     * - url: URL to download and set as featured
     *
     * @param int $post_id The post ID
     * @param mixed $featured_image Attachment ID (int) or image URL (string)
     * @return bool True if successful, false otherwise
     */
    private static function attach_featured_image($post_id, $featured_image) {
        // Handle attachment ID directly
        if (is_numeric($featured_image)) {
            $attachment_id = intval($featured_image);
            if (wp_attachment_is_image($attachment_id)) {
                $result = set_post_thumbnail($post_id, $attachment_id);
                if ($result) {
                    self::associate_attachment_with_post($attachment_id, $post_id);
                    error_log("[DeepWriting] Set featured image (attachment_id: {$attachment_id}) for post {$post_id}");
                }
                return $result;
            }
            error_log("[DeepWriting] Attachment {$attachment_id} is not a valid image for post {$post_id}");
            return false;
        }

        // Handle URL - download and set as featured
        if (is_string($featured_image) && !empty($featured_image)) {
            try {
                $existing_attachment_id = self::resolve_attachment_id_from_url($featured_image);
                if ($existing_attachment_id && wp_attachment_is_image($existing_attachment_id)) {
                    $result = set_post_thumbnail($post_id, $existing_attachment_id);
                    if ($result) {
                        self::associate_attachment_with_post($existing_attachment_id, $post_id);
                        error_log("[DeepWriting] Reused existing featured image attachment {$existing_attachment_id} for post {$post_id}");
                    }
                    return $result;
                }

                $attachment_id = self::download_and_attach_image($post_id, $featured_image);
                if ($attachment_id && !is_wp_error($attachment_id)) {
                    $result = set_post_thumbnail($post_id, $attachment_id);
                    if ($result) {
                        self::associate_attachment_with_post($attachment_id, $post_id);
                        error_log("[DeepWriting] Downloaded and set featured image for post {$post_id}");
                    }
                    return $result;
                }
                error_log("[DeepWriting] Failed to download featured image for post {$post_id}: " . (is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'unknown error'));
            } catch (\Throwable $e) {
                error_log("[DeepWriting] Featured image attachment threw for post {$post_id}: " . $e->getMessage());
            }
        }

        return false;
    }

    private static function attach_inline_images_to_post($post_id, $html_content) {
        if (empty($post_id) || empty($html_content) || !function_exists('wp_update_post')) {
            return;
        }

        if (!preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html_content, $matches)) {
            return;
        }

        $image_urls = array_unique(array_filter($matches[1] ?? array()));
        foreach ($image_urls as $image_url) {
            $attachment_id = self::resolve_attachment_id_from_url($image_url);
            if (!$attachment_id) {
                continue;
            }

            wp_update_post(array(
                'ID' => $attachment_id,
                'post_parent' => $post_id,
            ));
            update_post_meta($attachment_id, '_source_post_id', $post_id);
        }
    }

    private static function associate_attachment_with_post($attachment_id, $post_id) {
        if (empty($attachment_id) || empty($post_id) || !function_exists('wp_update_post')) {
            return;
        }

        wp_update_post(array(
            'ID' => $attachment_id,
            'post_parent' => $post_id,
        ));
        update_post_meta($attachment_id, '_source_post_id', $post_id);
    }

    private static function resolve_attachment_id_from_url($image_url) {
        if (empty($image_url) || !is_string($image_url) || !function_exists('attachment_url_to_postid')) {
            return 0;
        }

        $attachment_id = intval(attachment_url_to_postid($image_url));
        if ($attachment_id > 0) {
            return $attachment_id;
        }

        $normalized_url = strtok($image_url, '?');
        if ($normalized_url && $normalized_url !== $image_url) {
            $attachment_id = intval(attachment_url_to_postid($normalized_url));
            if ($attachment_id > 0) {
                return $attachment_id;
            }
        }

        return 0;
    }

    /**
     * Download image from URL and attach to post
     *
     * @param int $post_id The post ID
     * @param string $image_url The image URL
     * @return int|WP_Error Attachment ID or error
     */
    private static function download_and_attach_image($post_id, $image_url) {
        // Validate URL
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', 'Invalid image URL');
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Download image
        $tmp_file = download_url($image_url, 30);
        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }

        // Get file info
        $file_type = wp_check_filetype(basename($image_url), null);
        if (empty($file_type['type'])) {
            @unlink($tmp_file);
            return new WP_Error('invalid_type', 'Could not determine file type');
        }

        // Prepare upload
        $upload_dir = wp_upload_dir();
        $filename = wp_unique_filename($upload_dir['path'], basename($image_url));
        $file_path = $upload_dir['path'] . '/' . $filename;

        // Move file to uploads
        if (!@copy($tmp_file, $file_path)) {
            @unlink($tmp_file);
            return new WP_Error('copy_failed', 'Failed to copy image file');
        }
        @unlink($tmp_file);

        // Create attachment
        $attachment_data = array(
            'post_mime_type' => $file_type['type'],
            'post_title'     => sanitize_file_name(basename($image_url)),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        $attachment_id = wp_insert_attachment($attachment_data, $file_path, $post_id);
        if (is_wp_error($attachment_id)) {
            @unlink($file_path);
            return $attachment_id;
        }

        // Generate attachment metadata
        $attach_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        // Mark as AI generated
        update_post_meta($attachment_id, '_ai_generated', true);
        update_post_meta($attachment_id, '_source_post_id', $post_id);

        return $attachment_id;
    }

    /**
     * Get topic status from database
     */
    private static function get_topic_status($topic_id) {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$topics_table} WHERE id = %d",
            $topic_id
        ));
        return $status;
    }

    /**
     * Restore topic status to unused
     */
    private static function restore_topic_status($topic_id) {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        $wpdb->update(
            $topics_table,
            array('status' => YALI_AI_WRITER_TOPIC_UNUSED),
            array('id' => $topic_id)
        );
        error_log("[DeepWriting] Restored topic {$topic_id} to 'unused' status");
    }

    private static function mark_topic_used($topic_id) {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        $result = $wpdb->update(
            $topics_table,
            array('status' => YALI_AI_WRITER_TOPIC_USED),
            array('id' => $topic_id)
        );
        return $result !== false;
    }

    /**
     * Clean up orphaned draft post
     *
     * Deletes a draft post that was created but couldn't be linked to a topic.
     * Used to maintain consistency when topic status update fails after draft creation.
     *
     * @param int $post_id The post ID to clean up
     * @return bool True if cleanup succeeded or post didn't exist, false on error
     */
    private static function cleanup_orphaned_draft($post_id) {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            return true;
        }

        // Verify it's actually a draft before deleting
        $post = get_post($post_id);
        if (!$post) {
            error_log("[DeepWriting] Orphaned draft cleanup: Post {$post_id} does not exist");
            return true;
        }

        if ($post->post_status !== 'draft') {
            error_log("[DeepWriting] Orphaned draft cleanup: Post {$post_id} is not a draft (status: {$post->post_status}), skipping deletion");
            return false;
        }

        // Check if this post is linked to any topic (safety check)
        $topic_id = get_post_meta($post_id, '_yali_deep_writing_topic_id', true);
        if (!empty($topic_id)) {
            global $wpdb;
            $articles_table = $wpdb->prefix . 'yali_ai_writer_articles';
            $linked_topic = $wpdb->get_var($wpdb->prepare(
                "SELECT topic_id FROM {$articles_table} WHERE topic_id = %d AND post_id = %d LIMIT 1",
                intval($topic_id),
                $post_id
            ));

            if ($linked_topic) {
                error_log("[DeepWriting] Orphaned draft cleanup: Post {$post_id} is linked to topic {$linked_topic}, not cleaning up");
                return false;
            }
        }

        // Delete the orphaned draft
        $result = wp_delete_post($post_id, true); // Force delete (skip trash)
        if ($result) {
            error_log("[DeepWriting] Cleaned up orphaned draft post {$post_id}");
            return true;
        } else {
            error_log("[DeepWriting] Failed to clean up orphaned draft post {$post_id}");
            return false;
        }
    }

    /**
     * Get task from queue
     */
    private static function get_task_from_queue($task_id) {
        $queue = get_option(self::OPTION_KEY_TASK_QUEUE, array());
        return isset($queue[$task_id]) ? $queue[$task_id] : null;
    }

    /**
     * Update queue item status
     */
    private static function update_queue_status($task_id, $status) {
        $queue = get_option(self::OPTION_KEY_TASK_QUEUE, array());
        if (isset($queue[$task_id])) {
            $queue[$task_id]['status'] = $status;
            $queue[$task_id]['updated_at'] = time();
            update_option(self::OPTION_KEY_TASK_QUEUE, $queue);
            error_log("[DeepWriting] Updated task {$task_id} status to {$status}");
            return true;
        }
        return false;
    }

    /**
     * Record sync failure in shadow state for retry semantics
     *
     * Keeps the topic-task mapping intact while recording failure details.
     * This supports later retry attempts by the extension.
     *
     * @param int $topic_id The topic ID
     * @param string $task_id The task ID
     * @param string $error The error message
     */
    private static function record_sync_failure($topic_id, $task_id, $error) {
        $shadow_state = get_option(self::OPTION_KEY_SHADOW_STATE, array());
        $shadow_state[$topic_id] = array(
            'state' => 'sync_failed',
            'task_id' => $task_id,
            'last_error' => $error,
            'error_count' => isset($shadow_state[$topic_id]['error_count']) ? $shadow_state[$topic_id]['error_count'] + 1 : 1,
            'first_failure_at' => $shadow_state[$topic_id]['first_failure_at'] ?? time(),
            'last_failure_at' => time(),
            'updated_at' => time()
        );
        update_option(self::OPTION_KEY_SHADOW_STATE, $shadow_state);
        error_log("[DeepWriting] Recorded sync failure for topic {$topic_id}, task {$task_id}: {$error}");
    }

    /**
     * Set shadow state for a topic
     */
    private static function set_shadow_state($topic_id, $state, $task_id) {
        $shadow_state = get_option(self::OPTION_KEY_SHADOW_STATE, array());
        $shadow_state[$topic_id] = array(
            'state' => $state,
            'task_id' => $task_id,
            'updated_at' => time()
        );
        update_option(self::OPTION_KEY_SHADOW_STATE, $shadow_state);
    }

    /**
     * Clear shadow state for a topic
     */
    private static function clear_shadow_state($topic_id) {
        $shadow_state = get_option(self::OPTION_KEY_SHADOW_STATE, array());
        if (isset($shadow_state[$topic_id])) {
            unset($shadow_state[$topic_id]);
            update_option(self::OPTION_KEY_SHADOW_STATE, $shadow_state);
        }
    }

    /**
     * Set topic-to-task mapping
     */
    private static function set_topic_task_mapping($topic_id, $task_id) {
        $mapping = get_option(self::OPTION_KEY_TOPIC_TASK_MAP, array());
        $mapping[$topic_id] = $task_id;
        update_option(self::OPTION_KEY_TOPIC_TASK_MAP, $mapping);
    }

    /**
     * Clear topic-to-task mapping
     */
    private static function clear_topic_task_mapping($topic_id) {
        $mapping = get_option(self::OPTION_KEY_TOPIC_TASK_MAP, array());
        if (isset($mapping[$topic_id])) {
            unset($mapping[$topic_id]);
            update_option(self::OPTION_KEY_TOPIC_TASK_MAP, $mapping);
        }
    }

    /**
     * Get topic ID by task ID
     */
    private static function get_topic_id_by_task($task_id) {
        $mapping = get_option(self::OPTION_KEY_TOPIC_TASK_MAP, array());
        foreach ($mapping as $topic_id => $task) {
            if ($task === $task_id) {
                return $topic_id;
            }
        }
        return null;
    }

    /**
     * Check if task has already been processed (idempotency)
     */
    private static function is_task_processed($task_id, $topic_id) {
        $processed = get_option('yali_deep_writing_processed_tasks', array());
        return isset($processed[$task_id]) || (
            self::get_topic_status($topic_id) === YALI_AI_WRITER_TOPIC_USED &&
            self::get_topic_post_id($topic_id) !== null
        );
    }

    /**
     * Acquire processing lock with TTL/stale lock recovery
     *
     * Prevents duplicate concurrent execution while allowing recovery from crashed callbacks.
     * If a lock is older than PROCESSING_LOCK_TTL, it is considered stale and will be
     * cleared to allow a new attempt.
     *
     * @param string $task_id The task ID
     * @param int $topic_id The topic ID
     * @return array Array with 'acquired' (bool) and 'error' (string|null) keys
     */
    private static function acquire_processing_lock($task_id, $topic_id) {
        $processing = get_option('yali_deep_writing_processing_tasks', array());
        $now = time();

        // Check if there's an existing lock for this task
        if (isset($processing[$task_id])) {
            $lock = $processing[$task_id];
            $lock_age = $now - ($lock['started_at'] ?? 0);

            // If lock is within TTL, another process is actively handling this
            if ($lock_age < self::PROCESSING_LOCK_TTL) {
                return array(
                    'acquired' => false,
                    'error' => "Task {$task_id} is already being processed (lock age: {$lock_age}s)"
                );
            }

            // Lock is stale (older than TTL) - clear it and allow new attempt
            error_log("[DeepWriting] Clearing stale processing lock for task {$task_id} (age: {$lock_age}s)");
            unset($processing[$task_id]);
        }

        // Also check for stale locks on this topic from other tasks (safety net)
        foreach ($processing as $existing_task_id => $lock) {
            if ($lock['topic_id'] == $topic_id) {
                $lock_age = $now - ($lock['started_at'] ?? 0);
                if ($lock_age >= self::PROCESSING_LOCK_TTL) {
                    error_log("[DeepWriting] Clearing stale topic lock for topic {$topic_id} from task {$existing_task_id} (age: {$lock_age}s)");
                    unset($processing[$existing_task_id]);
                }
            }
        }

        // Acquire the lock
        $processing[$task_id] = array(
            'topic_id' => $topic_id,
            'started_at' => $now
        );
        update_option('yali_deep_writing_processing_tasks', $processing);

        return array('acquired' => true, 'error' => null);
    }

    /**
     * Mark task as processing to prevent duplicate execution
     * @deprecated Use acquire_processing_lock() instead for TTL semantics
     */
    private static function mark_task_processing($task_id, $topic_id) {
        $processing = get_option('yali_deep_writing_processing_tasks', array());
        $processing[$task_id] = array(
            'topic_id' => $topic_id,
            'started_at' => time()
        );
        update_option('yali_deep_writing_processing_tasks', $processing);
    }

    /**
     * Clear task processing mark
     */
    private static function clear_task_processing($task_id, $topic_id) {
        $processing = get_option('yali_deep_writing_processing_tasks', array());
        if (isset($processing[$task_id])) {
            unset($processing[$task_id]);
            update_option('yali_deep_writing_processing_tasks', $processing);
        }
    }

    /**
     * Mark task as successfully processed
     */
    private static function mark_task_processed($task_id, $topic_id, $post_id) {
        $processed = get_option('yali_deep_writing_processed_tasks', array());
        $processed[$task_id] = array(
            'topic_id' => $topic_id,
            'post_id' => $post_id,
            'processed_at' => time()
        );
        update_option('yali_deep_writing_processed_tasks', $processed);
        self::clear_task_processing($task_id, $topic_id);
    }

    private static function save_article_record($topic_id, $post_id, $result) {
        global $wpdb;

        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        $articles_table = $wpdb->prefix . 'yali_ai_writer_articles';

        $topic = $wpdb->get_row($wpdb->prepare(
            "SELECT id, title, api_config_id, api_config_name FROM {$topics_table} WHERE id = %d",
            $topic_id
        ), ARRAY_A);

        if (!$topic) {
            return false;
        }

        $existing_article_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$articles_table} WHERE topic_id = %d AND post_id = %d LIMIT 1",
            $topic_id,
            $post_id
        ));

        if ($existing_article_id) {
            return true;
        }

        $article_data = array(
            'job_id' => 0,
            'topic_id' => $topic_id,
            'post_id' => $post_id,
            'title' => $result['title'] ?? $topic['title'],
            'content' => $result['content'] ?? '',
            'status' => YALI_AI_WRITER_ARTICLE_SUCCESS,
            'processing_time' => 0,
            'word_count' => yali_ai_writer_manager_word_count($result['content'] ?? ''),
            'api_config_id' => $topic['api_config_id'] ?? null,
            'api_config_name' => $topic['api_config_name'] ?? null,
            'prompt_template' => 'deep-writing-extension'
        );

        return (bool) $wpdb->insert($articles_table, $article_data);
    }

    private static function delete_article_record($topic_id, $post_id) {
        global $wpdb;
        $articles_table = $wpdb->prefix . 'yali_ai_writer_articles';
        $wpdb->delete($articles_table, array(
            'topic_id' => $topic_id,
            'post_id' => $post_id,
        ));
    }

    /**
     * Get post ID associated with a topic (for idempotency check)
     */
    private static function get_topic_post_id($topic_id) {
        global $wpdb;
        $articles_table = $wpdb->prefix . 'yali_ai_writer_articles';
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$articles_table} WHERE topic_id = %d AND post_id > 0 ORDER BY id DESC LIMIT 1",
            $topic_id,
        ));
        if ($post_id) {
            return intval($post_id);
        }

        if (!empty($wpdb->postmeta)) {
            $fallback_post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_yali_deep_writing_topic_id' AND meta_value = %d ORDER BY meta_id DESC LIMIT 1",
                $topic_id,
            ));
            if ($fallback_post_id) {
                return intval($fallback_post_id);
            }
        }

        return null;
    }
}

// Initialize on WordPress init
add_action('init', array('Yali_AI_Writer_DeepWritingHandler', 'init'));
