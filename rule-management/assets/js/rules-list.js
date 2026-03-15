jQuery(document).ready(function ($) {
    // 处理页面加载时的 Toast 提示 (来自 URL 参数)
    const urlParams = new URLSearchParams(window.location.search);
    const messageCode = urlParams.get('message');

    if (messageCode) {
        if (typeof window.yaliToast === 'function' && typeof camRulesListI18n !== 'undefined') {
            switch (messageCode) {
                case '1':
                    window.yaliToast(camRulesListI18n.addSuccess, 'success');
                    break;
                case '2':
                    window.yaliToast(camRulesListI18n.saveError, 'error');
                    break;
                case '3':
                    window.yaliToast(camRulesListI18n.updateSuccess, 'success');
                    break;
                case '4':
                    window.yaliToast(camRulesListI18n.deleteSuccess, 'success');
                    break;
                case '5':
                    window.yaliToast(camRulesListI18n.deleteError, 'error');
                    break;
            }

            const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?page=yali-ai-writer-rules';
            window.history.replaceState({ path: newUrl }, '', newUrl);
        }
    }


    // 为编辑按钮添加提示（如果被禁用）
    const editButtons = document.querySelectorAll('button:disabled[title*="编辑"]');
    editButtons.forEach(button => {
        button.style.cursor = 'not-allowed';
        button.addEventListener('click', function (e) {
            e.preventDefault();
            if (typeof camRulesListI18n !== 'undefined') {
                alert(camRulesListI18n.ruleInUseEdit);
            }
        });
    });

    // 为删除按钮添加提示（如果被禁用）
    const disabledDeleteButtons = document.querySelectorAll('button:disabled[title*="删除"]');
    disabledDeleteButtons.forEach(button => {
        button.style.cursor = 'not-allowed';
        button.addEventListener('click', function (e) {
            e.preventDefault();
            if (typeof camRulesListI18n !== 'undefined') {
                alert(camRulesListI18n.ruleInUseDelete);
            }
        });
    });
});
