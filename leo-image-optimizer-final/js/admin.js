/**
 * Manus Image Optimizer - Admin Panel Script (Optimized)
 * Converted to vanilla JavaScript for better performance and smaller bundle size
 */
(function() {
    'use strict';
    
    var state = {
        totalImages: 0,
        processedCount: 0,
        errorCount: 0,
        isProcessing: false,
        shouldStop: false,
        uiUpdateIntervalId: null
    };
    
    // Helper function for AJAX requests
    function ajax(action, data, callback, errorCallback) {
        data = data || {};
        data.action = action;
        data.security = manusImageOptimizer.nonce;
        
        var xhr = new XMLHttpRequest();
        xhr.open('POST', manusImageOptimizer.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (callback) callback(response);
                } catch (e) {
                    if (errorCallback) errorCallback(xhr, 'parsererror', e);
                }
            } else {
                if (errorCallback) errorCallback(xhr, 'error', null);
            }
        };
        
        xhr.onerror = function() {
            if (errorCallback) errorCallback(xhr, 'error', null);
        };
        
        var params = Object.keys(data).map(function(key) {
            return encodeURIComponent(key) + '=' + encodeURIComponent(data[key]);
        }).join('&');
        
        xhr.send(params);
    }
    
    // Helper functions
    function $(selector) {
        return document.querySelector(selector);
    }
    
    function setText(selector, text) {
        var el = $(selector);
        if (el) el.textContent = text;
    }
    
    function getValue(selector) {
        var el = $(selector);
        return el ? el.value : '';
    }
    
    function setValue(selector, value) {
        var el = $(selector);
        if (el) el.value = value;
    }
    
    function setChecked(selector, checked) {
        var el = $(selector);
        if (el) el.checked = checked;
    }
    
    function isChecked(selector) {
        var el = $(selector);
        return el ? el.checked : false;
    }
    
    function hide(selector) {
        var el = $(selector);
        if (el) el.style.display = 'none';
    }
    
    function show(selector) {
        var el = $(selector);
        if (el) el.style.display = '';
    }
    
    function updateStatus(message, type) {
        type = type || 'info';
        setText('#manus-status', message);
        var colors = { error: '#d63638', success: '#00a32a', info: '#666' };
        var el = $('#manus-status');
        if (el) el.style.color = colors[type] || colors.info;
    }
    
    function updateProgress() {
        var totalWork = state.processedCount + state.errorCount + state.totalImages;
        var progress = 0;
        if (totalWork > 0) {
            progress = Math.round(((state.processedCount + state.errorCount) / totalWork) * 100);
        }
        var fill = $('#manus-progress-fill');
        if (fill) {
            fill.style.width = progress + '%';
            fill.textContent = progress + '% ';
        }
        setText('#manus-processed-count', state.processedCount);
        setText('#manus-remaining-count', state.totalImages);
        setText('#manus-error-count', state.errorCount);
    }
    
    function loadProgress() {
        ajax('manus_get_conversion_progress', {}, function(response) {
            if (response.success) {
                state.processedCount = response.data.processed_count;
                state.errorCount = response.data.error_count;
                updateProgress();
                if (response.data.is_processing) {
                    state.isProcessing = true;
                    hide('#manus-start-conversion');
                    show('#manus-stop-conversion');
                    updateStatus('批量转换正在后台运行...');
                    startUIUpdateInterval();
                } else {
                    finishProcessing();
                }
            }
        });
    }
    
    function startUIUpdateInterval() {
        if (state.uiUpdateIntervalId) clearInterval(state.uiUpdateIntervalId);
        state.uiUpdateIntervalId = setInterval(function() {
            loadProgress();
            getEligibleImagesCount();
        }, 5000);
    }
    
    function stopUIUpdateInterval() {
        if (state.uiUpdateIntervalId) {
            clearInterval(state.uiUpdateIntervalId);
            state.uiUpdateIntervalId = null;
        }
    }
    
    function loadSettings() {
        ajax('manus_get_plugin_settings', {}, function(response) {
            if (response.success) {
                setValue('#manus-quality', response.data.quality);
                setValue('#manus-batch-size', response.data.batch_size);
                setChecked('#manus-auto-convert', response.data.auto_convert == 1);
                setChecked('#manus-delete-original', response.data.delete_original == 1);
                setValue('#manus-processor', response.data.processor);
                setChecked('#manus-lazy-loading', response.data.lazy_loading == 1);
                setChecked('#manus-cdn-enabled', response.data.cdn_enabled == 1);
                setValue('#manus-cdn-url', response.data.cdn_url);
                setValue('#manus-lazy-threshold', response.data.lazy_loading_threshold);
            }
        });
    }
    
    function checkImageLibs() {
        ajax('manus_check_image_libs', {}, function(response) {
            if (response.success) {
                var statusText = '可用处理器：';
                if (response.data.gd_available) {
                    statusText += 'GD Library (支持WebP)';
                } else {
                    statusText += 'GD Library (不支持WebP)';
                    var gdOption = document.querySelector('#manus-processor option[value="gd"]');
                    if (gdOption) gdOption.disabled = true;
                }
                if (response.data.imagick_available) {
                    statusText += ', ImageMagick (可用)';
                } else {
                    statusText += ', ImageMagick (不可用)';
                    var imagickOption = document.querySelector('#manus-processor option[value="imagick"]');
                    if (imagickOption) imagickOption.disabled = true;
                }
                setText('#manus-processor-status', statusText);
            } else {
                setText('#manus-processor-status', '无法检测图片处理器。');
            }
        });
    }
    
    function getEligibleImagesCount() {
        ajax('manus_get_eligible_images_count', {}, function(response) {
            if (response.success) {
                state.totalImages = response.data.count;
                setText('#manus-total-images', state.totalImages);
                updateProgress();
            } else {
                updateStatus('获取图片数量时出错: ' + response.data.message, 'error');
            }
        });
    }
    
    function finishProcessing() {
        state.isProcessing = false;
        state.shouldStop = false;
        show('#manus-start-conversion');
        hide('#manus-stop-conversion');
        stopUIUpdateInterval();
        
        if (state.processedCount >= state.totalImages && state.totalImages > 0) {
            updateStatus('批量转换完成！', 'success');
        } else if (state.totalImages === 0) {
            updateStatus('没有找到需要转换的图片', 'info');
        } else {
            updateStatus('批量转换已停止', 'info');
        }
        
        var totalDone = state.processedCount + state.errorCount;
        var totalWorkForFinish = state.processedCount + state.errorCount + state.totalImages;
        var finalProgress = 0;
        if (totalWorkForFinish > 0) {
            finalProgress = Math.round(((state.processedCount + state.errorCount) / totalWorkForFinish) * 100);
        }
        var fill = $('#manus-progress-fill');
        if (fill) {
            fill.style.width = finalProgress + '%';
            fill.textContent = finalProgress + '% ';
        }
        
        getEligibleImagesCount();
    }
    
    // Event handlers
    function initEventHandlers() {
        var saveBtn = $('#manus-save-settings');
        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                var settings = {
                    quality: getValue('#manus-quality'),
                    batch_size: getValue('#manus-batch-size'),
                    auto_convert: isChecked('#manus-auto-convert') ? 1 : 0,
                    delete_original: isChecked('#manus-delete-original') ? 1 : 0,
                    processor: getValue('#manus-processor'),
                    lazy_loading: isChecked('#manus-lazy-loading') ? 1 : 0,
                    cdn_enabled: isChecked('#manus-cdn-enabled') ? 1 : 0,
                    cdn_url: getValue('#manus-cdn-url'),
                    lazy_loading_threshold: getValue('#manus-lazy-threshold')
                };
                
                // Flatten settings object for proper POST parameter encoding
                var data = {};
                for (var key in settings) {
                    data['settings[' + key + ']'] = settings[key];
                }
                
                ajax('manus_update_plugin_settings', data, function(response) {
                    if (response.success) {
                        updateStatus('设置已保存', 'success');
                    } else {
                        updateStatus('保存设置时出错: ' + response.data.message, 'error');
                    }
                });
            });
        }
        
        var startBtn = $('#manus-start-conversion');
        if (startBtn) {
            startBtn.addEventListener('click', function() {
                if (state.totalImages === 0) {
                    updateStatus('没有找到需要转换的图片', 'info');
                    return;
                }
                
                if (!confirm('确定要开始批量转换吗？此操作将删除原始图片文件。请确保您已经备份了网站数据！')) {
                    return;
                }
                
                state.isProcessing = true;
                state.shouldStop = false;
                state.processedCount = 0;
                state.errorCount = 0;
                updateProgress();
                
                hide('#manus-start-conversion');
                show('#manus-stop-conversion');
                updateStatus('开始批量转换...');
                
                ajax('manus_start_conversion', {}, function(response) {
                    if (response.success) {
                        updateStatus('批量转换已在后台启动，请勿关闭此页面，或定期刷新查看进度...');
                        startUIUpdateInterval();
                    } else {
                        updateStatus('启动批量转换时出错: ' + response.data.message, 'error');
                        finishProcessing();
                    }
                }, function() {
                    updateStatus('AJAX请求失败', 'error');
                    finishProcessing();
                });
            });
        }
        
        var stopBtn = $('#manus-stop-conversion');
        if (stopBtn) {
            stopBtn.addEventListener('click', function() {
                state.shouldStop = true;
                updateStatus('正在停止转换...');
                ajax('manus_stop_conversion', {}, function(response) {
                    if (response.success) {
                        finishProcessing();
                    } else {
                        updateStatus('停止转换时出错: ' + response.data.message, 'error');
                    }
                });
            });
        }
        
        var cleanupBtn = $('#manus-run-cleanup');
        if (cleanupBtn) {
            cleanupBtn.addEventListener('click', function() {
                var formats = getValue('#manus-cleanup-formats');
                var size = getValue('#manus-cleanup-size');
                updateStatus('正在清理原图...');
                ajax('manus_cleanup_original_images', { formats: formats, size: size }, function(response) {
                    if (response.success) {
                        var msg = '原图清理完成，已删除 ' + response.data.deleted + ' 个，保留 ' + response.data.kept + ' 个。';
                        updateStatus(msg, 'success');
                        getEligibleImagesCount();
                    } else {
                        updateStatus('清理原图时出错: ' + response.data.message, 'error');
                    }
                }, function() {
                    updateStatus('AJAX请求失败', 'error');
                });
            });
        }
        
        var mimeTestBtn = $('#manus-run-mime-test');
        if (mimeTestBtn) {
            mimeTestBtn.addEventListener('click', function() {
                var url = getValue('#manus-test-url');
                if (!url) {
                    var resultEl = $('#manus-mime-test-result');
                    if (resultEl) {
                        resultEl.textContent = '请先输入要检测的图片 URL。';
                        resultEl.style.color = '#d63638';
                    }
                    return;
                }
                var resultEl = $('#manus-mime-test-result');
                if (resultEl) {
                    resultEl.textContent = '正在检测...';
                    resultEl.style.color = '#666';
                }
                ajax('manus_test_image_mime', { url: url }, function(response) {
                    if (response.success) {
                        var data = response.data;
                        var info = 'MIME 类型: ' + data.content_type + '\n';
                        if (data.content_length > 0) {
                            var sizeMB = (data.content_length / (1024 * 1024)).toFixed(2);
                            info += '文件大小: ' + sizeMB + ' MB\n';
                        }
                        if (data.cache_header) {
                            info += '缓存信息:\n' + data.cache_header + '\n';
                        }
                        if (data.is_webp) {
                            info += '检测结果: 返回 WebP 格式。';
                        } else if (data.is_avif) {
                            info += '检测结果: 返回 AVIF 格式。';
                        } else {
                            info += '检测结果: 未返回 WebP/AVIF。';
                        }
                        if (resultEl) {
                            resultEl.textContent = info;
                            resultEl.style.whiteSpace = 'pre-wrap';
                            resultEl.style.color = '#00a32a';
                        }
                    } else {
                        if (resultEl) {
                            resultEl.textContent = '检测失败: ' + response.data.message;
                            resultEl.style.color = '#d63638';
                        }
                    }
                }, function() {
                    if (resultEl) {
                        resultEl.textContent = 'AJAX请求失败';
                        resultEl.style.color = '#d63638';
                    }
                });
            });
        }
    }
    
    // Initialize
    function init() {
        loadSettings();
        loadProgress();
        getEligibleImagesCount();
        checkImageLibs();
        initEventHandlers();
    }
    
    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
