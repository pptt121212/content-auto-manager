/**
 * API Config Form Inline Scripts
 * Extracted from api-config-form.php for WordPress.org compliance
 * Uses wp.i18n.__() for translations
 */

(function($) {
    'use strict';

    // Wait for DOM ready
    $(function() {
        // ===== Script 1: Search Test AJAX Functionality =====
        
        // Get localized data
        var configData = window.apiConfigFormData || {};
        
                // Test search button click handler
                $('#search-test-btn').on('click', function() {
                    var query = $('#search-test-keyword').val();
                    var resultDiv = $('#search-test-content');
                    
                    if (!query) {
                        alert(wp.i18n.__('请输入搜索关键词', 'yali-ai-writer'));
                        return;
                    }
                    
                    resultDiv.html(wp.i18n.__('正在搜索...', 'yali-ai-writer'));
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'yali_ai_writer_test_search_api',
                            nonce: $('#test_search_nonce_field').val(),
                            query: query
                        },
                        success: function(response) {
                            if (response.success) {
                                var data = response.data;
                                var html = '<p><strong>' + wp.i18n.__('找到结果数：', 'yali-ai-writer') + '</strong> ' + data.count + '</p>';
                                
                                if (data.results && data.results.length > 0) {
                                    $.each(data.results, function(index, item) {
                                        html += '<div style="margin-bottom:15px; border-bottom:1px solid #ddd; padding-bottom:10px;">';
                                        html += '<div style="font-weight:bold; margin-bottom:5px;">';
                                        var pos = (item.position !== undefined) ? item.position : (index + 1);
                                        html += '<span style="color:#666; margin-right:5px;">[' + pos + ']</span>';
                                        html += '<a href="' + item.link + '" target="_blank" style="text-decoration:none;">' + item.title + '</a>';
                                        html += '</div>';
                                        html += '<div style="font-size:13px; line-height:1.5;">' + item.snippet + '</div>';
                                        html += '<div style="font-size:12px; color:var(--yali-success); margin-top:3px;">' + item.link + '</div>';
                                        html += '</div>';
                                    });
                                } else {
                                    html += '<p>' + wp.i18n.__('未找到相关结果。', 'yali-ai-writer') + '</p>';
                                }
                                
                                resultDiv.html(html);
                            } else {
                                resultDiv.html('<span style="color:#dc3232;">' + wp.i18n.__('错误： ', 'yali-ai-writer') + (response.data.message || 'Unknown error') + '</span>');
                            }
                        },
                        error: function(xhr, status, error) {
                            resultDiv.html('<span style="color:#dc3232;">' + wp.i18n.__('系统错误： ', 'yali-ai-writer') + error + '</span>');
                        }
                    });
                });
        // ===== Script 2: Toggle Switches, API Connection Test, API Type Switching =====
        
        // Toggle switches functionality
        function initToggleSwitches() {
            var toggles = document.querySelectorAll('.yali-toggle-input');
            toggles.forEach(function(toggle) {
                toggle.addEventListener('change', function() {
                    var hiddenInput = document.querySelector('input[name="' + this.name.replace('_toggle', '') + '"]');
                    if (hiddenInput) {
                        hiddenInput.value = this.checked ? '1' : '0';
                    }
                });
            });
        }
        initToggleSwitches();

        // Get quota info function
        window.getQuotaInfo = function(channel) {
            var resultElement = document.getElementById('quota-info-result');
            if (!resultElement) return;

            // 只有插件官方API才支持获取配额信息
            if (channel !== 'official') {
                return;
            }

            resultElement.textContent = wp.i18n.__('正在获取配额信息...', 'yali-ai-writer');
            resultElement.className = 'quota-result';

            // 发送AJAX请求
            var data = new URLSearchParams();
            data.append('action', 'yali_ai_writer_get_quota_info');
            data.append('nonce', configData.quotaNonce || '');
            data.append('channel', channel);

            fetch(configData.ajaxUrl || ajaxurl, {
                method: 'POST',
                body: data
            })
            .then(function(response) { return response.json(); })
            .then(function(response) {
                if (response.success) {
                    var quota = response.data.quota_balance || 0;
                    var color = quota > 10 ? '#28a745' : (quota > 0 ? '#ffc107' : '#dc3545');
                    var statusText = quota > 10 ? wp.i18n.__('充足', 'yali-ai-writer') : (quota > 0 ? wp.i18n.__('不足', 'yali-ai-writer') : wp.i18n.__('已用完', 'yali-ai-writer'));
                    resultElement.innerHTML = '<span style="color: ' + color + '; font-size: 18px;">' + quota + ' ' + wp.i18n.__('次', 'yali-ai-writer') + '</span> <span style="color: #666; font-size: 14px;">(' + statusText + ')</span>';
                    resultElement.className = 'quota-result success';
                } else {
                    resultElement.innerHTML = '<span style="color: #dc3232;">' + response.data.message + '</span>';
                    resultElement.className = 'quota-result error';
                }
            })
            .catch(function() {
                resultElement.innerHTML = '<span style="color: #dc3232;">' + wp.i18n.__('获取配额信息失败: 服务器错误', 'yali-ai-writer') + '</span>';
                resultElement.className = 'quota-result error';
            });
        };

        // Test API connection
        $(document).on('click', '.test-api-connection', function(e) {
            e.preventDefault();
            var $button = $(this);
            var configId = $button.data('config-id');
            
            if (!configId) {
                alert(wp.i18n.__('配置ID无效', 'yali-ai-writer'));
                return;
            }

            $button.prop('disabled', true).css('opacity', '0.6');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'yali_ai_writer_test_api_connection',
                    config_id: configId,
                    nonce: configData.nonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        if (typeof window.yaliToast === 'function') {
                            window.yaliToast(response.data.message, 'success');
                        } else {
                            alert(response.data.message);
                        }
                    } else {
                        if (typeof window.yaliToast === 'function') {
                            window.yaliToast(response.data.message, 'error');
                        } else {
                            alert(wp.i18n.__('测试失败: ', 'yali-ai-writer') + response.data.message);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    var errorMessage = wp.i18n.__('连接测试失败: ', 'yali-ai-writer');
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMessage += xhr.responseJSON.data.message;
                    } else {
                        errorMessage += error;
                    }
                    
                    if (typeof window.yaliToast === 'function') {
                        window.yaliToast(errorMessage, 'error');
                    } else {
                        alert(errorMessage);
                    }
                },
                complete: function() {
                    $button.prop('disabled', false).css('opacity', '');
                }
            });
        });

        // API type switching
        var apiTypeSelect = document.getElementById('api-type');
        var apiUrlInput = document.getElementById('api-url');
        var apiUrlDesc = document.getElementById('api-url-desc');
        var modelNameInput = document.querySelector('input[name="model_name"]');

        var apiTypeDefaults = {
            'openai': {
                url: '',
                urlDesc: wp.i18n.__('例如: https://api.openai.com/v1/chat/completions', 'yali-ai-writer'),
                model: 'gpt-3.5-turbo'
            },
            'gemini': {
                url: 'https://generativelanguage.googleapis.com/v1beta/models',
                urlDesc: wp.i18n.__('例如: https://generativelanguage.googleapis.com/v1beta/models', 'yali-ai-writer'),
                model: 'gemini-3-flash-preview'
            },
            'claude': {
                url: 'https://api.anthropic.com/v1/messages',
                urlDesc: wp.i18n.__('例如: https://api.anthropic.com/v1/messages', 'yali-ai-writer'),
                model: 'claude-3-5-sonnet-20241022'
            }
        };

        var allDefaultUrls = [];
        var allDefaultModels = [];
        for (var key in apiTypeDefaults) {
            if (apiTypeDefaults[key].url) {
                allDefaultUrls.push(apiTypeDefaults[key].url);
            }
            if (apiTypeDefaults[key].model) {
                allDefaultModels.push(apiTypeDefaults[key].model);
            }
        }

        function updateApiTypeDefaults(isUserSwitch) {
            if (!apiTypeSelect) return;

            var selectedType = apiTypeSelect.value;
            var defaults = apiTypeDefaults[selectedType];

            if (defaults) {
                if (apiUrlInput) {
                    if (isUserSwitch) {
                        if (!apiUrlInput.value || allDefaultUrls.indexOf(apiUrlInput.value) !== -1) {
                            apiUrlInput.value = defaults.url;
                        }
                    } else {
                        if (!apiUrlInput.value) {
                            apiUrlInput.value = defaults.url;
                        }
                    }
                }
                if (apiUrlDesc) {
                    apiUrlDesc.textContent = defaults.urlDesc;
                }
                if (modelNameInput) {
                    if (isUserSwitch) {
                        if (!modelNameInput.value || allDefaultModels.indexOf(modelNameInput.value) !== -1) {
                            modelNameInput.value = defaults.model;
                        }
                    } else {
                        if (!modelNameInput.value) {
                            modelNameInput.value = defaults.model;
                        }
                    }
                }
            }
        }

        if (apiTypeSelect) {
            apiTypeSelect.addEventListener('change', function() {
                updateApiTypeDefaults(true);
            });
            updateApiTypeDefaults(false);
        }

        // Predefined channel handling
        var predefinedChannelSelect = document.getElementById('predefined-api-channel');
        var predefinedApiUrl = document.getElementById('predefined-api-url');
        var predefinedApiDescription = predefinedApiUrl ? predefinedApiUrl.nextElementSibling : null;
        var predefinedTokenInput = document.querySelector('input[name="predefined_api_token"]');
        var predefinedTokenDescription = predefinedTokenInput ? predefinedTokenInput.parentNode.querySelector('.description') : null;

        function getCurrentSelectedChannel() {
            if (predefinedChannelSelect && predefinedChannelSelect.value && !predefinedChannelSelect.disabled) {
                return predefinedChannelSelect.value;
            }
            var hiddenChannelInput = document.querySelector('input[name="predefined_api_channel"][type="hidden"]');
            if (hiddenChannelInput && hiddenChannelInput.value) {
                return hiddenChannelInput.value;
            }
            if (predefinedChannelSelect) {
                var selectedOption = predefinedChannelSelect.querySelector('option:checked') || 
                                    predefinedChannelSelect.querySelector('option[selected]');
                if (selectedOption && selectedOption.value) {
                    return selectedOption.value;
                }
            }
            return configData.selectedChannel || '';
        }

        function updatePredefinedChannelInfo() {
            var selectedChannel = getCurrentSelectedChannel();
            var modelSelect = document.getElementById('predefined-api-model');
            var savedModel = configData.savedModel || '';
            var isEditMode = configData.isEditConfig || false;

            var tokenRow = predefinedTokenInput ? predefinedTokenInput.closest('tr') : null;
            var quotaRow = document.getElementById('quota-info-row');
            var apiUrlRow = document.getElementById('api-url-row');
            var modelRow = document.getElementById('predefined-model-row');
            var refreshBtn = document.getElementById('refresh-pollinations-models');

            if (modelSelect) {
                modelSelect.innerHTML = '';

                if (selectedChannel === 'pollinations') {
                    if (modelRow) modelRow.style.display = '';
                    if (refreshBtn) refreshBtn.style.display = '';

                    // 编辑模式：显示已保存的模型（仅当没有通过同步加载过模型时）
                    if (isEditMode && savedModel && modelSelect.children.length === 0) {
                        var opt = document.createElement('option');
                        opt.value = savedModel;
                        opt.text = savedModel + ' (' + wp.i18n.__('当前保存的模型', 'yali-ai-writer') + ')';
                        opt.selected = true;
                        modelSelect.appendChild(opt);
                    } else if (modelSelect.children.length === 0) {
                        // 新建模式或编辑模式但未加载模型：显示提示选项
                        var placeholderOpt = document.createElement('option');
                        placeholderOpt.value = '';
                        placeholderOpt.text = wp.i18n.__('↗️ 请先点击"同步多模型"获取可用模型列表', 'yali-ai-writer');
                        placeholderOpt.disabled = true;
                        placeholderOpt.selected = true;
                        modelSelect.appendChild(placeholderOpt);
                    }
                } else if (selectedChannel === 'official') {
                    if (modelRow) modelRow.style.display = 'none';
                    if (refreshBtn) refreshBtn.style.display = 'none';
                }
            }

            // Update channel-specific UI
            if (selectedChannel === 'pollinations') {
                if (predefinedApiUrl) {
                    predefinedApiUrl.textContent = 'https://gen.pollinations.ai/v1/chat/completions';
                }
                if (predefinedApiDescription) {
                    predefinedApiDescription.innerHTML = wp.i18n.__('<strong>模型获取方式：</strong>点击"同步多模型"按钮从 Pollinations 获取最新可用模型列表。<br>系统会自动过滤语音/图像/视频生成模型，仅显示文本生成模型。', 'yali-ai-writer');
                }
                if (predefinedTokenDescription) {
                    predefinedTokenDescription.innerHTML = wp.i18n.__('Pollinations 现在需要 API Key 才能稳定使用。<br><strong>申请地址：</strong><a href="https://enter.pollinations.ai/" target="_blank">https://enter.pollinations.ai/</a><br>使用 API Key 后，不仅连接更稳定，且通过该接口生成的文章内容质量更高。', 'yali-ai-writer');
                }
                if (predefinedTokenInput) {
                    predefinedTokenInput.setAttribute('required', 'required');
                    predefinedTokenInput.setAttribute('placeholder', wp.i18n.__('请输入您的 API Key', 'yali-ai-writer'));
                    var label = tokenRow ? tokenRow.querySelector('th') : null;
                    if (label) label.textContent = wp.i18n.__('API Key (必填)', 'yali-ai-writer');
                }
                if (modelSelect) {
                    modelSelect.setAttribute('required', 'required');
                }
                if (tokenRow) tokenRow.style.display = '';
                if (apiUrlRow) apiUrlRow.style.display = '';
                if (quotaRow) quotaRow.style.display = 'none';

                var accountRow = document.getElementById('pollinations-account-row');
                if (accountRow) {
                    accountRow.style.display = '';
                    var apiKey = predefinedTokenInput ? predefinedTokenInput.value : '';
                    if (apiKey) {
                        getPollinationsAccountInfo(apiKey);
                    } else {
                        var display = document.getElementById('pollinations-account-display');
                        if (display) {
                            display.innerHTML = '<div class="stats-loading">' + 
                                wp.i18n.__('请输入 API Key 以查看账户信息', 'yali-ai-writer') + '</div>';
                        }
                    }
                }
            } else if (selectedChannel === 'official') {
                if (predefinedApiUrl) {
                    predefinedApiUrl.textContent = 'https://key.kdjingpai.com/api-proxy.php';
                }
                if (predefinedApiDescription) {
                    predefinedApiDescription.innerHTML = wp.i18n.__('插件官方API服务，通过授权码验证使用。<br><strong>如何申请使用：</strong><br>1. 联系插件作者微信：qn006699 获取插件授权码后使用<br>2. 在发布规则中配置授权码<br>3. 即可开始使用官方API服务', 'yali-ai-writer');
                }
                if (tokenRow) tokenRow.style.display = 'none';
                if (predefinedTokenInput) {
                    predefinedTokenInput.removeAttribute('required');
                }
                if (modelSelect) {
                    modelSelect.removeAttribute('required');
                }
                if (apiUrlRow) apiUrlRow.style.display = 'none';
                var accountRow = document.getElementById('pollinations-account-row');
                if (accountRow) accountRow.style.display = 'none';

                if (quotaRow) {
                    quotaRow.style.display = '';
                    setTimeout(function() {
                        getQuotaInfo('official');
                    }, 500);
                }
            }
        }

        // Get Pollinations account info
        function getPollinationsAccountInfo(apiKey) {
            var display = document.getElementById('pollinations-account-display');
            if (!display) return;

            display.innerHTML = '<div class="stats-loading"><span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>' + 
                wp.i18n.__('正在拉取账户信息...', 'yali-ai-writer') + '</div>';

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'yali_ai_writer_get_pollinations_account_info',
                    nonce: configData.managerNonce || '',
                    api_key: apiKey
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var html = '';
                        var hasData = false;

                        if (data.balance) {
                            var pVal = parseFloat(data.balance.pollen || 0);
                            var label = data.balance.is_budget ? 
                                wp.i18n.__('Key 预算 (Pollen)', 'yali-ai-writer') : 
                                wp.i18n.__('花粉余额 (Pollen)', 'yali-ai-writer');
                            var color = data.balance.is_budget ? 'var(--yali-warning)' : 'var(--yali-primary)';
                            html += '<div class="pollinations-stat-card"><span class="stat-label">' + label + 
                                '</span><span class="stat-value" style="color:' + color + '">' + 
                                pVal.toFixed(2) + '</span><span class="stat-unit">Credits</span></div>';

                            if (!data.balance.is_budget) {
                                html += '<div class="pollinations-stat-card"><span class="stat-label">' + 
                                    wp.i18n.__('账户金额 (USD)', 'yali-ai-writer') + 
                                    '</span><span class="stat-value">$' + 
                                    parseFloat(data.balance.usd || 0).toFixed(2) + 
                                    '</span><span class="stat-unit">USD</span></div>';
                            }
                            hasData = true;
                        }

                        if (data.profile) {
                            html += '<div class="pollinations-stat-card"><span class="stat-label">' + 
                                wp.i18n.__('账户等级', 'yali-ai-writer') + '</span><span class="stat-value">' + 
                                (data.profile.tier || 'Microbe').toUpperCase() + 
                                '</span><span class="stat-unit">' + (data.profile.email || '') + '</span></div>';
                            hasData = true;
                        }

                        if (data.usage) {
                            html += '<div class="pollinations-stat-card"><span class="stat-label">' + 
                                wp.i18n.__('累计消耗 (Pollen)', 'yali-ai-writer') + '</span><span class="stat-value">' + 
                                parseFloat(data.usage.pollen_spent || 0).toFixed(2) + 
                                '</span><span class="stat-unit">Spent</span></div>';
                            html += '<div class="pollinations-stat-card"><span class="stat-label">' + 
                                wp.i18n.__('累计 Token', 'yali-ai-writer') + '</span><span class="stat-value">' + 
                                parseInt(data.usage.total_tokens || 0).toLocaleString() + 
                                '</span><span class="stat-unit">Total</span></div>';
                            hasData = true;
                        }

                        if (data.daily_usage) {
                            html += '<div class="pollinations-stat-card" style="background: rgba(34, 197, 94, 0.03); border-color: rgba(34, 197, 94, 0.15);"><span class="stat-label">' + 
                                wp.i18n.__('今日消耗 (Pollen)', 'yali-ai-writer') + '</span><span class="stat-value" style="color: var(--yali-success);">' + 
                                parseFloat(data.daily_usage.pollen_spent || 0).toFixed(3) + 
                                '</span><span class="stat-unit">Credits</span></div>';
                            html += '<div class="pollinations-stat-card" style="background: rgba(34, 197, 94, 0.03); border-color: rgba(34, 197, 94, 0.15);"><span class="stat-label">' + 
                                wp.i18n.__('今日 Token', 'yali-ai-writer') + '</span><span class="stat-value" style="color: var(--yali-success);">' + 
                                parseInt(data.daily_usage.total_tokens || 0).toLocaleString() + 
                                '</span><span class="stat-unit">Tokens</span></div>';
                            hasData = true;
                        }

                        var perms = data.permissions || [];
                        if (!perms.includes('balance') || !perms.includes('usage')) {
                            html += '<div class="pollinations-stat-card" style="grid-column: 1 / -1; background: rgba(245, 158, 11, 0.05); border-color: rgba(245, 158, 11, 0.2); text-align:left;">';
                            html += '<span class="stat-label" style="color:var(--yali-warning)">' + 
                                wp.i18n.__('权限提醒 (Missing Scopes)', 'yali-ai-writer') + '</span>';
                            html += '<div style="font-size:12px; line-height:1.4; color:var(--yali-text-muted); padding-top:4px;">' + 
                                wp.i18n.__('当前 API Key 缺失 <b>account:balance</b> 或 <b>account:usage</b> 权限。如需查看完整账单，请在 Pollinations 仪表板重新生成 Key 并勾选相应权限。', 'yali-ai-writer') + 
                                '</div></div>';
                            hasData = true;
                        }

                        if (!hasData) {
                            display.innerHTML = '<div class="stats-loading">' + 
                                wp.i18n.__('未能获取账户详细数据，请确认 API Key 是否有 account 相关权限。', 'yali-ai-writer') + '</div>';
                        } else {
                            display.innerHTML = html;
                        }
                    } else {
                        display.innerHTML = '<div class="stats-loading" style="color: var(--yali-error);">' + 
                            wp.i18n.__('获取失败: ', 'yali-ai-writer') + 
                            (response.data ? response.data.message : wp.i18n.__('密钥失效或接口请求频率限制', 'yali-ai-writer')) + '</div>';
                    }
                },
                error: function() {
                    display.innerHTML = '<div class="stats-loading" style="color: var(--yali-error);">' + 
                        wp.i18n.__('网络连接异常', 'yali-ai-writer') + '</div>';
                }
            });
        }

        // Listen for API key changes
        if (predefinedTokenInput) {
            var timeout = null;
            predefinedTokenInput.addEventListener('input', function() {
                clearTimeout(timeout);
                timeout = setTimeout(function() {
                    var selectedChannel = getCurrentSelectedChannel();
                    if (selectedChannel === 'pollinations' && predefinedTokenInput.value) {
                        getPollinationsAccountInfo(predefinedTokenInput.value);
                    }
                }, 800);
            });
        }

        if (predefinedChannelSelect) {
            predefinedChannelSelect.addEventListener('change', updatePredefinedChannelInfo);
        }

        updatePredefinedChannelInfo();

        // Fetch Pollinations models
        function fetchPollinationsModels() {
            var refreshBtn = document.getElementById('refresh-pollinations-models');
            var statusSpan = document.getElementById('model-refresh-status');
            var modelSelect = document.getElementById('predefined-api-model');
            var currentSelected = modelSelect ? modelSelect.value : '';

            if (refreshBtn) refreshBtn.disabled = true;
            if (statusSpan) statusSpan.style.display = '';

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'yali_ai_writer_fetch_pollinations_models',
                    nonce: configData.managerNonce || ''
                },
                success: function(response) {
                    if (response.success && response.data.models) {
                        if (modelSelect) {
                            modelSelect.innerHTML = '';
                            var friendlyNames = {
                                'openai-large': 'openai-large (' + wp.i18n.__('顶级模型，性能最强', 'yali-ai-writer') + ')',
                                'openai': 'openai (' + wp.i18n.__('标准模型，响应快', 'yali-ai-writer') + ')',
                                'openai-fast': 'openai-fast (' + wp.i18n.__('极速模型', 'yali-ai-writer') + ')',
                                'claude-large': 'claude-large (Anthropic Claude 3.5)',
                                'claude': 'claude (' + wp.i18n.__('Claude 3 标准版', 'yali-ai-writer') + ')',
                                'gemini-large': 'gemini-large (Google Gemini Pro)',
                                'gemini': 'gemini (' + wp.i18n.__('Gemini 标准版', 'yali-ai-writer') + ')',
                                'deepseek': 'deepseek (DeepSeek V3)',
                                'kimi': 'kimi (Moonshot AI)',
                                'qwen-coder': 'qwen-coder (' + wp.i18n.__('通义千问', 'yali-ai-writer') + ')',
                                'grok': 'grok (xAI Grok 2)',
                                'perplexity-reasoning': 'perplexity-reasoning (' + wp.i18n.__('联网搜索推理', 'yali-ai-writer') + ')',
                                'mistral': 'mistral (Mistral AI)',
                                'glm': 'glm (' + wp.i18n.__('智谱清言', 'yali-ai-writer') + ')',
                                'minimax': 'minimax (' + wp.i18n.__('海螺 AI', 'yali-ai-writer') + ')'
                            };

                            // 分离免费和付费模型
                            var freeModels = response.data.models.filter(function(m) { return m.is_free; });
                            var paidModels = response.data.models.filter(function(m) { return !m.is_free; });
                            
                            // 先添加免费模型
                            if (freeModels.length > 0) {
                                var freeGroup = document.createElement('optgroup');
                                freeGroup.label = '🔒 免费模型 (' + freeModels.length + '个)';
                                
                                freeModels.forEach(function(m) {
                                    var opt = document.createElement('option');
                                    opt.value = m.id;
                                    
                                    var displayText = m.id;
                                    var badges = [];
                                    if (m.has_reasoning) badges.push('🧠');
                                    if (m.has_tools) badges.push('🛠️');
                                    
                                    // 显示上下文大小（格式化为 K）
                                    if (m.context_length) {
                                        var contextK = Math.round(m.context_length / 1000);
                                        displayText += ' (' + contextK + 'K)';
                                    }
                                    
                                    if (badges.length > 0) {
                                        displayText += ' ' + badges.join(' ');
                                    }
                                    
                                    opt.text = displayText;
                                    if (m.id === currentSelected) {
                                        opt.selected = true;
                                    }
                                    freeGroup.appendChild(opt);
                                });
                                modelSelect.appendChild(freeGroup);
                            }
                            
                            // 再添加付费模型
                            if (paidModels.length > 0) {
                                var paidGroup = document.createElement('optgroup');
                                paidGroup.label = '💎 付费模型 (' + paidModels.length + '个)';
                                
                                paidModels.forEach(function(m) {
                                    var opt = document.createElement('option');
                                    opt.value = m.id;
                                    
                                    var displayText = m.id;
                                    var badges = [];
                                    if (m.has_reasoning) badges.push('🧠');
                                    if (m.has_tools) badges.push('🛠️');
                                    
                                    // 显示上下文大小（格式化为 K）
                                    if (m.context_length) {
                                        var contextK = Math.round(m.context_length / 1000);
                                        displayText += ' (' + contextK + 'K)';
                                    }
                                    
                                    if (badges.length > 0) {
                                        displayText += ' ' + badges.join(' ');
                                    }
                                    
                                    opt.text = displayText;
                                    if (m.id === currentSelected) {
                                        opt.selected = true;
                                    }
                                    paidGroup.appendChild(opt);
                                });
                                modelSelect.appendChild(paidGroup);
                            }
                            
                            // 显示统计信息
                            var noteText = response.data.note || '';
                            console.log('✓ Pollinations 模型同步: ' + noteText);

                            alert('✨ ' + wp.i18n.__('模型列表同步成功！', 'yali-ai-writer') + '\n\n' + 
                                  (response.data.free_count || 0) + ' 个免费模型\n' +
                                  (response.data.paid_count || 0) + ' 个付费模型');
                        }
                    } else {
                        alert(wp.i18n.__('❌ 获取失败: ', 'yali-ai-writer') + 
                            (response.data ? response.data.message : wp.i18n.__('未知错误', 'yali-ai-writer')));
                    }
                },
                error: function() {
                    alert(wp.i18n.__('网络连接错误，请稍后再试。', 'yali-ai-writer'));
                },
                complete: function() {
                    if (refreshBtn) refreshBtn.disabled = false;
                    if (statusSpan) statusSpan.style.display = 'none';
                }
            });
        }

        var refreshBtn = document.getElementById('refresh-pollinations-models');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', fetchPollinationsModels);
        }

        // Vector API type handling
        var vectorApiTypeSelect = document.getElementById('vector-api-type');
        var vectorUrlInput = document.querySelector('input[name="vector_api_url"]');
        var vectorModelInput = document.getElementById('vector-model-name');
        var vectorModelDescription = document.getElementById('vector-model-description');

        if (vectorApiTypeSelect) {
            function updateVectorFields() {
                var selectedType = vectorApiTypeSelect.value;

                if (selectedType === 'openai') {
                    if (vectorUrlInput && vectorUrlInput.value === '') {
                        vectorUrlInput.value = 'https://api.openai.com/v1/embeddings';
                    }
                    if (vectorModelInput && vectorModelInput.value === '') {
                        vectorModelInput.value = 'text-embedding-ada-002';
                    }
                    if (vectorModelDescription) {
                        vectorModelDescription.textContent = wp.i18n.__('用于向量嵌入的模型名称，例如: text-embedding-ada-002', 'yali-ai-writer');
                    }
                } else if (selectedType === 'jina') {
                    if (vectorUrlInput && vectorUrlInput.value === '') {
                        vectorUrlInput.value = 'https://api.jina.ai/v1/embeddings';
                    }
                    if (vectorModelInput && vectorModelInput.value === '') {
                        vectorModelInput.value = 'jina-embeddings-v4';
                    }
                    if (vectorModelDescription) {
                        vectorModelDescription.textContent = wp.i18n.__('Jina Embeddings v4 固定为1024维，请使用: jina-embeddings-v4', 'yali-ai-writer');
                    }

                    var vectorApiKeyInput = document.querySelector('input[name="vector_api_key"]');
                    if (vectorApiKeyInput) {
                        vectorApiKeyInput.removeAttribute('required');
                        vectorApiKeyInput.setAttribute('placeholder', wp.i18n.__('Jina v4 可选填密钥，留空则允许', 'yali-ai-writer'));
                    }
                }

                if (selectedType === 'openai') {
                    var vectorApiKeyInput = document.querySelector('input[name="vector_api_key"]');
                    if (vectorApiKeyInput) {
                        // 只有在新建模式且不是编辑配置时才添加 required
                        var isEditMode = configData && configData.isEditConfig;
                        if (!isEditMode) {
                            vectorApiKeyInput.setAttribute('required', 'required');
                        } else {
                            vectorApiKeyInput.removeAttribute('required');
                        }
                        vectorApiKeyInput.setAttribute('placeholder', wp.i18n.__('留空则不修改', 'yali-ai-writer'));
                    }
                }
            }

            vectorApiTypeSelect.addEventListener('change', updateVectorFields);
            updateVectorFields();
        }
    });

})(jQuery);
