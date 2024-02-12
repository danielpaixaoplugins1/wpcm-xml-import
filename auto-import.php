<?php
/**
 * Plugin Name: WPCM XML Import
 * Description: Importa conteúdo de XML, baixa e otimiza imagens externas automaticamente, e configura imagens destacadas para posts.
 * Version: 1.5
 * Author: Daniel Oliveira da Paixão
 * Author URI: https://centralmidia.net
 */

if (!defined('ABSPATH')) exit;

class WPCM_Auto_Image_XML_Importer {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_import_xml', array($this, 'handle_xml_upload'));
    }

    public function add_admin_menu() {
        add_menu_page('WPCM Import', 'WPCM Import', 'manage_options', 'wpcm-import', array($this, 'render_admin_page'), 'dashicons-upload');
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h2>Importar XML e Atualizar Imagens</h2>
            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('wpcm_xml_import', 'wpcm_xml_nonce'); ?>
                <input type="hidden" name="action" value="import_xml">
                <input type="file" name="xml_file" required>
                <input type="submit" class="button button-primary" value="Importar XML">
            </form>
        </div>
        <?php
    }

    public function handle_xml_upload() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wpcm_xml_nonce'], 'wpcm_xml_import')) {
            wp_die('Você não tem permissão para executar esta ação.');
        }

        if (isset($_FILES['xml_file']) && $_FILES['xml_file']['error'] == UPLOAD_ERR_OK) {
            $this->process_xml_file($_FILES['xml_file']['tmp_name']);
            wp_redirect(admin_url('admin.php?page=wpcm-import&imported=true'));
            exit;
        } else {
            wp_die('Erro no upload do arquivo XML.');
        }
    }

    private function process_xml_file($file_path) {
        $xml = simplexml_load_file($file_path);
        if (!$xml) {
            wp_die('Falha ao processar o arquivo XML.');
        }

        foreach ($xml->channel->item as $item) {
            $post_data = [
                'post_title' => (string)$item->title,
                'post_content' => (string)$item->description,
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
                'post_type' => 'post',
            ];

            // Verifica se o post já existe
            $existing_post = get_page_by_title($post_data['post_title'], OBJECT, 'post');
            if ($existing_post) {
                $post_data['ID'] = $existing_post->ID;
                wp_update_post($post_data);
            } else {
                $post_id = wp_insert_post($post_data);
            }

            // Processa imagens para cada post
            $this->process_images_for_post($post_data['post_content'], $post_id ?? $existing_post->ID);
        }
    }

    private function process_images_for_post($content, $post_id) {
        if (preg_match_all('/<img[^>]+src=[\'"]?([^\'" >]+)[\'"]?[^>]*>/i', $content, $matches)) {
            $first_image_set = false;
            foreach ($matches[1] as $image_url) {
                $new_image_id = $this->download_and_attach_image($image_url, $post_id);
                if ($new_image_id && !$first_image_set) {
                    set_post_thumbnail($post_id, $new_image_id);
                    $first_image_set = true;
                }
            }
        }
    }

    private function download_and_attach_image($image_url, $post_id) {
        include_once(ABSPATH . 'wp-admin/includes/media.php');
        include_once(ABSPATH . 'wp-admin/includes/file.php');
        include_once(ABSPATH . 'wp-admin/includes/image.php');

        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            // Error handling
            return false;
        }

        $file_array = array(
            'name' => basename($image_url),
            'tmp_name' => $tmp
        );

        // Check for file type
        $file_type = wp_check_filetype($file_array['name'], null);
        if ($file_type['type'] == false) {
            unlink($file_array['tmp_name']);
            return false;
        }

        $attachment_id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            return false;
        }

        return $attachment_id;
    }
}

new WPCM_Auto_Image_XML_Importer();
