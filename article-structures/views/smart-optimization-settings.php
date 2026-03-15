<?php
/**
 * 智能结构优化设置页面视图
 * 
 * 提供智能文章结构优化功能的管理界面
 * 包含功能开关、冷启动阶段显示、配置参数表单等
 * 
 * @package ContentAuto
 * @subpackage Views
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<?php
// 如果不是作为部分视图嵌入加载，则显示页面包装器和导航
if (empty($yali_smart_opt_embedded)) {
    $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'smart-optimization';
?>
<div class="wrap" id="smart-optimization-page-standalone">
    <h1 class="yali-page-title"><span class="dashicons dashicons-layout"></span> <?php _e('文章结构管理', 'yali-ai-writer'); ?></h1>
    <div class="yali-tabs">
        <a href="admin.php?page=yali-ai-writer-publish-rules&action=article-structures&tab=structures" class="yali-tab-item <?php echo $current_tab === 'structures' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-networking"></span> <?php _e('结构管理', 'yali-ai-writer'); ?>
        </a>
        <a href="admin.php?page=yali-ai-writer-publish-rules&action=article-structures&tab=smart-optimization" class="yali-tab-item <?php echo $current_tab === 'smart-optimization' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-chart-line"></span> <?php _e('智能优化', 'yali-ai-writer'); ?>
        </a>
    </div>
<?php
}
?>

<div class="yali-container" style="margin-top:20px;" id="smart-optimization-page">
    <div class="yali-notice yali-notice-info">
            <p><strong><?php _e('智能结构优化系统：', 'yali-ai-writer'); ?></strong><?php _e('通过分析受欢迎文章的结构特征，自动学习并优化文章结构选择策略。系统会根据历史数据智能选择最适合的文章结构，同时保持内容多样性。', 'yali-ai-writer'); ?></p>
        </div>

        <!-- 功能总开关 -->
        <div class="yali-card">
            <div class="yali-card-header">
                <h3><?php _e('功能开关', 'yali-ai-writer'); ?></h3>
            </div>
            <div class="yali-flex-row" style="justify-content: space-between; align-items: center;">
                <div class="toggle-info">
                    <h4 style="margin:0 0 5px 0;"><?php _e('启用智能结构优化', 'yali-ai-writer'); ?></h4>
                    <p class="description" style="margin:0;"><?php _e('启用后，系统将基于受欢迎度数据智能选择文章结构，而非完全随机选择。', 'yali-ai-writer'); ?></p>
                </div>
                <div class="toggle-control" style="display:flex; align-items:center; gap:10px;">
                    <label class="switch">
                        <input type="checkbox" id="smart-optimization-enabled" />
                        <span class="slider round"></span>
                    </label>
                    <span class="toggle-status" id="toggle-status-text" style="font-weight:500;"><?php _e('加载中...', 'yali-ai-writer'); ?></span>
                </div>
            </div>
        </div>

        <!-- 冷启动阶段显示 -->
        <div class="yali-card">
            <div class="yali-card-header">
                <h3><?php _e('冷启动阶段状态', 'yali-ai-writer'); ?></h3>
            </div>
            <p class="description" style="margin-bottom:15px;"><?php _e('系统根据每个内容角度的文章数量自动判断冷启动阶段，并调整选择策略。', 'yali-ai-writer'); ?></p>
            <div id="cold-start-phases-container">
                <span class="spinner is-active"></span>
                <p><?php _e('正在加载冷启动阶段数据...', 'yali-ai-writer'); ?></p>
            </div>
        </div>

        <!-- 配置参数表单 -->
        <div class="yali-card">
            <div class="yali-card-header">
                <h3><?php _e('配置参数', 'yali-ai-writer'); ?></h3>
            </div>
            <p class="description" style="margin-bottom:15px;"><?php _e('调整智能选择算法的参数。修改后点击"保存配置"生效。', 'yali-ai-writer'); ?></p>
            
            <form id="optimization-config-form">
                
                <!-- 策略与随机性 -->
                <div class="yali-panel" style="margin-bottom: 20px;">
                    <h4 style="margin:0 0 15px 0; font-size:14px; border-bottom:1px solid #eee; padding-bottom:10px;"><?php _e('选择策略与随机性', 'yali-ai-writer'); ?></h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <label for="exploration_rate" class="yali-form-label"><?php _e('探索率 (ε)', 'yali-ai-writer'); ?></label>
                            <input type="number" id="exploration_rate" name="exploration_rate" 
                                   min="0" max="1" step="0.05" class="yali-input" />
                            <p class="yali-desc"><?php _e('正常阶段随机选择的比例 (0-1)。值越高，随机性越强。', 'yali-ai-writer'); ?></p>
                        </div>
                        <div>
                            <label for="softmax_temperature" class="yali-form-label"><?php _e('Softmax 温度', 'yali-ai-writer'); ?></label>
                            <input type="number" id="softmax_temperature" name="softmax_temperature" 
                                   min="0.1" max="5" step="0.1" class="yali-input" />
                            <p class="yali-desc"><?php _e('控制加权选择集中度 (0.1-5)。值越低越倾向热门结构。', 'yali-ai-writer'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 多样性控制 -->
                <div class="yali-panel" style="margin-bottom: 20px;">
                    <h4 style="margin:0 0 15px 0; font-size:14px; border-bottom:1px solid #eee; padding-bottom:10px;"><?php _e('多样性控制 (惩罚机制)', 'yali-ai-writer'); ?></h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <label for="batch_diversity_threshold" class="yali-form-label"><?php _e('批量使用阈值', 'yali-ai-writer'); ?></label>
                            <input type="number" id="batch_diversity_threshold" name="batch_diversity_threshold" 
                                   min="0" max="1" step="0.05" class="yali-input" />
                            <p class="yali-desc"><?php _e('同批次中单个结构使用比例上限 (0-1)。', 'yali-ai-writer'); ?></p>
                        </div>
                        <div>
                            <label for="batch_diversity_penalty" class="yali-form-label"><?php _e('批量惩罚系数', 'yali-ai-writer'); ?></label>
                            <input type="number" id="batch_diversity_penalty" name="batch_diversity_penalty" 
                                   min="0" max="1" step="0.1" class="yali-input" />
                            <p class="yali-desc"><?php _e('超过批量阈值时的权重乘数 (0-1)。', 'yali-ai-writer'); ?></p>
                        </div>
                        <div>
                            <label for="window_diversity_threshold" class="yali-form-label"><?php _e('窗口使用阈值 (7天)', 'yali-ai-writer'); ?></label>
                            <input type="number" id="window_diversity_threshold" name="window_diversity_threshold" 
                                   min="0" max="1" step="0.05" class="yali-input" />
                            <p class="yali-desc"><?php _e('7天内单个结构使用比例上限 (0-1)。', 'yali-ai-writer'); ?></p>
                        </div>
                        <div>
                            <label for="window_diversity_penalty" class="yali-form-label"><?php _e('窗口惩罚系数', 'yali-ai-writer'); ?></label>
                            <input type="number" id="window_diversity_penalty" name="window_diversity_penalty" 
                                   min="0" max="1" step="0.1" class="yali-input" />
                            <p class="yali-desc"><?php _e('超过窗口阈值时的权重乘数 (0-1)。', 'yali-ai-writer'); ?></p>
                        </div>
                        <div>
                            <label for="min_entropy_threshold" class="yali-form-label"><?php _e('最小熵阈值', 'yali-ai-writer'); ?></label>
                            <input type="number" id="min_entropy_threshold" name="min_entropy_threshold" 
                                   min="0" max="5" step="0.1" class="yali-input" />
                            <p class="yali-desc"><?php _e('熵值低于此值时触发多样性警告。', 'yali-ai-writer'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 结构提升与分析 -->
                <div class="yali-panel" style="margin-bottom: 20px;">
                    <h4 style="margin:0 0 15px 0; font-size:14px; border-bottom:1px solid #eee; padding-bottom:10px;"><?php _e('新结构提升 & 分析任务', 'yali-ai-writer'); ?></h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <label for="new_structure_boost" class="yali-form-label"><?php _e('新结构提升系数', 'yali-ai-writer'); ?></label>
                            <input type="number" id="new_structure_boost" name="new_structure_boost" 
                                   min="1" max="5" step="0.1" class="yali-input" />
                            <p class="yali-desc"><?php _e('数据驱动新结构的初始权重乘数。', 'yali-ai-writer'); ?></p>
                        </div>
                        <div>
                            <label for="new_structure_boost_uses" class="yali-form-label"><?php _e('提升有效次数', 'yali-ai-writer'); ?></label>
                            <input type="number" id="new_structure_boost_uses" name="new_structure_boost_uses" 
                                   min="1" max="20" step="1" class="yali-input" />
                            <p class="yali-desc"><?php _e('新结构在前 N 次使用内享受提升。', 'yali-ai-writer'); ?></p>
                        </div>
                        <div>
                            <label for="analysis_schedule_hour" class="yali-form-label"><?php _e('分析执行时间 (小时)', 'yali-ai-writer'); ?></label>
                            <div class="yali-flex-row">
                                <input type="number" id="analysis_schedule_hour" name="analysis_schedule_hour" 
                                   min="0" max="23" step="1" class="yali-input" />
                                <span>:00</span>
                            </div>
                            <p class="yali-desc"><?php _e('每日自动分析任务的执行时间。', 'yali-ai-writer'); ?></p>
                        </div>
                        <div>
                            <label for="min_articles_for_analysis" class="yali-form-label"><?php _e('最小分析文章数', 'yali-ai-writer'); ?></label>
                            <input type="number" id="min_articles_for_analysis" name="min_articles_for_analysis" 
                                   min="1" max="100" step="1" class="yali-input" />
                            <p class="yali-desc"><?php _e('每角度至少需要的文章数量。', 'yali-ai-writer'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- 时间衰减 & 置信度 -->
                <div class="yali-panel" style="margin-bottom: 0;">
                    <h4 style="margin:0 0 15px 0; font-size:14px; border-bottom:1px solid #eee; padding-bottom:10px;"><?php _e('时间衰减 & 置信度', 'yali-ai-writer'); ?></h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                         <div>
                            <label for="time_decay_30_days" class="yali-form-label"><?php _e('30天内权重', 'yali-ai-writer'); ?></label>
                            <input type="number" id="time_decay_30_days" name="time_decay_30_days" 
                                   min="0" max="1" step="0.1" class="yali-input" />
                            <p class="yali-desc"><?php _e('最近30天数据的权重系数。', 'yali-ai-writer'); ?></p>
                        </div>
                        <div>
                            <label for="time_decay_30_90_days" class="yali-form-label"><?php _e('30-90天权重', 'yali-ai-writer'); ?></label>
                            <input type="number" id="time_decay_30_90_days" name="time_decay_30_90_days" 
                                   min="0" max="1" step="0.1" class="yali-input" />
                            <p class="yali-desc"><?php _e('中期数据的权重系数。', 'yali-ai-writer'); ?></p>
                        </div>
                        <div>
                            <label for="time_decay_90_plus_days" class="yali-form-label"><?php _e('90天以上权重', 'yali-ai-writer'); ?></label>
                            <input type="number" id="time_decay_90_plus_days" name="time_decay_90_plus_days" 
                                   min="0" max="1" step="0.1" class="yali-input" />
                            <p class="yali-desc"><?php _e('早期数据的权重系数。', 'yali-ai-writer'); ?></p>
                        </div>
                         <div>
                            <label for="confidence_min_articles" class="yali-form-label"><?php _e('置信度最小文章数', 'yali-ai-writer'); ?></label>
                            <input type="number" id="confidence_min_articles" name="confidence_min_articles" 
                                   min="1" max="10" step="1" class="yali-input" />
                            <p class="yali-desc"><?php _e('少于此值时应用置信度折扣。', 'yali-ai-writer'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="yali-card-footer" style="padding: 20px 0 0 0; border: none; margin-top:20px;">
                    <button type="submit" class="yali-btn yali-btn-primary" id="save-config-btn"><?php _e('保存配置', 'yali-ai-writer'); ?></button>
                    <button type="button" class="yali-btn yali-btn-secondary" id="reset-config-btn" style="margin-left:10px;"><?php _e('恢复默认', 'yali-ai-writer'); ?></button>
                    <span class="spinner" id="config-spinner" style="float:none; margin-left:10px;"></span>
                </div>
            </form>
        </div>

        <!-- 数据驱动结构列表 -->
        <div class="yali-card">
            <div class="yali-card-header">
                <h3><?php _e('数据驱动结构', 'yali-ai-writer'); ?></h3>
            </div>
            <p class="description" style="margin-bottom:15px;"><?php _e('从受欢迎文章中自动提取生成的结构模板。', 'yali-ai-writer'); ?></p>
            <div id="data-driven-structures-container">
                <span class="spinner is-active"></span>
                <p><?php _e('正在加载数据驱动结构...', 'yali-ai-writer'); ?></p>
            </div>
        </div>

        <!-- 多样性监控面板 -->
        <div class="yali-card">
            <div class="yali-card-header">
                <h3><?php _e('多样性监控', 'yali-ai-writer'); ?></h3>
            </div>
            <p class="description" style="margin-bottom:20px;"><?php _e('监控结构选择的多样性，防止过度依赖少数热门结构。', 'yali-ai-writer'); ?></p>
            
            <!-- Grid Wrapper for Diversity Panels -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 20px;">
                <!-- 熵值概览 -->
                <div style="margin-bottom:0; height: 100%;">
                    <h4 style="margin:0 0 15px 0; font-size:14px; color:#1d2327; font-weight:600;"><?php _e('熵值概览', 'yali-ai-writer'); ?></h4>
                    <div id="entropy-overview-container">
                        <span class="spinner is-active"></span>
                    </div>
                </div>
                
                <!-- 结构使用分布 -->
                <div style="margin-bottom:0; height: 100%;">
                    <h4 style="margin:0 0 15px 0; font-size:14px; color:#1d2327; font-weight:600;"><?php _e('结构使用分布', 'yali-ai-writer'); ?></h4>
                    <div id="usage-distribution-container">
                        <span class="spinner is-active"></span>
                    </div>
                </div>
            </div>
            
            <!-- 最近选择日志 -->
            <div class="yali-card" style="box-shadow:none; border:1px solid #eee; margin-bottom:0;">
                <div class="yali-card-header" style="padding-bottom:10px; margin-bottom:15px; border-bottom:1px dashed #eee;">
                    <h4 style="margin:0; font-size:14px;"><?php _e('最近选择日志', 'yali-ai-writer'); ?></h4>
                </div>
                <div id="recent-selections-container">
                    <span class="spinner is-active"></span>
                </div>
            </div>
        </div>

        <!-- 性能对比视图 -->
        <div class="yali-card">
            <div class="yali-card-header">
                <h3><?php _e('性能对比', 'yali-ai-writer'); ?></h3>
            </div>
            <p class="description" style="margin-bottom:15px;"><?php _e('对比 AI 生成结构与数据驱动结构的表现。', 'yali-ai-writer'); ?></p>
            <div id="performance-comparison-container">
                <span class="spinner is-active"></span>
                <p><?php _e('正在加载性能对比数据...', 'yali-ai-writer'); ?></p>
            </div>
        </div>

        <!-- 手动触发区域 -->
        <div class="yali-card">
            <div class="yali-card-header">
                <h3><?php _e('手动操作', 'yali-ai-writer'); ?></h3>
            </div>
            <p class="description" style="margin-bottom:15px;"><?php _e('手动触发分析任务或更新受欢迎度指数。', 'yali-ai-writer'); ?></p>
            <div class="manual-actions" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <button type="button" class="yali-btn yali-btn-secondary" id="manual-analyze-btn">
                    <span class="dashicons dashicons-search"></span> <?php _e('立即分析高表现文章', 'yali-ai-writer'); ?>
                </button>
                <button type="button" class="yali-btn yali-btn-secondary" id="manual-update-popularity-btn">
                    <span class="dashicons dashicons-update"></span> <?php _e('更新受欢迎度指数', 'yali-ai-writer'); ?>
                </button>
                <button type="button" class="yali-btn yali-btn-danger" id="clear-cache-btn">
                    <span class="dashicons dashicons-trash"></span> <?php _e('清除缓存', 'yali-ai-writer'); ?>
                </button>
                <span class="spinner" id="manual-action-spinner" style="float:none; margin:0;"></span>
            </div>
            <div id="manual-action-result" style="margin-top:15px;"></div>
        </div>
</div>
<?php
// 如果是独立页面（非嵌入），则关闭之前开启的 wrap 容器
if (empty($yali_smart_opt_embedded)) {
    echo '</div>'; 
}
?>
