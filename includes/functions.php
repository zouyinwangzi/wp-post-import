<?php
function safe_download_url($url, $timeout = 30)
{
    // sleep(1);
    // return new WP_Error('download_failed', 'Download failed: 1111');
    $chrome_ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36';
    $headers = [
        // 'Referer' => $url,
        'User-Agent' => $chrome_ua,
        'Accept' => 'application/pdf,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
        'Accept-Encoding' => 'gzip, deflate, br',
        'Accept-Language' => 'zh-CN,zh;q=0.9',
        'Cache-Control' => 'no-cache',
        'Pragma' => 'no-cache',
        // 'Cookie' => 'ak_es_cc=cn; ...' // 如有需要可补充
    ];
    // $tmp = download_url($url, $timeout);
    // if (!is_wp_error($tmp)) {
    //     return $tmp;
    // }
    // // 记录download_url失败的详细信息
    // error_log('[safe_download_url] download_url failed: ' . $tmp->get_error_message() . ' | URL: ' . $url);

    // download_url失败，尝试用wp_remote_get
    $response = wp_remote_get($url, [
        'timeout' => $timeout,
        'redirection' => 5,
        'headers' => $headers,
        'decompress' => true,
        'sslverify' => false, // 某些CDN下可尝试关闭SSL验证
    ]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
        // 记录wp_remote_get失败的详细信息
        $msg = is_wp_error($response) ? $response->get_error_message() : 'HTTP error: ' . wp_remote_retrieve_response_code($response);
        error_log('[safe_download_url] wp_remote_get failed: ' . $msg . ' | URL: ' . $url);
        return new WP_Error('download_failed', 'Download failed: ' . $msg);
    }
    $body = wp_remote_retrieve_body($response);
    // 检查内容类型和长度，避免下载到HTML错误页
    $content_type = wp_remote_retrieve_header($response, 'content-type');
    $allowed_types = [
        'pdf',
        'msword',
        'vnd.openxmlformats-officedocument.wordprocessingml.document',
        'vnd.ms-excel',
        'vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip',
        'rar',
        '7z',
        'octet-stream',
        'image',
        'text',
        'csv',
        'json'
    ];
    $is_allowed = false;
    foreach ($allowed_types as $type) {
        if (stripos($content_type, $type) !== false) {
            $is_allowed = true;
            break;
        }
    }
    if (!$is_allowed) {
        error_log('[safe_download_url] suspicious content-type: ' . $content_type . ' | URL: ' . $url);
        return new WP_Error('download_failed', 'Download failed: suspicious content-type: ' . $content_type);
    }
    $tmp = wp_tempnam($url);
    if (!$tmp || !file_put_contents($tmp, $body)) {
        error_log('[safe_download_url] file_put_contents failed | URL: ' . $url);
        return new WP_Error('download_failed', 'Download failed: cannot save file');
    }
    return $tmp;
}



// 开始新任务（清空旧记录）
function product_import_start_new_task($task_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'product_import_failed_urls';

    // 删除旧任务记录（保留最近3个任务）
    $wpdb->query($wpdb->prepare(
        "DELETE FROM $table_name 
         WHERE task_id != %s 
         AND task_id NOT IN (
             SELECT task_id FROM (
                 SELECT DISTINCT task_id 
                 FROM $table_name 
                 ORDER BY created_at DESC 
                 LIMIT 3
             ) AS recent_tasks
         )",
        $task_id
    ));

    // 清除当前任务的所有记录
    $wpdb->delete($table_name, ['task_id' => $task_id]);

    return $task_id;
}

// 生成唯一任务ID
function product_import_generate_task_id()
{
    return 'task_' . md5(uniqid() . current_time('timestamp'));
}


// 记录URL状态（原子操作）
function product_import_set_url_status($task_id, $url, $status, $error = '')
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'product_import_failed_urls';

    $data = [
        'task_id' => $task_id,
        'url_md5' => md5(trim($url)),
        'url' => trim($url),
        'status' => $status,
        'error' => substr($error, 0, 255),
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    ];

    // 使用ON DUPLICATE KEY UPDATE处理重复
    $sql = $wpdb->prepare(
        "INSERT INTO $table_name 
        (task_id, url_md5, url, status, error, created_at, updated_at) 
        VALUES (%s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE 
            status = VALUES(status),
            error = VALUES(error),
            updated_at = VALUES(updated_at)",
        $data['task_id'],
        $data['url_md5'],
        $data['url'],
        $data['status'],
        $data['error'],
        $data['created_at'],
        $data['updated_at']
    );
    file_put_contents(ABSPATH . 'product_import_sql.log', $sql . PHP_EOL, FILE_APPEND);
    $result = $wpdb->query($sql);

    return $result !== false;
}

// 检查URL状态
function product_import_get_url_status($task_id, $url)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'product_import_failed_urls';
    $md5 = md5(trim($url));

    return $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM $table_name 
         WHERE task_id = %s AND url_md5 = %s",
        $task_id,
        $md5
    ));
}

// 标记URL为成功
function product_import_mark_url_success($task_id, $url)
{
    return product_import_set_url_status($task_id, $url, 'success');
}


function failed_import_attachment_log_table($failed_attaches)
{
    $failed_attaches = array_unique($failed_attaches);
    echo '<h2>Failed Import Attachment</h2>
        <div class="product-import-preview-table-wrapper">
            <table class="widefat failed-import-attachment-table">
                <thead><tr>
                <th>Attachment URL</th>
                </tr></thead>
                <tbody>';
    foreach ($failed_attaches as $url) {
        echo '<tr><td>' . $url . '</td></tr>';
    }
    echo '</tbody>
        </table>
    </div>';
}
