<?php
/**
 * 分类过滤管理类
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_Category_Filter {
    
    /**
     * 获取允许使用的分类ID列表
     */
    public static function get_allowed_categories() {
        $allowed_categories = get_option('content_auto_manager_allowed_categories', array());
        
        // 如果没有设置，返回所有分类
        if (empty($allowed_categories)) {
            $all_categories = get_categories(array('hide_empty' => false));
            return wp_list_pluck($all_categories, 'term_id');
        }
        
        return array_map('intval', $allowed_categories);
    }
    
    /**
     * 检查分类是否被允许使用
     */
    public static function is_category_allowed($category_id) {
        $allowed_categories = self::get_allowed_categories();
        return in_array(intval($category_id), $allowed_categories);
    }
    
    /**
     * 获取过滤后的分类列表
     */
    public static function get_filtered_categories($args = array()) {
        $default_args = array(
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        );
        
        $args = wp_parse_args($args, $default_args);
        $all_categories = get_categories($args);
        $allowed_category_ids = self::get_allowed_categories();
        
        // 过滤分类
        $filtered_categories = array();
        foreach ($all_categories as $category) {
            if (in_array($category->term_id, $allowed_category_ids)) {
                $filtered_categories[] = $category;
            }
        }
        
        return $filtered_categories;
    }
    
    /**
     * 过滤分类ID数组，只保留允许的分类
     */
    public static function filter_category_ids($category_ids) {
        if (empty($category_ids) || !is_array($category_ids)) {
            return array();
        }
        
        $allowed_categories = self::get_allowed_categories();
        $filtered_ids = array();
        
        foreach ($category_ids as $category_id) {
            if (in_array(intval($category_id), $allowed_categories)) {
                $filtered_ids[] = intval($category_id);
            }
        }
        
        return $filtered_ids;
    }
    
    /**
     * 获取过滤设置的统计信息
     */
    public static function get_filter_stats() {
        $all_categories = get_categories(array('hide_empty' => false));
        $allowed_categories = get_option('content_auto_manager_allowed_categories', array());
        
        return array(
            'total_categories' => count($all_categories),
            'allowed_categories' => count($allowed_categories),
            'is_filtered' => !empty($allowed_categories),
            'filter_percentage' => empty($allowed_categories) ? 100 : round((count($allowed_categories) / count($all_categories)) * 100, 1)
        );
    }
    
    /**
     * 重置分类过滤设置
     */
    public static function reset_filter() {
        delete_option('content_auto_manager_allowed_categories');
    }
    
    /**
     * 验证并清理分类设置（移除已删除的分类）
     */
    public static function validate_and_clean_settings() {
        $allowed_categories = get_option('content_auto_manager_allowed_categories', array());
        
        if (empty($allowed_categories)) {
            return;
        }
        
        $all_category_ids = get_categories(array(
            'hide_empty' => false,
            'fields' => 'ids'
        ));
        
        // 过滤掉已删除的分类
        $valid_categories = array_intersect($allowed_categories, $all_category_ids);
        
        // 如果有变化，更新设置
        if (count($valid_categories) !== count($allowed_categories)) {
            update_option('content_auto_manager_allowed_categories', $valid_categories);
        }
    }
    
    /**
     * 获取过滤后的分类，并自动包含必要的父分类（用于层级显示）
     * @param array $args 查询参数
     * @return array 分类对象数组
     */
    public static function get_filtered_categories_with_parents($args = array()) {
        $filtered_categories = self::get_filtered_categories($args);
        $allowed_category_ids = self::get_allowed_categories();
        
        // 如果没有启用过滤，直接返回
        $filter_stats = self::get_filter_stats();
        if (!$filter_stats['is_filtered']) {
            return $filtered_categories;
        }
        
        // 收集所有需要的父分类ID
        $needed_parent_ids = array();
        foreach ($filtered_categories as $category) {
            $parent_id = $category->parent;
            while ($parent_id > 0) {
                if (!in_array($parent_id, $allowed_category_ids) && !in_array($parent_id, $needed_parent_ids)) {
                    $needed_parent_ids[] = $parent_id;
                }
                $parent_category = get_category($parent_id);
                $parent_id = $parent_category ? $parent_category->parent : 0;
            }
        }
        
        // 获取需要的父分类
        if (!empty($needed_parent_ids)) {
            $parent_categories = get_categories(array(
                'include' => $needed_parent_ids,
                'hide_empty' => false
            ));
            
            // 合并分类列表
            $all_categories = array_merge($filtered_categories, $parent_categories);
            
            // 按ID去重并排序
            $unique_categories = array();
            $seen_ids = array();
            foreach ($all_categories as $category) {
                if (!in_array($category->term_id, $seen_ids)) {
                    $unique_categories[] = $category;
                    $seen_ids[] = $category->term_id;
                }
            }
            
            // 按名称排序
            usort($unique_categories, function($a, $b) {
                return strcmp($a->name, $b->name);
            });
            
            return $unique_categories;
        }
        
        return $filtered_categories;
    }
}