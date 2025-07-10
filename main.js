(function(){
    'use strict';
    
    // 配置常量
    const CONCURRENT = 3; // 并发数量
    
    // 状态变量
    let processingQueue = []; // 待处理队列
    let failedQueue = new Set(); // 失败队列
    let isProcessing = false; // 处理状态锁
    let totalCount = 0; // 总任务数
    let completedCount = 0; // 已完成数
    let taskId = ''; // 任务ID

    /**
     * 更新状态显示
     */
    function updateStatus() {
        const statusEl = document.getElementById('preprocess-resource-status');
        const failedCount = failedQueue.size;
        const processedCount = completedCount + failedCount;
        const progress = totalCount > 0 ? Math.round((processedCount / totalCount) * 100) : 0;
        
        // 构建失败列表HTML
        const failedListHtml = failedCount > 0 ? `
            <div class="failed-list">
                <strong>失败条目 (${failedCount}):</strong><br>
                ${Array.from(failedQueue).join('<br>')}
            </div>
        ` : '';
        
        // 更新状态HTML
        statusEl.innerHTML = `
            <div>总进度: ${progress}% (${processedCount}/${totalCount})</div>
            <progress value="${progress}" max="100" style="width: 300px;"></progress>
            <div>成功: ${completedCount}, 失败: ${failedCount}</div>
            ${failedListHtml}
            ${isProcessing ? '<div>处理中...</div>' : (processedCount > 0 ? '<div>处理完成</div>' : '')}
        `;
    }

    /**
     * 处理单个URL
     * @param {string} url - 要处理的资源URL
     */
    async function processOne(url) {
        try {
            const response = await fetch(window.ajaxurl, {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'product_import_preprocess_resource',
                    url: url,
                    task_id: taskId
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                completedCount++;
            } else {
                failedQueue.add(url);
            }
        } catch (error) {
            failedQueue.add(url);
        } finally {
            updateStatus();
        }
    }

    /**
     * 处理队列中的任务
     */
    async function processQueue() {
        if (isProcessing || processingQueue.length === 0) return;
        
        isProcessing = true;
        updateStatus();
        
        try {
            // 每次处理CONCURRENT个任务
            while (processingQueue.length > 0) {
                const batch = [];
                // 从队列中取出最多CONCURRENT个任务
                while (batch.length < CONCURRENT && processingQueue.length > 0) {
                    batch.push(processingQueue.shift());
                }
                // 并行处理这批任务
                await Promise.all(batch.map(url => processOne(url)));
            }
        } finally {
            isProcessing = false;
            updateStatus();
        }
    }

    /**
     * 提交表单并获取URL列表
     */
    async function submitForm() {
        const statusEl = document.getElementById('preprocess-resource-status');
        const form = document.getElementById('product-import-form');
        
        if (!form) {
            statusEl.innerHTML = '错误: 未找到表单元素';
            return;
        }
        
        statusEl.innerHTML = '正在解析Excel，请稍候...';
        
        try {
            const formData = new FormData(form);
            formData.append('action', 'product_import_parse_resource_urls');
            
            const response = await fetch(window.ajaxurl, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            // console.log(result);
            
            if (!result.success || !result.data || !Array.isArray(result.data.urls)) {
                statusEl.innerHTML = `解析失败: ${result.data?.msg || '未知错误'}`;
                return;
            }
            
            const urls = result.data.urls;
            if (urls.length === 0) {
                statusEl.innerHTML = '未检测到远程资源URL';
                return;
            }
            
            // 初始化队列和计数器
            processingQueue = [...urls];
            totalCount = urls.length;
            completedCount = 0;
            taskId = result.data.task_id; // 获取任务ID
            document.getElementById('task_id').value = taskId;
            failedQueue.clear();
            
            // 开始处理队列
            processQueue();
        } catch (error) {
            statusEl.innerHTML = `请求错误: ${error.message}`;
        }
    }

    /**
     * 绑定按钮点击事件
     */
    function bindButtonEvent() {
        const btn = document.getElementById('preprocess-resource-btn');
        if (!btn) return;
        
        btn.addEventListener('click', () => {
            if (isProcessing) return; // 防止重复点击
            
            // 如果有失败队列，优先处理失败队列
            if (failedQueue.size > 0) {
                processingQueue = Array.from(failedQueue);
                totalCount = processingQueue.length;
                completedCount = 0;
                failedQueue.clear();
                processQueue();
            } else {
                // 否则提交表单获取新URL
                submitForm();
            }
        });
    }

    // 页面加载完成后初始化
    document.addEventListener('DOMContentLoaded', bindButtonEvent);
})();
