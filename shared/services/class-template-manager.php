<?php
/**
 * Prompt Template Manager Service
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_TemplateManager {
    
    private $table_name;
    private $db;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'yali_ai_writer_prompt_templates';
        
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/database/class-database.php';
        $this->db = new Yali_AI_Writer_Database();
    }
    
    /**
     * Get all templates
     */
    public function get_templates($type = null) {
        $where = array();
        if ($type) {
            $where['template_type'] = $type;
        }
        
        return $this->db->get_results($this->table_name, $where);
    }
    
    /**
     * Get a specific template
     */
    public function get_template($id) {
        return $this->db->get_row($this->table_name, array('id' => $id));
    }
    
    /**
     * Save a template (create or update)
     */
    public function save_template($data) {
        // Validate
        if (empty($data['name']) || empty($data['template_type']) || empty($data['content'])) {
            return new WP_Error('invalid_data', 'Missing required fields');
        }
        
        $template_data = array(
            'name' => sanitize_text_field($data['name']),
            'template_type' => sanitize_text_field($data['template_type']),
            'content' => $data['content'], // Provide XML/Text content, handled carefully
            'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1,
        );
        
        if (isset($data['id']) && !empty($data['id'])) {
            // Update
            $this->db->update($this->table_name, $template_data, array('id' => $data['id']));
            return $data['id'];
        } else {
            // Create
            return $this->db->insert($this->table_name, $template_data);
        }
    }
    
    /**
     * Delete a template
     */
    public function delete_template($id) {
        return $this->db->delete($this->table_name, array('id' => $id));
    }
    
    /**
     * Get active template content by type (randomly selected if multiple active)
     * This mimics the existing file-based random selection
     */
    public function get_active_template($type) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE template_type = %s AND is_active = 1 ORDER BY RAND() LIMIT 1",
            $type
        );
        return $wpdb->get_row($sql, ARRAY_A);
    }

    /**
     * Get active template content by type (randomly selected if multiple active)
     * This mimics the existing file-based random selection
     */
    public function get_active_template_content($type) {
        $template = $this->get_active_template($type);
        return $template ? $template['content'] : null;
    }
}
