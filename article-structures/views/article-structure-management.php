<div class="wrap" id="article-structures-page">
    <h1 class="yali-page-title"><span class="dashicons dashicons-layout"></span> <?php _e('文章结构管理', 'yali-ai-writer'); ?></h1>

    <div style="margin-bottom: 20px;">
        <a href="?page=yali-ai-writer-publish-rules" class="yali-btn yali-btn-secondary">
            <span class="dashicons dashicons-arrow-left-alt"></span> <?php _e('返回发布规则', 'yali-ai-writer'); ?>
        </a>
    </div>
    
    <!-- 标签导航 -->
    <?php
    $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'structures';
    ?>
    <div class="yali-tabs">
        <a href="admin.php?page=yali-ai-writer-publish-rules&action=article-structures&tab=structures" class="yali-tab-item <?php echo $current_tab === 'structures' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-networking"></span> <?php _e('结构管理', 'yali-ai-writer'); ?>
        </a>
        <a href="admin.php?page=yali-ai-writer-publish-rules&action=article-structures&tab=smart-optimization" class="yali-tab-item <?php echo $current_tab === 'smart-optimization' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-chart-line"></span> <?php _e('智能优化', 'yali-ai-writer'); ?>
        </a>
    </div>
    
    <?php if ($current_tab === 'smart-optimization') : ?>
        <?php 
        // 标记为嵌入模式，防止子视图重复渲染导航
        $yali_smart_opt_embedded = true;
        
        // 加载智能优化视图
        include_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-structures/views/smart-optimization-settings.php'; 
        ?>
    <?php else : ?>
        <div class="yali-grid-layout" style="margin-top:20px; grid-template-columns: 280px 1fr !important; gap: 20px;">
            <!-- 左侧：内容角度列表 -->
            <div class="yali-card" style="padding:0; overflow:hidden; display:flex; flex-direction:column;">
                <div class="yali-panel-header" style="border-bottom:1px solid #eee; padding:15px;">
                    <h3 style="margin:0; font-size:16px;"><?php _e('内容角度', 'yali-ai-writer'); ?></h3>
                </div>
                <div id="angle-list" class="angle-list" style="flex:1; overflow-y:auto;">
                    <!-- Angle list will be loaded here by JS -->
                    <div style="padding:20px; text-align:center;"><span class="spinner is-active"></span></div>
                </div>
            </div>

            <!-- 右侧：结构详情 -->
            <div class="yali-card" style="min-height:500px; display:flex; flex-direction:column;">
                <div class="yali-notice yali-notice-info" style="margin-bottom:20px;">
                    <p><strong><?php _e('说明：', 'yali-ai-writer'); ?></strong> <?php _e('选择左侧内容角度查看或生成结构。受欢迎度指数基于访问量和文章数量计算。', 'yali-ai-writer'); ?></p>
                </div>

                <div id="structure-detail-container" class="structure-detail-wrapper" style="flex:1;">
                    <!-- Structures for the selected angle will be shown here -->
                    <div class="structure-detail-placeholder" style="background:#f9f9f9; border:1px dashed #ddd; border-radius:4px; padding:40px; text-align:center; color:#666;">
                        <p style="font-size:15px; margin-bottom:5px;">👈 <?php _e('请从左侧选择一个内容角度', 'yali-ai-writer'); ?></p>
                        <p style="font-size:13px; opacity:0.8;"><?php _e('系统将显示该角度下的所有可用文章结构', 'yali-ai-writer'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal for Associated Articles -->
        <div id="associated-articles-modal" class="modal-overlay" style="display: none;">
            <div class="modal-content yali-card" style="box-shadow: 0 5px 15px rgba(0,0,0,0.2);">
                <h2 id="modal-title" style="margin-top:0;"><?php _e('关联的文章', 'yali-ai-writer'); ?></h2>
                <div id="modal-body">
                    <!-- Article list will be loaded here -->
                </div>
                <button id="modal-close" class="button yali-btn yali-btn-secondary" style="margin-top:20px;"><?php _e('关闭', 'yali-ai-writer'); ?></button>
            </div>
        </div>
    <?php endif; ?>

</div>