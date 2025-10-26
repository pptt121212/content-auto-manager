/**
 * 变量说明页面JavaScript
 */

jQuery(document).ready(function($) {
    // 标签页切换功能
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();

        // 移除所有活跃状态
        $('.nav-tab').removeClass('nav-tab-active');
        $('.tab-content').removeClass('active');

        // 添加当前活跃状态
        $(this).addClass('nav-tab-active');
        var targetId = $(this).attr('href').substring(1);
        $('#' + targetId).addClass('active');

        // 记录GA事件（如果需要）
        if (typeof gtag !== 'undefined') {
            gtag('event', 'tab_click', {
                'tab_name': $(this).text().trim()
            });
        }
    });

    // 变量卡片悬停效果增强
    $('.variable-card').hover(
        function() {
            $(this).addClass('hovered');
        },
        function() {
            $(this).removeClass('hovered');
        }
    );

    // 复制变量名功能
    $('.variable-name code').on('click', function() {
        var $this = $(this);
        var text = $this.text();

        // 尝试复制到剪贴板
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                showCopyMessage($this, '已复制!');
            }).catch(function() {
                showCopyMessage($this, '复制失败');
            });
        } else {
            // 降级方案
            var textArea = document.createElement('textarea');
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                showCopyMessage($this, '已复制!');
            } catch (err) {
                showCopyMessage($this, '复制失败');
            }
            document.body.removeChild(textArea);
        }
    });

    // 显示复制消息的函数
    function showCopyMessage($element, message) {
        // 检查是否已有消息显示
        var $existingMessage = $element.siblings('.copy-message');
        if ($existingMessage.length > 0) {
            $existingMessage.remove();
        }

        // 创建消息元素
        var $message = $('<span class="copy-message">' + message + '</span>');
        $message.css({
            'position': 'absolute',
            'top': '-25px',
            'right': '0',
            'background': '#333',
            'color': '#fff',
            'padding': '4px 8px',
            'border-radius': '3px',
            'font-size': '12px',
            'z-index': '1000'
        });

        // 添加到DOM并设置定位
        $element.parent().css('position', 'relative');
        $element.after($message);

        // 2秒后自动移除
        setTimeout(function() {
            $message.fadeOut(function() {
                $message.remove();
            });
        }, 2000);
    }

    
    // 添加键盘导航支持
    $(document).on('keydown', function(e) {
        // 如果焦点在标签页上，支持左右箭头切换
        if ($('.nav-tab:focus').length > 0) {
            var $currentTab = $('.nav-tab:focus');
            var $allTabs = $('.nav-tab');
            var currentIndex = $allTabs.index($currentTab);

            if (e.key === 'ArrowLeft' && currentIndex > 0) {
                e.preventDefault();
                $allTabs.eq(currentIndex - 1).click().focus();
            } else if (e.key === 'ArrowRight' && currentIndex < $allTabs.length - 1) {
                e.preventDefault();
                $allTabs.eq(currentIndex + 1).click().focus();
            }
        }
    });

    // 添加打印功能
    $('#print-variable-guide').on('click', function() {
        window.print();
        return false;
    });

    // 打印时隐藏不必要的元素
    if (window.matchMedia) {
        var mediaQueryList = window.matchMedia('print');
        mediaQueryList.addListener(function(mql) {
            if (mql.matches) {
                // 准备打印视图
                $('body').addClass('printing');
            } else {
                // 退出打印视图
                $('body').removeClass('printing');
            }
        });
    }
});