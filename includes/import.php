<?php
// product-import/includes/import.php

// require_once plugin_dir_path(__FILE__) . 'rollback.php';

class Product_Import_Handler
{
    protected static $task_id;
    protected static function get_sheet_data($sheet_row, $col_name)
    {
        if (isset($sheet_row[$col_name])) {
            return $sheet_row[$col_name];
        }
        return '';
    }
    public static function handle_import()
    {
        $failed_attaches = [];
        // ...existing handle_import 逻辑整体迁移到这里...
        // 由于内容较多，建议直接将原 handle_import 方法体粘贴到这里，并将$this->替换为self::或静态调用
        // require_once plugin_dir_path(__FILE__) . 'includes/converter.php';
        // 获取HTML转换选项
        $html_convert_options = isset($_POST['html_convert_options']) ? (array)$_POST['html_convert_options'] : array();
        self::$task_id = isset($_POST['task_id']) && $_POST['task_id'] ? sanitize_text_field($_POST['task_id']) : product_import_generate_task_id();

        // var_dump(self::$task_id); /////////////////

        $upload_dir = wp_upload_dir();
        $import_base = trailingslashit($upload_dir['basedir']) . 'import/';
        if (!file_exists($import_base)) {
            wp_mkdir_p($import_base);
        }
        $unique_folder = $import_base . 'import_' . date('Ymd_His') . '_' . wp_generate_password(6, false) . '/';
        wp_mkdir_p($unique_folder);

        $errors = array();
        $success = array();

        // 处理分批导入的唯一文件夹
        $import_folder_path = '';
        if (!empty($_POST['import_folder_path']) && is_dir($_POST['import_folder_path'])) {
            $unique_folder = rtrim($_POST['import_folder_path'], '/') . '/';
            $import_folder_path = $unique_folder;
        } else {
            $import_folder_path = $unique_folder;
        }

        // Handle products Excel
        $products_file = '';
        if (!empty($_FILES['products_file']['tmp_name'])) {
            $products_file = $unique_folder . basename($_FILES['products_file']['name']);
            if (move_uploaded_file($_FILES['products_file']['tmp_name'], $products_file)) {
                $success[] = 'Products Excel uploaded.';
            } else {
                $errors[] = 'Failed to upload Products Excel.';
            }
        } elseif (!empty($_POST['products_file_path']) && file_exists($_POST['products_file_path'])) {
            $products_file = $_POST['products_file_path'];
        }

        // Handle categories Excel
        $categories_file = '';
        if (!empty($_FILES['categories_file']['tmp_name'])) {
            $categories_file = $unique_folder . basename($_FILES['categories_file']['name']);
            if (move_uploaded_file($_FILES['categories_file']['tmp_name'], $categories_file)) {
                $success[] = 'Categories Excel uploaded.';
            } else {
                $errors[] = 'Failed to upload Categories Excel.';
            }
        } elseif (!empty($_POST['categories_file_path']) && file_exists($_POST['categories_file_path'])) {
            $categories_file = $_POST['categories_file_path'];
        }

        // Handle attachments ZIP（仅第一批上传和解压）
        if (!empty($_FILES['attachments_zip']['tmp_name']) && empty($_POST['import_folder_path'])) {
            $zip_file = $unique_folder . basename($_FILES['attachments_zip']['name']);
            if (move_uploaded_file($_FILES['attachments_zip']['tmp_name'], $zip_file)) {
                $zip = new ZipArchive();
                if ($zip->open($zip_file) === TRUE) {
                    $zip->extractTo($unique_folder);
                    $zip->close();
                    $success[] = 'Attachments ZIP extracted.';
                } else {
                    $errors[] = 'Failed to extract ZIP file.';
                }
            } else {
                $errors[] = 'Failed to upload ZIP file.';
            }
        }

        // 递归查找资源文件夹
        $product_dir = self::find_subdir($import_folder_path, 'pi-product');
        $download_dir = self::find_subdir($import_folder_path, 'pi-download');
        $category_dir = self::find_subdir($import_folder_path, 'pi-category');

        // 获取重名处理选项
        $duplicate_action = isset($_POST['duplicate_title_action']) ? $_POST['duplicate_title_action'] : 'insert_new';

        // Show result
        if ($errors) {
            echo '<div class="notice notice-error"><ul><li>' . implode('</li><li>', $errors) . '</li></ul></div>';
        }
        if ($success) {
            echo '<div class="notice notice-success"><ul><li>' . implode('</li><li>', $success) . '</li></ul></div>';
        }

        // Excel parsing and data import (structure only)
        if (!empty($products_file) && file_exists($products_file)) {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($products_file);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, true);
                // 字段名到列号的映射
                $header_map = array();
                foreach ($rows[1] as $col => $field) {
                    $header_map[trim($field)] = $col;
                }
                // 获取分批参数
                $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 100;
                $batch_start = isset($_POST['batch_start']) ? intval($_POST['batch_start']) : 2;
                $total_rows = count($rows);
                $batch_end = min($batch_start + $batch_size - 1, $total_rows);
                echo '<h2>Products Preview</h2>
                <div class="product-import-preview-table-wrapper">
                <table class="widefat product-import-preview-table"><thead><tr>';
                foreach ($rows[1] as $header) {
                    echo '<th>' . esc_html($header) . '</th>';
                }
                echo '</tr></thead><tbody>';
                for ($i = $batch_start; $i <= $batch_end; $i++) {
                    echo '<tr>';
                    foreach ($rows[$i] as $cell) {
                        echo '<td>' . esc_html(Product_Import_Data_Converter::convert_html($cell, $html_convert_options)) . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody>
                </table>
                </div>
                
                ';



                // Import to WordPress
                $imported = 0;
                // 导入日志收集
                $import_log = [
                    'post_ids' => [],
                    'term_ids' => [],
                    'attachment_ids' => [],
                    'file_name' => basename($products_file),
                    'import_type' => 'products',
                    'updated_posts' => [], // 新增：记录被覆盖文章的原始数据
                ];
                // 清空富文本图片收集器
                Product_Import_Data_Converter::$imported_attachment_ids = [];
                for ($i = $batch_start; $i <= $batch_end; $i++) {
                    $row = $rows[$i];
                    // 用字段名取值
                    $post_title = self::get_sheet_data($row, $header_map['Product Model']);
                    $title = self::get_sheet_data($row, $header_map['Product Model']);
                    $post_content = self::get_sheet_data($row, $header_map['Post Content']);
                    $post_excerpt = self::get_sheet_data($row, $header_map['Excerpt']);
                    $categories = self::get_sheet_data($row, $header_map['Categories']);
                    $featured_image = trim(self::get_sheet_data($row, $header_map['Featured Image']));

                    $brand = self::get_sheet_data($row, $header_map['Brand']);
                    $description = self::get_sheet_data($row, $header_map['Description']);
                    $ranking = self::get_sheet_data($row, $header_map['Ranking']);
                    $package_case = self::get_sheet_data($row, $header_map['Package Case']);
                    $package = self::get_sheet_data($row, $header_map['Package']);
                    $moisture_sensitivity_level = self::get_sheet_data($row, $header_map['Moisture Sensitivity Level']);
                    $standard_package = self::get_sheet_data($row, $header_map['Standard Package']);
                    $detailed_description = self::get_sheet_data($row, $header_map['Detailed Description']);
                    $operating_temperature = self::get_sheet_data($row, $header_map['Operating Temperature']);
                    $desc3 = self::get_sheet_data($row, $header_map['Desc3'] ?? '');
                    $download_file = trim(self::get_sheet_data($row, $header_map['Datasheet']));
                    // var_dump($download_file);

                    // 处理空值
                    if (empty($post_title)) continue;


                    // 富文本字段转换
                    $rich_fields = ['post_content' => &$post_content, 'description' => &$description,  'desc3' => &$desc3];
                    foreach ($rich_fields as $k => &$v) {
                        if (!empty($v)) {
                            $v = Product_Import_Data_Converter::convert_html($v, $html_convert_options, $product_dir);
                            $v = Product_Import_Data_Converter::process_images($v, $product_dir, $post_id ?? 0);
                        }
                    }


                    // 处理重名
                    $existing = self::get_component_by_title($post_title);
                    if ($existing) {
                        if ($duplicate_action === 'overwrite') {
                            $post_id = $existing->ID;
                            // 记录原始数据
                            $original = [
                                'post_title' => $existing->post_title,
                                'post_content' => $existing->post_content,
                                'post_excerpt' => $existing->post_excerpt,
                                'post_status' => $existing->post_status,
                                'post_type' => $existing->post_type,
                                'post_author' => $existing->post_author,
                                'post_date' => $existing->post_date,
                                'post_modified' => $existing->post_modified,
                                'post_parent' => $existing->post_parent,
                                'post_name' => $existing->post_name,
                                'post_mime_type' => $existing->post_mime_type,
                                'post_password' => $existing->post_password,
                                'menu_order' => $existing->menu_order,
                                'comment_status' => $existing->comment_status,
                                'ping_status' => $existing->ping_status,
                                'guid' => $existing->guid,
                                'categories' => wp_get_object_terms($post_id, 'product-category', ['fields' => 'names']),
                                'acf' => [],
                                'thumbnail_id' => get_post_thumbnail_id($post_id),
                            ];
                            // 获取所有ACF字段
                            if (function_exists('get_fields')) {
                                $original['acf'] = get_fields($post_id);
                            }
                            $import_log['updated_posts'][$post_id] = $original;
                            $post_data = array(
                                'ID'           => $post_id,
                                'post_title'   => $post_title,
                                'post_content' => $post_content,
                                'post_excerpt' => $post_excerpt ?? '',
                                'post_type'    => 'components',
                                'post_status'  => 'publish',
                            );
                            wp_update_post($post_data);
                        } elseif ($duplicate_action === 'skip') {
                            continue;
                        } else { // insert_new
                            $post_data = array(
                                'post_title'    => $post_title,
                                'post_content'  => $post_content,
                                'post_excerpt'  => $post_excerpt ?? '',
                                'post_type'     => 'components',
                                'post_status'   => 'publish',
                            );
                            $post_id = wp_insert_post($post_data);
                        }
                    } else {
                        $post_data = array(
                            'post_title'    => $post_title,
                            'post_content'  => $post_content,
                            'post_excerpt'  => $post_excerpt ?? '',
                            'post_type'     => 'components',
                            'post_status'   => 'publish',
                        );
                        // var_dump($post_data);
                        $post_id = wp_insert_post($post_data, true);
                        // var_dump($post_id);
                    }
                    if (is_wp_error($post_id) || !$post_id) continue;
                    $import_log['post_ids'][] = $post_id;
                    // Categories
                    $cats = array();
                    if (!empty($categories)) {
                        $cat_names = array_map('trim', explode(',', $categories));
                        foreach ($cat_names as $cat_name) {
                            $term = term_exists($cat_name, 'product-category');
                            if (!$term) {
                                $term = wp_insert_term($cat_name, 'product-category');
                            }
                            if (!is_wp_error($term) && isset($term['term_id'])) {
                                $cats[] = intval($term['term_id']);
                            }
                        }
                    }
                    if ($cats) {
                        wp_set_object_terms($post_id, $cats, 'product-category');
                    }
                    // ACF fields
                    $acf_fields = [
                        'title' => $title,
                        'manufacturer' => $brand,
                        'description' => $description,
                        'ranking' => $ranking,
                        'desc1' => Product_Import_Data_Converter::convert_to_table_html([
                            'Product Model' => $post_title,
                            'Brand' => $brand,
                            'Description' => $description,
                        ], 'desc1_table'),
                        'desc2' => Product_Import_Data_Converter::convert_to_table_html([
                            'Package Case' => $package_case,
                            'Package' => $package,
                            'Moisture Sensitivity Level' => $moisture_sensitivity_level,
                            'Standard Package' => $standard_package,
                            'Operating Temperature' => $operating_temperature,
                        ], 'desc2_table'),
                        'desc3' => $detailed_description,
                    ];
                    foreach ($acf_fields as $field_name => $value) {
                        $field_obj = get_field_object($field_name, $post_id);
                        if ($field_obj && isset($field_obj['key'])) {
                            update_field($field_obj['key'], $value, $post_id);
                        } else {
                            update_field($field_name, $value, $post_id);
                        }
                    }
                    // Featured Image
                    // if ($featured_image && $product_dir) {
                    //     $img_path = $product_dir . '/' . $featured_image;
                    //     if (file_exists($img_path)) {
                    //         $attach_id = self::import_media($img_path, $post_id, $product_dir);
                    //         if ($attach_id) {
                    //             set_post_thumbnail($post_id, $attach_id);
                    //             $import_log['attachment_ids'][] = $attach_id;
                    //         }
                    //     }
                    // }
                    if ($featured_image) {
                        $attach_id = self::import_media($featured_image, $post_id, $product_dir);
                        if ($attach_id) {
                            set_post_thumbnail($post_id, $attach_id);
                            $import_log['attachment_ids'][] = $attach_id;
                        }
                    }



                    // download_file
                    // echo "download_file:$download_file<br>"; ///////////////////
                    if ($download_file) {
                        $attach_id = self::import_media($download_file, $post_id, $download_dir);
                        // echo "download_file is File ,attach id is: $attach_id <br>"; ///////////////////
                        // if (filter_var($download_file, FILTER_VALIDATE_URL)) { //file is URL
                        //     $attach_id = self::import_media($download_file, $post_id);
                        //     echo "download_file is URL,attach id is: $attach_id <br>"; //////////////////
                        // } else {
                        //     $file_path = (string)$download_dir . '/' . $download_file;
                        //     if ($download_dir && file_exists($file_path)) {
                        //         $attach_id = self::import_media($file_path, $post_id);
                        //         echo "download_file is File ,attach id is: $attach_id <br>"; ///////////////////
                        //     }
                        // }
                        // var_dump($attach_id);
                        if ($attach_id) {
                            $field_obj = get_field_object('download_file', $post_id);
                            $acf_file = array(
                                'ID' => $attach_id,
                                'url' => wp_get_attachment_url($attach_id),
                                'title' => get_the_title($attach_id),
                                'filename' => basename(get_attached_file($attach_id)),
                                'filesize' => filesize(get_attached_file($attach_id)),
                                'mime_type' => get_post_mime_type($attach_id),
                            );
                            if ($field_obj && isset($field_obj['key'])) {
                                update_field($field_obj['key'], $acf_file, $post_id);
                            } else {
                                update_field('download_file', $acf_file, $post_id);
                            }
                            $import_log['attachment_ids'][] = $attach_id;
                        } else {
                            $failed_attaches[] = $download_file;
                        }
                    }




                    $imported++;
                }

                //输出附件下载失败的表格
                failed_import_attachment_log_table($failed_attaches);

                // 合并富文本导入的图片ID
                $import_log['attachment_ids'] = array_unique(array_merge($import_log['attachment_ids'], Product_Import_Data_Converter::$imported_attachment_ids));

                echo '<div class="notice notice-success"><p>' . $imported . ' products imported (from row ' . $batch_start . ' to ' . $batch_end . ').</p></div>';
                // 分批导入按钮
                if ($batch_end < $total_rows) {
                    echo '<form method="post" enctype="multipart/form-data">';
                    foreach ($_POST as $k => $v) {
                        if ($k === 'batch_start') continue;
                        if (is_array($v)) {
                            foreach ($v as $vv) {
                                echo '<input type="hidden" name="' . esc_attr($k) . '[]" value="' . esc_attr($vv) . '">';
                            }
                        } else {
                            echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
                        }
                    }
                    if (!empty($products_file)) {
                        echo '<input type="hidden" name="products_file_path" value="' . esc_attr($products_file) . '">';
                    }
                    if (!empty($categories_file)) {
                        echo '<input type="hidden" name="categories_file_path" value="' . esc_attr($categories_file) . '">';
                    }
                    if (!empty($import_folder_path)) {
                        echo '<input type="hidden" name="import_folder_path" value="' . esc_attr($import_folder_path) . '">';
                    }
                    echo '<input type="hidden" name="batch_start" value="' . ($batch_end + 1) . '">';
                    echo '<input type="submit" name="product_import_submit" class="button button-primary" value="Continue Import Next Batch">';
                    echo '</form>';
                }

                // 导入完成后写入日志表
                Product_Import_Rollback::write_log([
                    'import_type' => $import_log['import_type'],
                    'file_name' => $import_log['file_name'],
                    'post_ids' => $import_log['post_ids'],
                    'term_ids' => [],
                    'attachment_ids' => $import_log['attachment_ids'],
                    'updated_posts' => isset($import_log['updated_posts']) ? $import_log['updated_posts'] : [],
                ]);
            } catch (Exception $e) {
                echo '<div class="notice notice-error"><p>Failed to read products.xlsx: ' . esc_html($e->getMessage()) . '</p></div>';
            }
        }
        if (!empty($categories_file) && file_exists($categories_file)) {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($categories_file);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, true);
                // 字段名到列号的映射
                $header_map = array();
                foreach ($rows[1] as $col => $field) {
                    $header_map[trim($field)] = $col;
                }
                echo '<h2>Categories Preview</h2><table class="widefat"><thead><tr>';
                foreach ($rows[1] as $header) {
                    echo '<th>' . esc_html($header) . '</th>';
                }
                echo '</tr></thead><tbody>';
                for ($i = 2; $i <= count($rows); $i++) {
                    echo '<tr>';
                    foreach ($rows[$i] as $cell) {
                        echo '<td>' . esc_html($cell) . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody></table>';

                // Import categories
                $imported = 0;
                for ($i = 2; $i <= count($rows); $i++) {
                    $row = $rows[$i];
                    // 用字段名取值
                    $cat_name = trim(self::get_sheet_data($row, $header_map['Category Name']));
                    $cat_desc = trim(self::get_sheet_data($row, $header_map['Description']));
                    $parent_name = trim(self::get_sheet_data($row, $header_map['Parent Category']));
                    $cat_thumb = trim(self::get_sheet_data($row, $header_map['Category Thumbnail']));
                    $seo_title = trim(self::get_sheet_data($row, $header_map['SEO Title (DE)']));
                    $seo_desc = trim(self::get_sheet_data($row, $header_map['SEO Description (DE)']));
                    if (empty($cat_name)) continue; // Category Name required
                    $parent_id = 0;
                    if ($parent_name) {
                        $parent_term = get_term_by('name', $parent_name, 'product-category');
                        if ($parent_term) {
                            $parent_id = $parent_term->term_id;
                        }
                    }
                    $term = get_term_by('name', $cat_name, 'product-category');
                    if ($term) {
                        // Update
                        wp_update_term($term->term_id, 'product-category', array(
                            'description' => $cat_desc,
                            'parent' => $parent_id
                        ));
                        $term_id = $term->term_id;
                    } else {
                        // Insert
                        $result = wp_insert_term($cat_name, 'product-category', array(
                            'description' => $cat_desc,
                            'parent' => $parent_id
                        ));
                        if (!is_wp_error($result) && isset($result['term_id'])) {
                            $term_id = $result['term_id'];
                        } else {
                            continue;
                        }
                    }
                    // ACF fields and thumbnail
                    // Category Thumbnail
                    // if ($cat_thumb && $category_dir) {
                    //     $img_path = $category_dir . '/' . $cat_thumb;
                    //     if (file_exists($img_path)) {
                    //         $attach_id = self::import_media($img_path);
                    //         if ($attach_id) {
                    //             $field_obj = get_field_object('category_thumbnail', 'product-category_' . $term_id);
                    //             if ($field_obj && isset($field_obj['key'])) {
                    //                 update_field($field_obj['key'], $attach_id, 'product-category_' . $term_id);
                    //             } else {
                    //                 update_field('category_thumbnail', $attach_id, 'product-category_' . $term_id);
                    //             }
                    //         }
                    //     }
                    // }
                    if ($cat_thumb) {
                        $attach_id = self::import_media($cat_thumb, 0, $category_dir);
                        if ($attach_id) {
                            $field_obj = get_field_object('category_thumbnail', 'product-category_' . $term_id);
                            if ($field_obj && isset($field_obj['key'])) {
                                update_field($field_obj['key'], $attach_id, 'product-category_' . $term_id);
                            } else {
                                update_field('category_thumbnail', $attach_id, 'product-category_' . $term_id);
                            }
                        }
                    }

                    // SEO Title (DE)
                    if (!empty($seo_title)) {
                        $field_obj = get_field_object('seo_title_de', 'product-category_' . $term_id);
                        if ($field_obj && isset($field_obj['key'])) {
                            update_field($field_obj['key'], $seo_title, 'product-category_' . $term_id);
                        } else {
                            update_field('seo_title_de', $seo_title, 'product-category_' . $term_id);
                        }
                    }
                    // SEO Description (DE)
                    if (!empty($seo_desc)) {
                        $field_obj = get_field_object('seo_description_de', 'product-category_' . $term_id);
                        if ($field_obj && isset($field_obj['key'])) {
                            update_field($field_obj['key'], $seo_desc, 'product-category_' . $term_id);
                        } else {
                            update_field('seo_description_de', $seo_desc, 'product-category_' . $term_id);
                        }
                    }
                    $imported++;
                }
                echo '<div class="notice notice-success"><p>' . $imported . ' categories imported.</p></div>';
            } catch (Exception $e) {
                echo '<div class="notice notice-error"><p>Failed to read categories.xlsx: ' . esc_html($e->getMessage()) . '</p></div>';
            }
        }
    }
    // 递归查找指定子文件夹
    public static function find_subdir($base, $target)
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir() && strtolower($file->getFilename()) === strtolower($target)) {
                return $file->getPathname();
            }
        }
        return false;
    }
    // 导入媒体，支持本地文件路径或URL
    public static function import_media($file, $post_id = 0, $search_dir = '')
    {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // 判断是否为URL
        $is_url = filter_var($file, FILTER_VALIDATE_URL);
        $md5 = md5(trim($file));
        $filename = $is_url ? basename(parse_url($file, PHP_URL_PATH)) : basename($file);

        // 1. 检查媒体库是否已存在（通过MD5）
        $exists = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'meta_key' => '_import_src_md5',
            'meta_value' => $md5,
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);
        if ($exists) return $exists[0];

        // 2. 处理URL资源
        if ($is_url) {
            // 2.1 优先从搜索目录查找本地文件
            if ($search_dir) {
                $local_path = trailingslashit($search_dir) . $filename;
                if (file_exists($local_path)) {
                    $file_array = [
                        'name' => $filename,
                        'tmp_name' => $local_path
                    ];
                    $attach_id = media_handle_sideload($file_array, $post_id);

                    if (!is_wp_error($attach_id)) {
                        self::add_import_metadata($attach_id, $md5, $file);
                        return $attach_id;
                    }
                }
            }

            // 2.2 检查是否已知失败URL
            if ('failed' == product_import_get_url_status(self::$task_id, $file)) {
                return false;
            }

            // 2.3 从网络下载
            $tmp_file = safe_download_url($file);
            if (is_wp_error($tmp_file)) {
                return false;
            }

            $file_array = [
                'name' => $filename,
                'tmp_name' => $tmp_file
            ];
        }
        // 3. 处理本地文件
        else {
            if ($search_dir) {
                $local_path = trailingslashit($search_dir) . $filename;
                if (!file_exists($local_path)) {
                    return false;
                }
                $file_array = [
                    'name' => $filename,
                    'tmp_name' => $file
                ];
                // 统一处理文件导入
                $attach_id = media_handle_sideload($file_array, $post_id);

                if (!is_wp_error($attach_id)) {
                    self::add_import_metadata($attach_id, $md5, $file);
                    return $attach_id;
                }
            }
        }



        // 清理临时文件（如果是URL下载）
        if ($is_url && isset($tmp_file)) {
            @unlink($tmp_file);
        }

        return false;
    }

    /**
     * 添加导入元数据
     */
    private static function add_import_metadata($attach_id, $md5, $source_url)
    {
        add_post_meta($attach_id, '_import_src_md5', $md5, true);
        if (filter_var($source_url, FILTER_VALIDATE_URL)) {
            add_post_meta($attach_id, '_import_src_url', $source_url, true);
        }
    }


    // 用 WP_Query 替代 get_page_by_title
    public static function get_component_by_title($title)
    {
        $query = new WP_Query([
            'post_type'      => 'components',
            'title'          => $title,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'all',
        ]);
        if ($query->have_posts()) {
            return $query->posts[0];
        }
        return false;
    }
}

/**
 * 资源导入优化建议与设计
 *
 * 目标：提升大批量/慢速第三方资源导入的用户体验和稳定性，兼顾易用性和性能。
 *
 * 推荐方案（综合最佳实践）：
 *
 * 1. 前端分步异步导入（AJAX队列/分批）：
 *    - 用户上传Excel后，前端解析所有资源URL（或后台解析后返回资源列表）。
 *    - 前端用AJAX逐个/分批请求后台接口，后台负责下载资源并导入媒体库。
 *    - 每个资源下载成功后，前端实时显示进度、失败列表，支持重试。
 *    - 支持断点续传（刷新后继续未完成任务）。
 *    - 支持并发（如3-5个AJAX同时进行），提升整体速度。
 *
 * 2. 媒体唯一标识与去重：
 *    - 每个资源用原始URL的md5作为唯一标识，导入媒体库时写入post meta（如 _import_src_md5）。
 *    - 导入时先查找是否已存在该资源，存在则直接复用，不重复下载。
 *
 * 3. 导入主流程与资源绑定：
 *    - 正式导入数据时，所有资源字段（图片、PDF等）先查找媒体库是否有对应md5标记的附件，有则直接用。
 *    - 没有则可选“自动补下载”或提示用户。
 *
 * 4. 后台/CLI批量导入支持（可选）：
 *    - 对于超大量资源，支持WP-CLI命令行批量导入，避免Web超时。
 *
 * 5. 失败重试与任务管理：
 *    - 失败任务前端可重试，后台可记录失败原因。
 *    - 支持导出/导入失败任务列表，便于后续处理。
 *
 * 6. 用户体验细节：
 *    - 前端进度条、剩余/失败数提示。
 *    - 支持暂停/继续。
 *    - 导入完成后可一键进入正式数据导入。
 *
 * 7. 安全性：
 *    - 校验URL合法性，防止SSRF等安全风险。
 *    - 限制单次任务数量，防止滥用。
 *
 * 8. 兼容性：
 *    - 兼容原有“同步导入”方式，用户可选“极速导入”或“安全导入”。
 *
 * 伪代码结构如下：
 *
 * // 前端
 * 1. 用户上传Excel，点击“资源预处理”按钮
 * 2. 前端解析/请求后台返回所有资源URL
 * 3. 前端AJAX分批请求后台接口 /wp-admin/admin-ajax.php?action=import_resource&url=xxx
 * 4. 后台接口：判断url是否已导入，未导入则下载并写入媒体库，写入url md5标记
 * 5. 前端显示进度，失败可重试
 * 6. 资源全部导入后，用户点击“正式导入数据”，主流程直接复用已导入媒体
 *
 * // 后台
 * add_action('wp_ajax_import_resource', function() {
 *   $url = $_POST['url'];
 *   $md5 = md5($url);
 *   $exists = get_posts(['post_type'=>'attachment','meta_key'=>'_import_src_md5','meta_value'=>$md5]);
 *   if ($exists) return ...;
 *   // 下载并导入媒体库，add_post_meta($attach_id, '_import_src_md5', $md5);
 * });
 *
 * // 主流程
 * $md5 = md5($url);
 * $attach = get_posts(['post_type'=>'attachment','meta_key'=>'_import_src_md5','meta_value'=>$md5]);
 * if ($attach) { ... } else { ... }
 *
 * 这样既保证了性能、体验，也兼容失败重试和断点续传。
 *
 * 如需具体实现代码，可随时提出。
 */
