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

<div class="wrap" id="smart-optimization-page">
    <h1>文章结构管理</h1>
    
    <!-- 标签导航 -->
    <nav class="nav-tab-wrapper">
        <a href="admin.php?page=content-auto-manager-article-structures" class="nav-tab">结构管理</a>
        <a href="admin.php?page=content-auto-manager-smart-optimization" class="nav-tab nav-tab-active">智能优化</a>
    </nav>
    
    <div class="tab-content-wrapper">
        <p>智能结构优化系统通过分析受欢迎文章的结构特征，自动学习并优化文章结构选择策略。系统会根据历史数据智能选择最适合的文章结构，同时保持内容多样性。</p>

        <!-- 功能总开关 -->
        <div class="optimization-section">
            <h2>功能开关</h2>
            <div class="optimization-toggle-card">
                <div class="toggle-info">
                    <h3>智能结构优化</h3>
                    <p>启用后，系统将基于受欢迎度数据智能选择文章结构，而非完全随机选择。</p>
                </div>
                <div class="toggle-control">
                    <label class="switch">
                    <input type="checkbox" id="smart-optimization-enabled" />
                    <span class="slider round"></span>
                </label>
                <span class="toggle-status" id="toggle-status-text">加载中...</span>
            </div>
        </div>
    </div>

    <!-- 冷启动阶段显示 -->
    <div class="optimization-section">
        <h2>冷启动阶段状态</h2>
        <p class="section-description">系统根据每个内容角度的文章数量自动判断冷启动阶段，并调整选择策略。</p>
        <div id="cold-start-phases-container">
            <span class="spinner is-active"></span>
            <p>正在加载冷启动阶段数据...</p>
        </div>
    </div>

    <!-- 配置参数表单 -->
    <div class="optimization-section">
        <h2>配置参数</h2>
        <p class="section-description">调整智能选择算法的参数。修改后点击"保存配置"生效。</p>
        
        <form id="optimization-config-form">
            <table class="form-table">
                <tbody>
                    <!-- 选择策略参数 -->
                    <tr>
                        <th colspan="2"><h3 class="config-group-title">选择策略参数</h3></th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="exploration_rate">探索率 (ε)</label>
                        </th>
                        <td>
                            <input type="number" id="exploration_rate" name="exploration_rate" 
                                   min="0" max="1" step="0.05" class="small-text" />
                            <p class="description">正常阶段随机选择的比例 (0-1)。值越高，随机性越强。默认: 0.3</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="softmax_temperature">Softmax 温度</label>
                        </th>
                        <td>
                            <input type="number" id="softmax_temperature" name="softmax_temperature" 
                                   min="0.1" max="5" step="0.1" class="small-text" />
                            <p class="description">控制加权选择的集中程度 (0.1-5)。值越低越倾向高受欢迎度结构，值越高越接近随机。默认: 1.0</p>
                        </td>
                    </tr>

                    <!-- 批量多样性参数 -->
                    <tr>
                        <th colspan="2"><h3 class="config-group-title">批量多样性控制</h3></th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="batch_diversity_threshold">批量使用阈值</label>
                        </th>
                        <td>
                            <input type="number" id="batch_diversity_threshold" name="batch_diversity_threshold" 
                                   min="0" max="1" step="0.05" class="small-text" />
                            <p class="description">同批次中单个结构使用比例超过此值时触发惩罚 (0-1)。默认: 0.25</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="batch_diversity_penalty">批量惩罚系数</label>
                        </th>
                        <td>
                            <input type="number" id="batch_diversity_penalty" name="batch_diversity_penalty" 
                                   min="0" max="1" step="0.1" class="small-text" />
                            <p class="description">超过批量阈值时的权重乘数。默认: 0.3</p>
                        </td>
                    </tr>

                    <!-- 窗口多样性参数 -->
                    <tr>
                        <th colspan="2"><h3 class="config-group-title">窗口多样性控制</h3></th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="window_diversity_threshold">窗口使用阈值</label>
                        </th>
                        <td>
                            <input type="number" id="window_diversity_threshold" name="window_diversity_threshold" 
                                   min="0" max="1" step="0.05" class="small-text" />
                            <p class="description">7天窗口内单个结构使用比例超过此值时触发惩罚 (0-1)。默认: 0.30</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="window_diversity_penalty">窗口惩罚系数</label>
                        </th>
                        <td>
                            <input type="number" id="window_diversity_penalty" name="window_diversity_penalty" 
                                   min="0" max="1" step="0.1" class="small-text" />
                            <p class="description">超过窗口阈值时的权重乘数。默认: 0.3</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="min_entropy_threshold">最小熵阈值</label>
                        </th>
                        <td>
                            <input type="number" id="min_entropy_threshold" name="min_entropy_threshold" 
                                   min="0" max="5" step="0.1" class="small-text" />
                            <p class="description">熵值低于此值时触发多样性警告。默认: 1.5</p>
                        </td>
                    </tr>

                    <!-- 新结构提升参数 -->
                    <tr>
                        <th colspan="2"><h3 class="config-group-title">新结构提升</h3></th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="new_structure_boost">新结构提升系数</label>
                        </th>
                        <td>
                            <input type="number" id="new_structure_boost" name="new_structure_boost" 
                                   min="1" max="5" step="0.1" class="small-text" />
                            <p class="description">数据驱动新结构的权重乘数。默认: 2.0</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="new_structure_boost_uses">提升有效次数</label>
                        </th>
                        <td>
                            <input type="number" id="new_structure_boost_uses" name="new_structure_boost_uses" 
                                   min="1" max="20" step="1" class="small-text" />
                            <p class="description">新结构提升在前N次使用内有效。默认: 5</p>
                        </td>
                    </tr>

                    <!-- 分析任务参数 -->
                    <tr>
                        <th colspan="2"><h3 class="config-group-title">定时分析任务</h3></th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="analysis_schedule_hour">分析执行时间</label>
                        </th>
                        <td>
                            <input type="number" id="analysis_schedule_hour" name="analysis_schedule_hour" 
                                   min="0" max="23" step="1" class="small-text" />
                            <span>:00</span>
                            <p class="description">每日文章分析任务的执行时间（24小时制）。默认: 3</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="min_articles_for_analysis">最小分析文章数</label>
                        </th>
                        <td>
                            <input type="number" id="min_articles_for_analysis" name="min_articles_for_analysis" 
                                   min="1" max="100" step="1" class="small-text" />
                            <p class="description">每个内容角度至少需要此数量文章才进行分析。默认: 10</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="min_days_published">最小发布天数</label>
                        </th>
                        <td>
                            <input type="number" id="min_days_published" name="min_days_published" 
                                   min="1" max="30" step="1" class="small-text" />
                            <p class="description">文章发布后至少经过此天数才纳入分析。默认: 7</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="max_articles_per_angle">每角度最大处理数</label>
                        </th>
                        <td>
                            <input type="number" id="max_articles_per_angle" name="max_articles_per_angle" 
                                   min="1" max="20" step="1" class="small-text" />
                            <p class="description">每次分析任务每个内容角度最多处理的高表现文章数。默认: 5</p>
                        </td>
                    </tr>

                    <!-- 时间衰减参数 -->
                    <tr>
                        <th colspan="2"><h3 class="config-group-title">时间衰减因子</h3></th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="time_decay_30_days">30天内权重</label>
                        </th>
                        <td>
                            <input type="number" id="time_decay_30_days" name="time_decay_30_days" 
                                   min="0" max="1" step="0.1" class="small-text" />
                            <p class="description">最近30天内文章的权重系数。默认: 1.0</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="time_decay_30_90_days">30-90天权重</label>
                        </th>
                        <td>
                            <input type="number" id="time_decay_30_90_days" name="time_decay_30_90_days" 
                                   min="0" max="1" step="0.1" class="small-text" />
                            <p class="description">30-90天前文章的权重系数。默认: 0.7</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="time_decay_90_plus_days">90天以上权重</label>
                        </th>
                        <td>
                            <input type="number" id="time_decay_90_plus_days" name="time_decay_90_plus_days" 
                                   min="0" max="1" step="0.1" class="small-text" />
                            <p class="description">90天以上文章的权重系数。默认: 0.4</p>
                        </td>
                    </tr>

                    <!-- 置信度参数 -->
                    <tr>
                        <th colspan="2"><h3 class="config-group-title">置信度参数</h3></th>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="confidence_min_articles">置信度最小文章数</label>
                        </th>
                        <td>
                            <input type="number" id="confidence_min_articles" name="confidence_min_articles" 
                                   min="1" max="10" step="1" class="small-text" />
                            <p class="description">结构关联文章数少于此值时应用置信度折扣。默认: 3</p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary" id="save-config-btn">保存配置</button>
                <button type="button" class="button" id="reset-config-btn">恢复默认</button>
                <span class="spinner" id="config-spinner"></span>
            </p>
        </form>
    </div>

    <!-- 数据驱动结构列表 -->
    <div class="optimization-section">
        <h2>数据驱动结构</h2>
        <p class="section-description">从受欢迎文章中自动提取生成的结构模板。</p>
        <div id="data-driven-structures-container">
            <span class="spinner is-active"></span>
            <p>正在加载数据驱动结构...</p>
        </div>
    </div>

    <!-- 多样性监控面板 -->
    <div class="optimization-section">
        <h2>多样性监控</h2>
        <p class="section-description">监控结构选择的多样性，防止过度依赖少数热门结构。</p>
        
        <div class="diversity-dashboard">
            <!-- 熵值概览 -->
            <div class="diversity-card">
                <h3>熵值概览</h3>
                <div id="entropy-overview-container">
                    <span class="spinner is-active"></span>
                </div>
            </div>
            
            <!-- 结构使用分布 -->
            <div class="diversity-card">
                <h3>结构使用分布</h3>
                <div id="usage-distribution-container">
                    <span class="spinner is-active"></span>
                </div>
            </div>
        </div>
        
        <!-- 最近选择日志 -->
        <div class="diversity-card full-width">
            <h3>最近选择日志</h3>
            <div id="recent-selections-container">
                <span class="spinner is-active"></span>
            </div>
        </div>
    </div>

    <!-- 性能对比视图 -->
    <div class="optimization-section">
        <h2>性能对比</h2>
        <p class="section-description">对比 AI 生成结构与数据驱动结构的表现。</p>
        <div id="performance-comparison-container">
            <span class="spinner is-active"></span>
            <p>正在加载性能对比数据...</p>
        </div>
    </div>

    <!-- 手动触发区域 -->
    <div class="optimization-section">
        <h2>手动操作</h2>
        <p class="section-description">手动触发分析任务或更新受欢迎度指数。</p>
        <div class="manual-actions">
            <button type="button" class="button" id="manual-analyze-btn">
                <span class="dashicons dashicons-search"></span> 立即分析高表现文章
            </button>
            <button type="button" class="button" id="manual-update-popularity-btn">
                <span class="dashicons dashicons-update"></span> 更新受欢迎度指数
            </button>
            <button type="button" class="button" id="clear-cache-btn">
                <span class="dashicons dashicons-trash"></span> 清除缓存
            </button>
            <span class="spinner" id="manual-action-spinner"></span>
        </div>
        <div id="manual-action-result"></div>
    </div>
    </div><!-- .tab-content-wrapper -->
</div>
