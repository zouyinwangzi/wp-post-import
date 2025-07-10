<?php
// 资源预处理：下载资源到媒体库并标记来源URL

add_action('wp_ajax_product_import_preprocess_resource', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['msg' => 'Permission denied']);
    }
    $url = isset($_POST['url']) ? trim($_POST['url']) : '';
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        wp_send_json_error(['msg' => 'Invalid URL']);
    }

    $task_id = isset($_POST['task_id']) ? $_POST['task_id'] : '';

    // 检查是否已处理过
    $current_status = product_import_get_url_status($task_id, $url);
    if ($current_status === 'success') {
        wp_send_json_success(['msg' => 'Already processed']);
        return;
    }


    $md5 = md5(trim($url)); // 修正：确保md5计算前去除空格

    // 查找是否已存在
    $exists = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'meta_key' => '_import_src_md5',
        'meta_value' => $md5,
        'posts_per_page' => 1,
        'fields' => 'ids',
    ]);
    if ($exists) {
        $attach_id = $exists[0];
        $attach_url = wp_get_attachment_url($attach_id);
        wp_send_json_success(['msg' => 'Already exists', 'attachment_id' => $attach_id, 'url' => $attach_url]);
    }

    // 下载并导入
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $tmp = safe_download_url($url);


    if (is_wp_error($tmp)) {
        // 记录失败状态
        product_import_set_url_status(
            $task_id, 
            $url, 
            'failed', 
            $tmp->get_error_message()
        );

        wp_send_json_error(['msg' => 'Download failed: ' . $tmp->get_error_message()]);
    }


    $filename = basename(parse_url($url, PHP_URL_PATH));


    $file_array = [
        'name' => $filename,
        'tmp_name' => $tmp,
    ];
    $attach_id = media_handle_sideload($file_array, 0);
    @unlink($tmp);
    if (is_wp_error($attach_id)) {
        wp_send_json_error(['msg' => 'Media import failed: ' . $attach_id->get_error_message()]);
        return;
    }
    // 标记来源
    add_post_meta($attach_id, '_import_src_md5', $md5, true);
    add_post_meta($attach_id, '_import_src_url', $url, true);
    $attach_url = wp_get_attachment_url($attach_id);
    
    // 标记为成功
    product_import_mark_url_success($task_id, $url);
    
    wp_send_json_success(['msg' => 'Imported', 'attachment_id' => $attach_id, 'url' => $attach_url]);
});

/**
 * 根据资源URL的md5查找媒体ID，若存在则返回附件ID，否则返回false
 * @param string $url
 * @return int|false
 */
function product_import_find_attachment_by_url($url)
{
    $md5 = md5(trim($url));
    $exists = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'meta_key' => '_import_src_md5',
        'meta_value' => $md5,
        'posts_per_page' => 1,
        'fields' => 'ids',
    ]);
    if ($exists) {
        return $exists[0];
    }
    return false;
}

/**
 * 根据资源URL获取媒体信息（ID、URL），不存在返回false
 * @param string $url
 * @return array|false
 */
function product_import_get_attachment_info_by_url($url)
{
    $attach_id = product_import_find_attachment_by_url($url);
    if ($attach_id) {
        return [
            'ID' => $attach_id,
            'url' => wp_get_attachment_url($attach_id),
            'title' => get_the_title($attach_id),
            'filename' => basename(get_attached_file($attach_id)),
            'filesize' => filesize(get_attached_file($attach_id)),
            'mime_type' => get_post_mime_type($attach_id),
        ];
    }
    return false;
}

/**
 * 通过资源URL获取已存在的媒体ID，如果不存在则尝试下载并导入，返回媒体ID或false
 * @param string $url
 * @return int|false
 */
function product_import_ensure_attachment_by_url($url)
{
    $attach_id = product_import_find_attachment_by_url($url);
    if ($attach_id) {
        return $attach_id;
    }
    // 下载并导入
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $tmp = safe_download_url($url);
    if (is_wp_error($tmp)) {
        return false;
    }
    $filename = basename(parse_url($url, PHP_URL_PATH));
    $file_array = [
        'name' => $filename,
        'tmp_name' => $tmp,
    ];
    $attach_id = media_handle_sideload($file_array, 0);
    @unlink($tmp);
    if (is_wp_error($attach_id)) {
        return false;
    }
    // 标记来源
    if (!is_wp_error($attach_id)) {
        $md5 = md5(trim($url));
    }
    add_post_meta($attach_id, '_import_src_md5', $md5, true);
    add_post_meta($attach_id, '_import_src_url', $url, true);
    return $attach_id;
}

/**
 * 通过资源URL的md5查找媒体ID（兼容URL参数不同但实际文件相同的情况）
 * 支持忽略URL参数，仅比对主路径的md5
 * 用于CDN、带token等动态参数的资源去重。
 * @param string $url
 * @return int|false
 */
function product_import_find_attachment_by_url_loose($url)
{
    // 解析URL，去掉参数部分，只保留协议、主机、路径
    $parsed = parse_url($url);
    if (!$parsed || empty($parsed['scheme']) || empty($parsed['host']) || empty($parsed['path'])) {
        return false;
    }
    $main_url = $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'];
    $md5 = md5($main_url);
    $exists = get_posts([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'meta_key' => '_import_src_md5',
        'meta_value' => $md5,
        'posts_per_page' => 1,
        'fields' => 'ids',
    ]);
    if ($exists) {
        return $exists[0];
    }
    return false;
}

/**
 * 用法说明：
 * 
 * 1. product_import_find_attachment_by_url($url)
 *    - 用完整URL的md5查找媒体ID，适合URL完全一致的资源。
 * 
 * 2. product_import_get_attachment_info_by_url($url)
 *    - 获取媒体详细信息（ID、URL、标题、文件名、大小、类型），找不到返回false。
 * 
 * 3. product_import_ensure_attachment_by_url($url)
 *    - 先查找媒体ID，找不到则自动下载并导入，返回媒体ID或false。
 * 
 * 4. product_import_find_attachment_by_url_loose($url)
 *    - 只用主路径（忽略参数）查找媒体ID，适合CDN带token、参数变化但实际文件相同的场景。
 * 
 * 推荐流程：
 *   - 优先用 find_attachment_by_url 查找，找不到再用 find_attachment_by_url_loose。
 *   - 需要时用 ensure_attachment_by_url 自动补全导入。
 *   - 这些函数可在主导入流程、资源预处理、图片/附件字段处理等场景灵活调用。
 */

add_action('wp_ajax_product_import_parse_resource_urls', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['msg' => 'Permission denied']);
    }
    // 只支持上传的Excel文件
    if (empty($_FILES['products_file']['tmp_name'])) {
        wp_send_json_error(['msg' => '请先选择产品Excel文件']);
    }

    // 生成新任务ID
    $task_id = product_import_generate_task_id();
    file_put_contents(ABSPATH . 'product_import_task_id.txt', $task_id);

    // 开始新任务（清理旧记录）
    // product_import_start_new_task($task_id);


    require_once(ABSPATH . 'wp-admin/includes/file.php');
    $tmp = $_FILES['products_file']['tmp_name'];
    try {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            require_once plugin_dir_path(__DIR__) . 'vendor/autoload.php';
        }
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);
        $urls = [];
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if (is_string($cell) && preg_match_all('/https?:\/\/[^\s"\'<>]+/i', $cell, $m)) {
                    foreach ($m[0] as $url) {
                        $urls[] = $url;
                    }
                }
            }
        }
        $urls = array_unique($urls);

        // 初始化URL状态为pending
        foreach ($urls as $url) {
            product_import_set_url_status($task_id, $url, 'pending');
        }

        wp_send_json_success([
            'urls' => array_values($urls),
            'task_id' => $task_id
        ]);
    } catch (Exception $e) {
        wp_send_json_error(['msg' => $e->getMessage()]);
    }
});

/**
 * 前端AJAX资源预处理说明：
 * 
 * 1. 用户在导入页面点击“资源预处理”按钮，前端JS会将上传的Excel文件通过AJAX提交到后台。
 * 2. 后台 `product_import_parse_resource_urls` 钩子会解析Excel，提取所有远程资源URL（如图片、PDF等）。
 * 3. 前端收到URL列表后，逐个用AJAX调用 `product_import_preprocess_resource` 钩子，后台负责下载并导入到媒体库，并做唯一标记。
 * 4. 前端实时显示进度，全部处理完毕后提示用户。
 * 
 * 这样可以在正式导入前，提前将所有远程资源缓存到本地，极大提升后续导入的速度和稳定性。
 * 
 * 后续可扩展：
 * - 支持分类Excel的资源提取
 * - 支持失败重试、断点续传
 * - 支持多文件并发处理
 * - 支持资源类型筛选（如只处理图片、只处理PDF等）
 */
