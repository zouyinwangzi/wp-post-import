<?php
class Product_Import_Rollback
{

    // 写入导入日志
    public static function write_log($args)
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'product_import_logs', [
            'import_time' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'import_type' => $args['import_type'],
            'file_name' => $args['file_name'],
            'post_ids' => maybe_serialize($args['post_ids']),
            'term_ids' => isset($args['term_ids']) ? maybe_serialize($args['term_ids']) : '',
            'attachment_ids' => maybe_serialize($args['attachment_ids']),
            'log' => maybe_serialize(['updated_posts' => $args['updated_posts']]),
        ]);
    }

    // 回滚导入
    public static function rollback($log_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'product_import_logs';
        $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $log_id));
        if (!$log || $log->log === 'rolledback') {
            return ['status' => 'already', 'msg' => 'This import has already been rolled back.'];
        }
        $post_ids = maybe_unserialize($log->post_ids);
        $attach_ids = maybe_unserialize($log->attachment_ids);
        $logdata = maybe_unserialize($log->log);
        $updated_posts = isset($logdata['updated_posts']) ? $logdata['updated_posts'] : [];
        $deleted_posts = 0;
        $restored_posts = 0;
        $deleted_attachments = 0;
        if (is_array($post_ids)) {
            foreach ($post_ids as $pid) {
                if (get_post($pid)) {
                    if (isset($updated_posts[$pid])) {
                        $orig = $updated_posts[$pid];
                        $restore_data = [
                            'ID' => $pid,
                            'post_title' => $orig['post_title'],
                            'post_content' => $orig['post_content'],
                            'post_excerpt' => $orig['post_excerpt'],
                            'post_status' => $orig['post_status'],
                            'post_type' => $orig['post_type'],
                            'post_author' => $orig['post_author'],
                            'post_date' => $orig['post_date'],
                            'post_modified' => $orig['post_modified'],
                            'post_parent' => $orig['post_parent'],
                            'post_name' => $orig['post_name'],
                            'post_mime_type' => $orig['post_mime_type'],
                            'post_password' => $orig['post_password'],
                            'menu_order' => $orig['menu_order'],
                            'comment_status' => $orig['comment_status'],
                            'ping_status' => $orig['ping_status'],
                            'guid' => $orig['guid'],
                        ];
                        wp_update_post($restore_data);
                        if (!empty($orig['acf']) && function_exists('update_field')) {
                            foreach ($orig['acf'] as $k => $v) {
                                update_field($k, $v, $pid);
                            }
                        }
                        if (!empty($orig['categories'])) {
                            wp_set_object_terms($pid, $orig['categories'], 'product-category');
                        }
                        if (!empty($orig['thumbnail_id'])) {
                            set_post_thumbnail($pid, $orig['thumbnail_id']);
                        } else {
                            delete_post_thumbnail($pid);
                        }
                        $restored_posts++;
                    } else {
                        wp_delete_post($pid, true);
                        $deleted_posts++;
                    }
                }
            }
        }
        if (is_array($attach_ids)) {
            foreach ($attach_ids as $aid) {
                if (get_post($aid)) {
                    wp_delete_attachment($aid, true);
                    $deleted_attachments++;
                }
            }
        }
        $wpdb->update($table, ['log' => 'rolledback'], ['id' => $log_id]);
        return [
            'status' => 'ok',
            'msg' => 'Rollback finished: ' . $deleted_posts . ' posts deleted, ' . $restored_posts . ' posts restored, ' . $deleted_attachments . ' attachments deleted.'
        ];
    }
}
