<div class="wrap" id="article-structures-page">
    <h1>文章结构管理</h1>
    <p>选择一个内容角度，查看或生成新的文章结构。在发布规则中勾选"文章结构指导"，生成文章中会智能匹配一个文章结构，帮助生成内容一致、结构清晰的文章。</p>
    <p><strong>受欢迎度指数说明：</strong>该指数用于帮助用户识别和选择高质量的文章结构。指数基于两个核心指标：访问量和文章数量。访问量反映文章受欢迎程度，文章数量影响统计可靠性。算法会计算每篇文章的平均访问量，然后根据文章数量进行校正（文章越多，校正越显著），避免单纯追求数量而忽视质量。最终得分由主要质量指标（效率调整后的平均访问量）和辅助指标（文章数量的对数函数）加权组成，确保高访问量结构获得正面评价，同时防止单纯追求数量而忽视质量的行为。</p>

    <div id="structures-container">
        <div id="angle-list-container" class="angle-list-wrapper">
            <h2>内容角度</h2>
            <div id="angle-list" class="angle-list">
                <!-- Angle list will be loaded here by JS -->
                <span class="spinner is-active"></span>
            </div>
        </div>

        <div id="structure-detail-container" class="structure-detail-wrapper">
            <!-- Structures for the selected angle will be shown here -->
            <div class="structure-detail-placeholder">
                <p>请从左侧选择一个内容角度</p>
            </div>
        </div>
    </div>

    <!-- Modal for Associated Articles -->
    <div id="associated-articles-modal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <h2 id="modal-title">关联的文章</h2>
            <div id="modal-body">
                <!-- Article list will be loaded here -->
            </div>
            <button id="modal-close" class="button">关闭</button>
        </div>
    </div>

</div>