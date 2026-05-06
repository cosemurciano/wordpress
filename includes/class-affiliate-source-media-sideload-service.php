<?php
if (!defined('ABSPATH')) { exit; }

class ALMA_Affiliate_Source_Media_Sideload_Service {
    const META_REMOTE_URL = '_alma_remote_image_url';
    const META_REMOTE_HASH = '_alma_remote_image_hash';
    const META_PROVIDER = '_alma_media_provider';
    const META_SOURCE_ID = '_alma_source_id';
    const META_EXTERNAL_ID = '_alma_external_id';
    const META_IMPORTED_FOR_POST_ID = '_alma_imported_for_post_id';

    public function import_featured_image($post_id, $image_url, $args = array()) {
        $post_id = absint($post_id);
        $args = is_array($args) ? $args : array();
        $provider = sanitize_key($args['provider'] ?? '');
        $source_id = sanitize_text_field((string)($args['source_id'] ?? ''));
        $external_id = sanitize_text_field((string)($args['external_id'] ?? ''));
        $overwrite_existing = !empty($args['overwrite_existing']);
        $dry_run = !empty($args['dry_run']);
        $context = is_array($args['context'] ?? null) ? $args['context'] : array();

        $url = $this->normalize_url($image_url);
        $hash = $url !== '' ? $this->hash_url($url) : '';
        $result = $this->result('skipped_no_url', $post_id, $url, $hash, __('Immagine non importata: URL mancante.', 'affiliate-link-manager-ai'));

        if ($post_id < 1 || get_post_type($post_id) !== 'affiliate_link') {
            $result = $this->result('failed_validation', $post_id, $url, $hash, __('Immagine non importata: post non valido.', 'affiliate-link-manager-ai'), 'invalid_post');
            $this->save_post_diagnostics($post_id, $result);
            $this->log_event('failed_validation', $result);
            return $result;
        }

        if ($url === '') {
            $this->save_post_diagnostics($post_id, $result);
            $this->log_event('skipped_no_url', $result);
            return $result;
        }

        if (!$this->is_valid_remote_url($url)) {
            $result = $this->result('failed_validation', $post_id, $url, $hash, __('Immagine non importata: URL remoto non valido.', 'affiliate-link-manager-ai'), 'invalid_url');
            $this->save_post_diagnostics($post_id, $result);
            $this->log_event('failed_validation', $result);
            return $result;
        }

        if (!$overwrite_existing && has_post_thumbnail($post_id)) {
            $result = $this->result('skipped_existing_thumbnail', $post_id, $url, $hash, __('Immagine già presente: featured image esistente non sovrascritta.', 'affiliate-link-manager-ai'));
            $result['attachment_id'] = (int) get_post_thumbnail_id($post_id);
            $this->save_post_diagnostics($post_id, $result);
            $this->log_event('skipped_existing_thumbnail', $result);
            return $result;
        }

        $existing_attachment_id = $this->find_existing_attachment($hash);
        if ($existing_attachment_id > 0) {
            $result = $this->result('reused_existing_attachment', $post_id, $url, $hash, __('Immagine riutilizzata dalla Media Library.', 'affiliate-link-manager-ai'));
            $result['attachment_id'] = $existing_attachment_id;
            $result['reused_existing'] = true;
            if (!$dry_run) {
                $featured = set_post_thumbnail($post_id, $existing_attachment_id);
                if (!$featured) {
                    $result['success'] = false;
                    $result['status'] = 'failed_featured_image';
                    $result['error_code'] = 'set_post_thumbnail_failed';
                    $result['message'] = __('Attachment riusato, ma associazione come immagine in evidenza fallita.', 'affiliate-link-manager-ai');
                } else {
                    $result['set_as_featured'] = true;
                }
                $this->save_attachment_meta($existing_attachment_id, $url, $hash, $provider, $source_id, $external_id, $post_id, $context);
            }
            $this->save_post_diagnostics($post_id, $result);
            $this->log_event($result['status'], $result);
            return $result;
        }

        if ($dry_run) {
            $result = $this->result('downloaded', $post_id, $url, $hash, __('Dry run: immagine remota validata, download non eseguito.', 'affiliate-link-manager-ai'));
            $this->save_post_diagnostics($post_id, $result);
            return $result;
        }

        $this->load_media_dependencies();
        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            $result = $this->result('failed_download', $post_id, $url, $hash, $tmp->get_error_message(), $tmp->get_error_code());
            $this->save_post_diagnostics($post_id, $result);
            $this->log_event('failed_download', $result);
            return $result;
        }

        $filename = $this->filename_from_url($url, $hash);
        $file_array = array('name' => $filename, 'tmp_name' => $tmp);
        $checked = function_exists('wp_check_filetype_and_ext') ? wp_check_filetype_and_ext($tmp, $filename) : array();
        if (!empty($checked['proper_filename'])) {
            $file_array['name'] = sanitize_file_name($checked['proper_filename']);
        }

        $attachment_id = media_handle_sideload($file_array, $post_id, null, array('post_title' => get_the_title($post_id)));
        if (is_wp_error($attachment_id)) {
            if (!empty($file_array['tmp_name']) && file_exists($file_array['tmp_name'])) {
                @unlink($file_array['tmp_name']);
            }
            $result = $this->result('failed_attachment', $post_id, $url, $hash, $attachment_id->get_error_message(), $attachment_id->get_error_code());
            $this->save_post_diagnostics($post_id, $result);
            $this->log_event('failed_attachment', $result);
            return $result;
        }

        $attachment_id = absint($attachment_id);
        $this->save_attachment_meta($attachment_id, $url, $hash, $provider, $source_id, $external_id, $post_id, $context);
        $result = $this->result('downloaded', $post_id, $url, $hash, __('Immagine importata nella Media Library.', 'affiliate-link-manager-ai'));
        $result['success'] = true;
        $result['attachment_id'] = $attachment_id;
        $featured = set_post_thumbnail($post_id, $attachment_id);
        if (!$featured) {
            $result['success'] = false;
            $result['status'] = 'failed_featured_image';
            $result['error_code'] = 'set_post_thumbnail_failed';
            $result['message'] = __('Immagine importata, ma associazione come immagine in evidenza fallita.', 'affiliate-link-manager-ai');
        } else {
            $result['set_as_featured'] = true;
        }

        $this->save_post_diagnostics($post_id, $result);
        $this->log_event($result['status'], $result);
        return $result;
    }

    private function normalize_url($url) {
        $url = is_scalar($url) ? trim((string)$url) : '';
        if ($url === '') return '';
        $url = esc_url_raw($url, array('http', 'https'));
        if ($url === '') return '';
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, array('http', 'https'), true)) return '';
        unset($parts['fragment']);
        $normalized = $scheme . '://' . strtolower((string)$parts['host']);
        if (!empty($parts['port'])) $normalized .= ':' . absint($parts['port']);
        $normalized .= isset($parts['path']) ? (string)$parts['path'] : '/';
        if (!empty($parts['query'])) $normalized .= '?' . (string)$parts['query'];
        return esc_url_raw($normalized, array('http', 'https'));
    }

    private function is_valid_remote_url($url) {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return false;
        if (!in_array(strtolower((string)$parts['scheme']), array('http', 'https'), true)) return false;
        return (bool) wp_http_validate_url($url);
    }

    private function hash_url($url) { return hash('sha256', $this->normalize_url($url)); }

    private function find_existing_attachment($hash) {
        $hash = sanitize_text_field((string)$hash);
        if ($hash === '') return 0;
        $query = new WP_Query(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => true,
            'meta_query' => array(array('key' => self::META_REMOTE_HASH, 'value' => $hash, 'compare' => '=')),
        ));
        $ids = is_array($query->posts) ? $query->posts : array();
        return !empty($ids[0]) ? absint($ids[0]) : 0;
    }

    private function load_media_dependencies() {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    private function filename_from_url($url, $hash) {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $name = sanitize_file_name(wp_basename($path));
        if ($name === '' || strpos($name, '.') === false) {
            $name = 'alma-remote-image-' . substr(sanitize_key($hash), 0, 16) . '.jpg';
        }
        return $name;
    }

    private function save_attachment_meta($attachment_id, $url, $hash, $provider, $source_id, $external_id, $post_id, $context) {
        update_post_meta($attachment_id, self::META_REMOTE_URL, esc_url_raw($url));
        update_post_meta($attachment_id, self::META_REMOTE_HASH, sanitize_text_field($hash));
        update_post_meta($attachment_id, self::META_PROVIDER, sanitize_key($provider));
        update_post_meta($attachment_id, self::META_SOURCE_ID, sanitize_text_field($source_id));
        update_post_meta($attachment_id, self::META_EXTERNAL_ID, sanitize_text_field($external_id));
        update_post_meta($attachment_id, self::META_IMPORTED_FOR_POST_ID, absint($post_id));
        if (!empty($context)) {
            update_post_meta($attachment_id, '_alma_media_import_context_json', wp_json_encode($context));
        }
    }

    private function save_post_diagnostics($post_id, $result) {
        $post_id = absint($post_id);
        if ($post_id < 1) return;
        update_post_meta($post_id, '_alma_featured_image_import_status', sanitize_key($result['status'] ?? ''));
        update_post_meta($post_id, '_alma_featured_image_attachment_id', absint($result['attachment_id'] ?? 0));
        update_post_meta($post_id, '_alma_featured_image_last_error', sanitize_text_field($result['error_code'] ? $result['message'] : ''));
        update_post_meta($post_id, '_alma_featured_image_imported_at', current_time('mysql'));
        update_post_meta($post_id, '_alma_featured_image_source_url', esc_url_raw((string)($result['image_url'] ?? '')));
        update_post_meta($post_id, '_alma_featured_image_hash', sanitize_text_field((string)($result['hash'] ?? '')));
    }

    private function result($status, $post_id, $url, $hash, $message = '', $error_code = '') {
        $success_statuses = array('reused_existing_attachment', 'downloaded');
        return array(
            'success' => in_array($status, $success_statuses, true),
            'status' => sanitize_key($status),
            'post_id' => absint($post_id),
            'attachment_id' => 0,
            'reused_existing' => false,
            'set_as_featured' => false,
            'error_code' => sanitize_key($error_code),
            'message' => sanitize_text_field((string)$message),
            'image_url' => esc_url_raw((string)$url),
            'hash' => sanitize_text_field((string)$hash),
        );
    }

    private function log_event($event, $result) {
        do_action('alma_affiliate_source_media_sideload_event', sanitize_key($event), $result);
    }
}
