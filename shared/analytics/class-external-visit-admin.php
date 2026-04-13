<?php
/**
 * 外部访问统计管理界面
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_ExternalVisitAdmin {
    
    private $tracker;
    
    public function __construct($tracker) {
        $this->tracker = $tracker;
        
        // 在文章编辑页面显示统计信息
        add_action('add_meta_boxes', array($this, 'add_stats_meta_box'));
        
        // 在文章列表添加统计列
        add_filter('manage_posts_columns', array($this, 'add_stats_column'));
        add_action('manage_posts_custom_column', array($this, 'display_stats_column'), 10, 2);
    }
    
    
    
    /**
     * 添加文章编辑页面的统计Meta Box
     */
    public function add_stats_meta_box() {
        add_meta_box(
            'external_visit_stats',
            '📊 外部访问统计',
            array($this, 'display_stats_meta_box'),
            'post',
            'side',
            'default'
        );
    }
    
    /**
     * 显示统计Meta Box（简化版）
     */
    public function display_stats_meta_box($post) {
        $visit_count = $this->tracker->get_visit_count($post->ID);
        ?>
        <div class="external-visit-stats">
            <p style="font-size: 24px; margin: 10px 0; text-align: center;">
                <strong style="color: #2271b1;"><?php echo number_format($visit_count); ?></strong>
            </p>
            <p style="text-align: center; margin: 5px 0; color: #666;">
                外部访问次数
            </p>
            <hr style="margin: 15px 0;">
            <p style="font-size: 12px; color: #666; margin: 0;">
                统计真实用户从外部来源的访问次数<br>
                <small>（已过滤爬虫、蜘蛛等自动化访问）</small>
            </p>
        </div>
        <?php
    }
    
    /**
     * 在文章列表添加统计列
     */
    public function add_stats_column($columns) {
        // 在日期列之前插入外部访问列
        $new_columns = array();
        foreach ($columns as $key => $value) {
            if ($key === 'date') {
                $new_columns['external_visits'] = '📊 外部访问';
            }
            $new_columns[$key] = $value;
        }
        return $new_columns;
    }
    
    /**
     * 显示统计列内容
     */
    public function display_stats_column($column, $post_id) {
        if ($column === 'external_visits') {
            $visit_count = (int)get_post_meta($post_id, '_external_visit_count', true);
            if ($visit_count > 0) {
                echo '<span style="color: #2271b1; font-weight: bold;">' . number_format($visit_count) . '</span>';
            } else {
                echo '<span style="color: #757575;">0</span>';
            }
        }
    }
    
}