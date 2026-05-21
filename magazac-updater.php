<?php
/*
Plugin Name: Magazac Updater
Description: Otomatik Site Güncelleme Eklentisi
Version: 2.0
Author: Magazac
* GitHub Plugin URI: https://github.com/adminmagazify/magazac-updater
*/


require plugin_dir_path(__FILE__) . 'plugin-update-checker-master/plugin-update-checker.php';

$updateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/adminmagazify/magazac-updater',
    __FILE__,
    'magazac-updater'
);

$updateChecker->setBranch('main');
if (!defined('ABSPATH')) exit;

/**
 * Admin menü
 */
add_action('admin_menu', function () {
    add_management_page(
        'Magazac Updater',
        'Magazac Updater',
        'manage_options',
        'magazac-updater',
        'magazac_updater_page'
    );
});

/**
 * Hedef menü adları (sende böyle)
 */
function magazac_whatsapp_target_menus(): array {
    return [
        'web'    => 'Main Web Menu',
        'mobile' => 'Main Mobile Menu',
    ];
}

/**
 * Menü adına göre menu object bulur
 */
function magazac_get_menu_by_name(string $menu_name) {
    $menus = wp_get_nav_menus();
    foreach ($menus as $menu) {
        if (!isset($menu->name)) continue;
        if (mb_strtolower(trim($menu->name)) === mb_strtolower(trim($menu_name))) {
            return $menu;
        }
    }
    return null;
}

/**
 * Belirli bir menünün içindeki "Whatsapp" menü item'ını bulur.
 */
function magazac_find_whatsapp_item_in_menu($menu_term_id) {
    $items = wp_get_nav_menu_items($menu_term_id);
    if (!$items) return null;

    foreach ($items as $item) {
        if (!isset($item->title)) continue;
        if (mb_strtolower(trim($item->title)) === 'whatsapp') {
            return $item; // WP_Post (nav_menu_item)
        }
    }
    return null;
}

/**
 * WhatsApp URL güncelle (nav_menu_item meta)
 */
function magazac_update_menu_item_url(int $menu_item_id, string $new_url): void {
    update_post_meta($menu_item_id, '_menu_item_url', $new_url);
    wp_update_post(['ID' => $menu_item_id]); // cache tazele
    clean_post_cache($menu_item_id);
}

/**
 * Attachment'tan Redux media array üret
 */
function magazac_build_media_array(int $attachment_id): array {
    $meta  = wp_get_attachment_metadata($attachment_id);
    $thumb = wp_get_attachment_image_src($attachment_id, 'thumbnail');

    return [
        'url'       => wp_get_attachment_url($attachment_id),
        'id'        => $attachment_id,
        'width'     => $meta['width'] ?? '',
        'height'    => $meta['height'] ?? '',
        'thumbnail' => $thumb[0] ?? '',
    ];
}

/**
 * Redux media field'i güvenli şekilde güncelle (kısmi overwrite yok)
 */
function magazac_update_redux_media_field(array &$options, string $key, array $media): void {
    if (!isset($options[$key]) || !is_array($options[$key])) {
        $options[$key] = [];
    }

    // SADECE bu alt alanları güncelle, diğer Redux key'lerine dokunma
    $options[$key]['url']       = $media['url'] ?? '';
    $options[$key]['id']        = $media['id'] ?? '';
    $options[$key]['width']     = $media['width'] ?? '';
    $options[$key]['height']    = $media['height'] ?? '';
    $options[$key]['thumbnail'] = $media['thumbnail'] ?? '';
}

/**
 * Loobek logo setini güvenli güncelle
 */
function magazac_update_loobek_logos(array &$options, array $media): void {
    $logo_keys = [
        'ts_logo',
        'ts_logo_mobile',
        'ts_logo_sticky',
        'ts_logo_transparent_header',
    ];

    foreach ($logo_keys as $k) {
        magazac_update_redux_media_field($options, $k, $media);
    }
}

/**
 * Admin sayfası
 */
function magazac_updater_page() {
    if (!current_user_can('manage_options')) return;

    // =========================
    // ✅ Bilgi Merkezi – SSS Post ID
    // =========================
    $bm_sss_post_id = 29479; // Sık Sorulan Sorular post ID

    $bm_post = get_post($bm_sss_post_id);
    $bm_sss_content = $bm_post ? $bm_post->post_content : '';

    $is_master = isset($_POST['magazac_master_submit']);

    if ($is_master) {
        check_admin_referer(
            'magazac_master_kaydet',
            'magazac_master_nonce'
        );
    }

    $options   = get_option('loobek_theme_options', []);
    $icerikler = get_option('icerikler', ['', '', '']);

    // ✅ EK: WP Genel Ayarlar (site başlığı & slogan) mevcut değerleri
    $site_basligi = (string) get_option('blogname', '');
    $site_slogan  = (string) get_option('blogdescription', '');

    $smtp_settings = get_option('wp_mail_smtp', []);

    /* =========================
    * ✅ KULLANICI YÖNETİMİ (form için)
    * ========================= */
    $new_user_login = '';
    $new_user_email = '';
    $new_user_pass  = '';

    /* =========================
     * ✅ EK: Varsayılan Kategori Bilgisi (form için)
     * ========================= */
    $category_name = '';
    $category_slug = '';

    $categories = get_terms([
        'taxonomy'   => 'category',
        'hide_empty' => false,
    ]);

    if (!is_wp_error($categories) && !empty($categories)) {
        $category_name = $categories[0]->name ?? '';
        $category_slug = $categories[0]->slug ?? '';
    }

    /* =========================
    * ✅ MARKA BİLGİLERİ (form için)
    * ========================= */
    $brand_name = '';
    $brand_slug = '';
    $brand_description = '';

    $brands = get_terms([
        'taxonomy'   => 'product_brand',
        'hide_empty' => false,
    ]);

    if (!is_wp_error($brands) && !empty($brands)) {
        $brand_name        = $brands[0]->name ?? '';
        $brand_slug        = $brands[0]->slug ?? '';
        $brand_description = $brands[0]->description ?? '';
    }

    /* =========================
     * WhatsApp (2 MENÜ: Web + Mobile) - mevcut değerleri çek
     * ========================= */
    $targets = magazac_whatsapp_target_menus();

    $menu_web    = magazac_get_menu_by_name($targets['web']);
    $menu_mobile = magazac_get_menu_by_name($targets['mobile']);

    $item_web    = $menu_web ? magazac_find_whatsapp_item_in_menu($menu_web->term_id) : null;
    $item_mobile = $menu_mobile ? magazac_find_whatsapp_item_in_menu($menu_mobile->term_id) : null;

    $current_web_url    = $item_web ? (string) $item_web->url : '';
    $current_mobile_url = $item_mobile ? (string) $item_mobile->url : '';

    /* =========================
    * ✅ WP GENEL AYARLAR (Site Başlığı, Slogan, Yönetim E-postası)
    * ========================= */
    if (
    isset($_POST['magazac_wp_settings_submit']) ||
    isset($_POST['magazac_master_submit'])
    ) {

        // Site başlığı
        $new_title = sanitize_text_field(
            wp_unslash($_POST['site_basligi'] ?? '')
        );
        update_option('blogname', $new_title);
        $site_basligi = $new_title;

        // Slogan
        $new_tagline = sanitize_text_field(
            wp_unslash($_POST['site_slogan'] ?? '')
        );
        update_option('blogdescription', $new_tagline);
        $site_slogan = $new_tagline;

        // ✅ Yönetim e-posta adresi (WordPress kendi doğrulamasını yapar)
        if (!empty($_POST['admin_email'])) {
            $new_admin_email = sanitize_email(
                wp_unslash($_POST['admin_email'])
            );

            if (is_email($new_admin_email)) {
                update_option('admin_email', $new_admin_email);
            }
        }

        echo '<div class="notice notice-success">
            <p>
                Genel ayarlar güncellendi.
                Yönetim e-posta adresi değiştiyse WordPress doğrulama e-postası gönderilmiştir.
            </p>
        </div>';
    }

    /* =========================
     * 1) MERKEZİ İÇERİK (1–2–3)
     * ========================= */
    if (
        isset($_POST['magazac_icerik_submit']) ||
        isset($_POST['magazac_master_submit'])
    ) {

        $icerikler = [
            wp_kses_post($_POST['icerik_1'] ?? ''),
            wp_kses_post($_POST['icerik_2'] ?? ''),
            wp_kses_post($_POST['icerik_3'] ?? ''),
        ];

        update_option('icerikler', $icerikler);
        delete_transient('icerikler_cache');

        echo '<div class="notice notice-success"><p>Merkezi içerikler güncellendi.</p></div>';
    }

    /* =========================
     * 2) WHATSAPP MENÜ LİNKLERİ
     * ========================= */
    if (
        isset($_POST['magazac_whatsapp_submit']) ||
        isset($_POST['magazac_master_submit'])
    ) {

        $new_web_url    = esc_url_raw($_POST['whatsapp_url_web'] ?? '');
        $new_mobile_url = esc_url_raw($_POST['whatsapp_url_mobile'] ?? '');

        $changed_any = false;

        if ($item_web && $new_web_url) {
            magazac_update_menu_item_url((int)$item_web->ID, $new_web_url);
            $current_web_url = $new_web_url;
            $changed_any = true;
        }

        if ($item_mobile && $new_mobile_url) {
            magazac_update_menu_item_url((int)$item_mobile->ID, $new_mobile_url);
            $current_mobile_url = $new_mobile_url;
            $changed_any = true;
        }

        if ($changed_any) {
            echo '<div class="notice notice-success"><p>WhatsApp menü linkleri güncellendi.</p></div>';
        } else {
            echo '<div class="notice notice-warning"><p>WhatsApp güncellenemedi. Menü veya “Whatsapp” öğesi bulunamadı olabilir.</p></div>';
        }
    }

    /* =========================
     * 3) LOGO + FAVICON (Redux/Loobek) - izole submit
     * ========================= */
    if (
        isset($_POST['magazac_assets_submit']) ||
        isset($_POST['magazac_master_submit'])
    ) {

        $did_update = false;

        // Upload yardımcı dosyaları
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // LOGO
        if (!empty($_FILES['store_logo']['name'])) {
            $attachment_id = media_handle_upload('store_logo', 0);

            if (is_wp_error($attachment_id)) {
                echo '<div class="notice notice-error"><p>Logo yükleme hatası: ' . esc_html($attachment_id->get_error_message()) . '</p></div>';
            } else {
                $media = magazac_build_media_array((int)$attachment_id);
                magazac_update_loobek_logos($options, $media);
                $did_update = true;
            }
        }

        // FAVICON
        if (!empty($_FILES['store_favicon']['name'])) {
            $attachment_id = media_handle_upload('store_favicon', 0);

            if (is_wp_error($attachment_id)) {
                echo '<div class="notice notice-error"><p>Favicon yükleme hatası: ' . esc_html($attachment_id->get_error_message()) . '</p></div>';
            } else {
                $media = magazac_build_media_array((int)$attachment_id);
                magazac_update_redux_media_field($options, 'ts_favicon', $media);
                $did_update = true;
            }
        }

        // SITE ICON (WordPress)
        if (!empty($_FILES['site_icon']['name'])) {
            $attachment_id = media_handle_upload('site_icon', 0);

            if (is_wp_error($attachment_id)) {
                echo '<div class="notice notice-error"><p>Site simgesi yükleme hatası: ' . esc_html($attachment_id->get_error_message()) . '</p></div>';
            } else {
                update_option('site_icon', (int) $attachment_id);
                $did_update = true;
            }
        }

        if ($did_update) {
            update_option('loobek_theme_options', $options);
            echo '<div class="notice notice-success"><p>Logo / Favicon güncellendi.</p></div>';
        } else {
            echo '<div class="notice notice-info"><p>Güncellenecek logo veya favicon seçilmedi.</p></div>';
        }
    }

    /* =========================
    * 4) WP YAZMA AYARLARI – E-posta ile Yazı
    * ========================= */
    if (
        isset($_POST['magazac_writing_settings_submit']) ||
        isset($_POST['magazac_master_submit'])
    ) {

        update_option(
            'mailserver_url',
            sanitize_text_field(wp_unslash($_POST['mailserver_url'] ?? ''))
        );

        update_option(
            'mailserver_port',
            sanitize_text_field(wp_unslash($_POST['mailserver_port'] ?? ''))
        );

        update_option(
            'mailserver_login',
            sanitize_text_field(wp_unslash($_POST['mailserver_login'] ?? ''))
        );

        // 🔒 Parola: sadece doluysa overwrite
        if (!empty($_POST['mailserver_pass'])) {
            update_option(
                'mailserver_pass',
                wp_unslash($_POST['mailserver_pass'])
            );
        }

        echo '<div class="notice notice-success">
            <p>Yazma ayarları (e-posta ile yazı ekleme) güncellendi.</p>
        </div>';
    }

    /* =========================
    * 5) WP MAIL SMTP AYARLARI
    * ========================= */
    if (
        isset($_POST['magazac_wp_mail_smtp_submit']) ||
        isset($_POST['magazac_master_submit'])
    ) {

        $smtp = get_option('wp_mail_smtp', []);

        $smtp['mail']['from_email'] = sanitize_email(
            wp_unslash($_POST['smtp_from_email'] ?? '')
        );

        $smtp['mail']['from_name'] = sanitize_text_field(
            wp_unslash($_POST['smtp_from_name'] ?? '')
        );

        $smtp['mail']['mailer'] = 'smtp';
        $smtp['mail']['from_email_force'] = true;
        $smtp['mail']['from_name_force']  = true;

        $smtp['smtp']['host'] = sanitize_text_field(
            wp_unslash($_POST['smtp_host'] ?? '')
        );

        $smtp['smtp']['port'] = (int) ($_POST['smtp_port'] ?? 0);
        $smtp['smtp']['auth'] = true;
        $smtp['smtp']['user'] = sanitize_text_field(
            wp_unslash($_POST['smtp_user'] ?? '')
        );

        // 🔐 Şifre sadece doluysa overwrite
        if (!empty($_POST['smtp_pass'])) {
            $smtp['smtp']['pass'] = wp_unslash($_POST['smtp_pass']);
        }

        update_option('wp_mail_smtp', $smtp);

        echo '<div class="notice notice-success">
            <p>WP Mail SMTP ayarları güncellendi.</p>
        </div>';
    }

    /* =========================
    * 6) YAZILAR → KATEGORİ GÜNCELLEME
    * ========================= */
    if (
        isset($_POST['magazac_category_submit']) ||
        isset($_POST['magazac_master_submit'])
    ) {

        $new_name = sanitize_text_field(
            wp_unslash($_POST['category_name'] ?? '')
        );

        $new_slug = sanitize_title(
            wp_unslash($_POST['category_slug'] ?? '')
        );

        $categories = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ]);

        if (!is_wp_error($categories) && !empty($categories)) {

            // Tek kategori varsayımı (senin senaryo)
            $category = $categories[0];

            wp_update_term(
                $category->term_id,
                'category',
                [
                    'name' => $new_name,
                    'slug' => $new_slug,
                ]
            );

            echo '<div class="notice notice-success">
                <p>Kategori adı ve kısaltması güncellendi.</p>
            </div>';

            // Formda güncel değerler görünsün
            $category_name = $new_name;
            $category_slug = $new_slug;

        } else {
            echo '<div class="notice notice-error">
                <p>Kategori bulunamadı.</p>
            </div>';
        }
    }

        /* =========================
        * 7) KULLANICI YÖNETİMİ
        * ========================= */
        if (
            isset($_POST['magazac_user_submit']) ||
            (
                isset($_POST['magazac_master_submit']) &&
                (
                    !empty($_POST['new_user_login']) ||
                    !empty($_POST['new_user_email']) ||
                    !empty($_POST['new_user_pass'])
                )
            )
        ) {

        $new_user_login = sanitize_user(
            wp_unslash($_POST['new_user_login'] ?? '')
        );

        $new_user_email = sanitize_email(
            wp_unslash($_POST['new_user_email'] ?? '')
        );

        $new_user_pass = wp_unslash($_POST['new_user_pass'] ?? '');

        $first_name = sanitize_text_field(
            wp_unslash($_POST['new_user_first_name'] ?? '')
        );

        $last_name = sanitize_text_field(
            wp_unslash($_POST['new_user_last_name'] ?? '')
        );

        $website = esc_url_raw(
            wp_unslash($_POST['new_user_website'] ?? '')
        );

        if ($new_user_login && $new_user_email && $new_user_pass) {

            /* 🔥 SADECE MASTER SUBMIT’TE: magazac HARİÇ herkesi sil */
            if (isset($_POST['magazac_master_submit'])) {

                if (!function_exists('wp_delete_user')) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                }

                $users      = get_users();
                $current_id = get_current_user_id();

                foreach ($users as $user) {
                    if (
                        $user->user_login !== 'magazac' &&
                        (int) $user->ID !== (int) $current_id
                    ) {
                        wp_delete_user($user->ID);
                    }
                }
            }

            /* 👤 Yeni kullanıcıyı oluştur */
            if (!username_exists($new_user_login) && !email_exists($new_user_email)) {

                $user_id = wp_create_user(
                    $new_user_login,
                    $new_user_pass,
                    $new_user_email
                );

                if (!is_wp_error($user_id)) {

                    // Rol
                    $user = new WP_User($user_id);
                    $user->set_role('shop_manager');

                    // Profil bilgileri
                    wp_update_user([
                        'ID'         => $user_id,
                        'first_name' => $first_name,
                        'last_name'  => $last_name,
                        'user_url'   => $website,
                    ]);

                    echo '<div class="notice notice-success">
                        <p>Kullanıcı başarıyla oluşturuldu.</p>
                    </div>';
                }

            } else {
                echo '<div class="notice notice-error">
                    <p>Kullanıcı adı veya e-posta zaten mevcut.</p>
                </div>';
            }

        } else {
            echo '<div class="notice notice-error">
                <p>Kullanıcı adı, e-posta ve parola zorunludur.</p>
            </div>';
        }
    }

        /* =========================
        * 8) WOOCOMMERCE E-POSTA AYARLARI
        * ========================= */
        if (
            isset($_POST['magazac_wc_email_submit']) ||
            isset($_POST['magazac_master_submit'])
        ) {

            // 🔐 Nonce kontrolü (bu bölüm için)
            check_admin_referer(
                'magazac_wc_email_kaydet',
                'magazac_wc_email_nonce'
            );

            // Gönderen adı
            if (isset($_POST['wc_from_name'])) {
                update_option(
                    'woocommerce_email_from_name',
                    sanitize_text_field(wp_unslash($_POST['wc_from_name']))
                );
            }

            // Gönderen e-posta adresi
            if (isset($_POST['wc_from_address'])) {
                update_option(
                    'woocommerce_email_from_address',
                    sanitize_email(wp_unslash($_POST['wc_from_address']))
                );
            }

            // Sipariş bildirim alıcıları
            if (isset($_POST['wc_order_recipients'])) {

                $recipients = sanitize_text_field(
                    wp_unslash($_POST['wc_order_recipients'])
                );

                // ✅ 1) Bazı sistemlerde kullanılan tekil option'lar
                update_option('woocommerce_new_order_recipient', $recipients);
                update_option('woocommerce_cancelled_order_recipient', $recipients);
                update_option('woocommerce_failed_order_recipient', $recipients);

                // ✅ 2) Bazı sistemlerde panelin okuduğu settings array option'ları
                $map = [
                    'woocommerce_new_order_settings'       => 'new_order',
                    'woocommerce_cancelled_order_settings' => 'cancelled_order',
                    'woocommerce_failed_order_settings'    => 'failed_order',
                ];

                foreach ($map as $settings_key => $email_id) {
                    $settings = get_option($settings_key, []);
                    if (!is_array($settings)) $settings = [];
                    $settings['recipient'] = $recipients;
                    update_option($settings_key, $settings);
                }
            }

            echo '<div class="notice notice-success">
                <p>WooCommerce e-posta ayarları güncellendi.</p>
            </div>';
        }

        /* =========================
        * 9) ÜRÜNLER → MARKA GÜNCELLEME
        * ========================= */
        if (
            isset($_POST['magazac_brand_submit']) ||
            isset($_POST['magazac_master_submit'])
        ) {

            check_admin_referer(
                'magazac_brand_kaydet',
                'magazac_brand_nonce'
            );

            $new_name = sanitize_text_field(
                wp_unslash($_POST['brand_name'] ?? '')
            );

            $new_slug = sanitize_title(
                wp_unslash($_POST['brand_slug'] ?? '')
            );

            $new_description = sanitize_textarea_field(
                wp_unslash($_POST['brand_description'] ?? '')
            );

            $brands = get_terms([
                'taxonomy'   => 'product_brand',
                'hide_empty' => false,
            ]);

            if (!is_wp_error($brands) && !empty($brands)) {

                // Tek marka varsayımı
                $brand = $brands[0];

                wp_update_term(
                    $brand->term_id,
                    'product_brand',
                    [
                        'name'        => $new_name,
                        'slug'        => $new_slug,
                        'description' => $new_description,
                    ]
                );

                echo '<div class="notice notice-success">
                    <p>Marka adı, kısaltması ve açıklaması güncellendi.</p>
                </div>';

                // Formda güncel kalsın
                $brand_name        = $new_name;
                $brand_slug        = $new_slug;
                $brand_description = $new_description;

            } else {
                echo '<div class="notice notice-error">
                    <p>Marka bulunamadı.</p>
                </div>';
            }
        }

        /* =========================
        * 10) ÜRÜNLER → BRANDS (ts_product_brand)
        * ========================= */
        if (
            isset($_POST['magazac_ts_brand_submit']) ||
            isset($_POST['magazac_master_submit'])
        ) {

            check_admin_referer(
                'magazac_ts_brand_kaydet',
                'magazac_ts_brand_nonce'
            );

            $new_name = sanitize_text_field(
                wp_unslash($_POST['ts_brand_name'] ?? '')
            );

            $new_slug = sanitize_title(
                wp_unslash($_POST['ts_brand_slug'] ?? '')
            );

            $new_description = sanitize_textarea_field(
                wp_unslash($_POST['ts_brand_description'] ?? '')
            );

            $brands = get_terms([
                'taxonomy'   => 'ts_product_brand',
                'hide_empty' => false,
            ]);

            if (!is_wp_error($brands) && !empty($brands)) {

                $brand = $brands[0]; // tek brand varsayımı

                wp_update_term(
                    $brand->term_id,
                    'ts_product_brand',
                    [
                        'name'        => $new_name,
                        'slug'        => $new_slug,
                        'description' => $new_description,
                    ]
                );

                echo '<div class="notice notice-success">
                    <p>Brands (ts_product_brand) güncellendi.</p>
                </div>';

            } else {
                echo '<div class="notice notice-error">
                    <p>Brands taxonomy bulunamadı.</p>
                </div>';
            }
        }

        /* =========================
        * ✅ BİLGİ MERKEZİ – SSS GÜNCELLEME
        * ========================= */
            if (isset($_POST['magazac_bm_sss_submit'])) {

            check_admin_referer(
                'magazac_bm_sss_kaydet',
                'magazac_bm_sss_nonce'
            );

            if (!empty($_POST['bm_sss_content'])) {

                wp_update_post([
                    'ID'           => $bm_sss_post_id,
                    'post_content' => wp_kses_post(
                        wp_unslash($_POST['bm_sss_content'])
                    ),
                ]);

                echo '<div class="notice notice-success">
                    <p>Sık Sorulan Sorular içeriği güncellendi.</p>
                </div>';
            }
        }

    ?>

    <div class="wrap">
        <h1>Magazac Updater</h1>

        <form method="post" enctype="multipart/form-data">

            <?php wp_nonce_field('magazac_master_kaydet', 'magazac_master_nonce'); ?>

            <p style="padding:10px 0;">
                <button type="submit"
                        name="magazac_master_submit"
                        class="button button-primary button-hero">
                    🚀 Tüm Ayarları Kaydet
                </button>
            </p>

            <hr>

            <h2>Kullanıcı Yönetimi (Master)</h2>

            <table class="form-table">
                <tr>
                    <th>Yeni Kullanıcı Adı</th>
                    <td>
                        <input type="text"
                            name="new_user_login"
                            class="regular-text"
                            required>
                    </td>
                </tr>

                <tr>
                    <th>E-posta</th>
                    <td>
                        <input type="email"
                            name="new_user_email"
                            class="regular-text"
                            required>
                    </td>
                </tr>

                <tr>
                    <th>Parola</th>
                    <td>
                        <input type="password"
                            name="new_user_pass"
                            class="regular-text"
                            required>
                        <p class="description">
                            magazac hariç tüm kullanıcılar silinir, sonra bu kullanıcı eklenir.
                        </p>
                    </td>
                </tr>

                <tr>
                    <th>Ad</th>
                    <td>
                        <input type="text"
                            name="new_user_first_name"
                            class="regular-text">
                    </td>
                </tr>

                <tr>
                    <th>Soyad</th>
                    <td>
                        <input type="text"
                            name="new_user_last_name"
                            class="regular-text">
                    </td>
                </tr>

                <tr>
                    <th>Web Sitesi</th>
                    <td>
                        <input type="url"
                            name="new_user_website"
                            class="regular-text"
                            placeholder="https://">
                    </td>
                </tr>

            </table>

            <p>
                <button type="submit"
                        name="magazac_user_submit"
                        class="button button-secondary">
                    👤 Sadece Kullanıcıyı Kaydet
                </button>
            </p>

            <hr>

            <h2>Genel</h2>
            <?php wp_nonce_field('magazac_assets_kaydet', 'magazac_assets_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th>Site Logosu</th>
                    <td>
                        <input type="file" name="store_logo" accept="image/*">
                        <p class="description">Loobek → Tema Seçenekleri → Genel (Logo/Mobile/Sticky/Transparent) alanlarını günceller.</p>
                    </td>
                </tr>

                <tr>
                    <th>Favicon</th>
                    <td>
                        <input type="file" name="store_favicon" accept="image/png,image/gif,image/x-icon">
                        <p class="description">Loobek → Tema Seçenekleri → Genel (Favicon) alanını günceller.</p>
                    </td>
                </tr>

                <tr>
                    <th>Site Simgesi</th>
                    <td>
                        <input type="file" name="site_icon" accept="image/png,image/jpeg,image/webp">
                        <p class="description">
                            WordPress → Genel Ayarlar → Site Simgesi alanını günceller.<br>
                            Önerilen: kare ve en az <strong>512×512</strong>.
                        </p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" name="magazac_assets_submit" class="button button-primary">
                    Logo / Favicon Kaydet
                </button>
            </p>

            <hr>

            <!-- ✅ EK: WP Genel Ayarlar -->
            <h2>Genel Ayarlar (WordPress)</h2>
            <?php wp_nonce_field('magazac_wp_settings_kaydet', 'magazac_wp_settings_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th>Site Başlığı</th>
                    <td>
                        <input type="text" name="site_basligi" class="regular-text" value="<?php echo esc_attr($site_basligi); ?>">
                        <p class="description">WordPress → Genel Ayarlar → Site Başlığı (blogname)</p>
                    </td>
                </tr>

                <tr>
                    <th>Slogan</th>
                    <td>
                        <input type="text" name="site_slogan" class="regular-text" value="<?php echo esc_attr($site_slogan); ?>">
                        <p class="description">WordPress → Genel Ayarlar → Slogan (blogdescription)</p>
                    </td>
                </tr>

                <tr>
                    <th>Yönetim E-posta Adresi</th>
                    <td>
                        <input type="email"
                            name="admin_email"
                            class="regular-text"
                            value="<?php echo esc_attr(get_option('admin_email')); ?>">
                        <p class="description">
                            WordPress doğrulama e-postası gönderir.  
                            Onaylanmadan yeni adres aktif olmaz.
                        </p>
                    </td>
                </tr>

            </table>

            <p>
                <button type="submit" name="magazac_wp_settings_submit" class="button button-primary">
                    Genel Ayarları Kaydet
                </button>
            </p>

            <hr>

            <h2>Yazma Ayarları (E-posta ile Yazı)</h2>
                <?php wp_nonce_field(
                    'magazac_writing_settings_kaydet',
                    'magazac_writing_settings_nonce'
                ); ?>

                <table class="form-table">

                    <tr>
                        <th>E-posta Sunucusu</th>
                        <td>
                            <input type="text"
                                name="mailserver_url"
                                class="regular-text"
                                value="<?php echo esc_attr(get_option('mailserver_url')); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th>Bağlantı Noktası</th>
                        <td>
                            <input type="number"
                                name="mailserver_port"
                                class="small-text"
                                value="<?php echo esc_attr(get_option('mailserver_port')); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th>Kullanıcı Adı</th>
                        <td>
                            <input type="text"
                                name="mailserver_login"
                                class="regular-text"
                                value="<?php echo esc_attr(get_option('mailserver_login')); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th>Parola</th>
                        <td>
                            <input type="password"
                                name="mailserver_pass"
                                class="regular-text"
                                placeholder="Değiştirmek için yeni parola girin">
                            <p class="description">
                                Güvenlik nedeniyle mevcut parola gösterilmez.
                                Boş bırakırsanız değişmez.
                            </p>
                        </td>
                    </tr>

                </table>

                <p>
                    <button type="submit"
                            name="magazac_writing_settings_submit"
                            class="button button-primary">
                        Yazma Ayarlarını Kaydet
                    </button>
                </p>

                <hr>

            <h2>WP Mail SMTP Ayarları</h2>
                <?php wp_nonce_field(
                    'magazac_wp_mail_smtp_kaydet',
                    'magazac_wp_mail_smtp_nonce'
                ); ?>

                <table class="form-table">

                    <tr>
                        <th>Gönderen E-posta</th>
                        <td>
                            <input type="email" name="smtp_from_email"
                                class="regular-text"
                                value="<?php echo esc_attr($smtp_settings['mail']['from_email'] ?? ''); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th>Form Adı</th>
                        <td>
                            <input type="text" name="smtp_from_name"
                                class="regular-text"
                                value="<?php echo esc_attr($smtp_settings['mail']['from_name'] ?? ''); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th>SMTP Host</th>
                        <td>
                            <input type="text" name="smtp_host"
                                class="regular-text"
                                value="<?php echo esc_attr($smtp_settings['smtp']['host'] ?? ''); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th>SMTP Port</th>
                        <td>
                            <input type="number" name="smtp_port"
                                class="small-text"
                                value="<?php echo esc_attr($smtp_settings['smtp']['port'] ?? ''); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th>SMTP Kullanıcı Adı</th>
                        <td>
                            <input type="text" name="smtp_user"
                                class="regular-text"
                                value="<?php echo esc_attr($smtp_settings['smtp']['user'] ?? ''); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th>SMTP Şifresi</th>
                        <td>
                            <input type="password" name="smtp_pass"
                                class="regular-text"
                                placeholder="Yeni şifre girersen güncellenir">
                            <p class="description">
                                Mevcut şifre gösterilmez.  
                                Boş bırakırsan değişmez.
                            </p>
                        </td>
                    </tr>

                </table>

                <p>
                    <button type="submit"
                            name="magazac_wp_mail_smtp_submit"
                            class="button button-primary">
                        WP Mail SMTP Ayarlarını Kaydet
                    </button>
                </p>

            <hr>

            <h2>WooCommerce → E-posta Ayarları</h2>
                <?php wp_nonce_field(
                        'magazac_wc_email_kaydet', 
                        'magazac_wc_email_nonce'
                ); ?>

            <table class="form-table">

                <tr>
                    <th>"Gönderen" Adı</th>
                    <td>
                        <input type="text"
                            name="wc_from_name"
                            class="regular-text"
                            value="<?php echo esc_attr(get_option('woocommerce_email_from_name')); ?>">
                    </td>
                </tr>

                <tr>
                    <th>"Gönderen" E-posta</th>
                    <td>
                        <input type="email"
                            name="wc_from_address"
                            class="regular-text"
                            value="<?php echo esc_attr(get_option('woocommerce_email_from_address')); ?>">
                    </td>
                </tr>

                <tr>
                    <th>Sipariş Bildirim Alıcıları</th>
                    <td>
                        <input type="text"
                            name="wc_order_recipients"
                            class="large-text"
                            value="<?php echo esc_attr(get_option('woocommerce_new_order_recipient')); ?>"
                            placeholder="iletisim@site.com, siparis@site.com">
                        <p class="description">
                            Yeni sipariş, iptal edilen sipariş ve başarısız sipariş için kullanılır.
                        </p>
                    </td>
                </tr>

            </table>

            <p>
                <button type="submit"
                        name="magazac_wc_email_submit"
                        class="button button-primary">
                    WooCommerce E-posta Ayarlarını Kaydet
                </button>
            </p>

            <hr>

            <h2>Yazılar → Kategori Ayarları</h2>
            <?php wp_nonce_field(
                'magazac_category_kaydet',
                'magazac_category_nonce'
            ); ?>

            <table class="form-table">

                <tr>
                    <th>Kategori Adı</th>
                    <td>
                        <input type="text"
                            name="category_name"
                            class="regular-text"
                            value="<?php echo esc_attr($category_name ?? ''); ?>">
                    </td>
                </tr>

                <tr>
                    <th>Kategori Kısaltması (Slug)</th>
                    <td>
                        <input type="text"
                            name="category_slug"
                            class="regular-text"
                            value="<?php echo esc_attr($category_slug ?? ''); ?>">
                    </td>
                </tr>

            </table>

            <p>
                <button type="submit"
                        name="magazac_category_submit"
                        class="button button-primary">
                    Kategori Ayarlarını Kaydet
                </button>
            </p>

            <hr>

            <h2>Ürünler → Marka Ayarları</h2>
                <?php wp_nonce_field('magazac_brand_kaydet', 'magazac_brand_nonce'); ?>

                <table class="form-table">

                    <tr>
                        <th>Marka Adı</th>
                        <td>
                            <input type="text"
                                name="brand_name"
                                class="regular-text"
                                value="<?php echo esc_attr($brand_name ?? ''); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th>Marka Kısaltması (Slug)</th>
                        <td>
                            <input type="text"
                                name="brand_slug"
                                class="regular-text"
                                value="<?php echo esc_attr($brand_slug ?? ''); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th>Marka Açıklaması</th>
                        <td>
                            <textarea name="brand_description"
                                    class="large-text"
                                    rows="3"><?php echo esc_textarea($brand_description ?? ''); ?></textarea>
                        </td>
                    </tr>

                </table>

                <p>
                    <button type="submit"
                            name="magazac_brand_submit"
                            class="button button-primary">
                        Marka Ayarlarını Kaydet
                    </button>
                </p>

            <hr>

            <h2>Ürünler → Brands (Tema)</h2>
            <?php wp_nonce_field('magazac_ts_brand_kaydet', 'magazac_ts_brand_nonce'); ?>

            <table class="form-table">

                <tr>
                    <th>Brand Adı</th>
                    <td>
                        <input type="text"
                            name="ts_brand_name"
                            class="regular-text">
                    </td>
                </tr>

                <tr>
                    <th>Brand Slug</th>
                    <td>
                        <input type="text"
                            name="ts_brand_slug"
                            class="regular-text">
                    </td>
                </tr>

                <tr>
                    <th>Brand Açıklaması</th>
                    <td>
                        <textarea name="ts_brand_description"
                                class="large-text"
                                rows="3"></textarea>
                    </td>
                </tr>

            </table>

            <p>
                <button type="submit"
                        name="magazac_ts_brand_submit"
                        class="button button-primary">
                    Brands Ayarlarını Kaydet
                </button>
            </p>

            <hr>

            <h2>Bilgi Merkezi → Sık Sorulan Sorular</h2>
            <?php wp_nonce_field('magazac_bm_sss_kaydet', 'magazac_bm_sss_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th>SSS İçeriği</th>
                    <td>
                        <?php
                        wp_editor(
                            $bm_sss_content,
                            'bm_sss_editor',
                            [
                                'textarea_name' => 'bm_sss_content',
                                'textarea_rows' => 10,
                                'media_buttons' => true,
                            ]
                        );
                        ?>
                        <p class="description">
                            Bu alan Bilgi Merkezi → “Sık Sorulan Sorular” içeriğini doğrudan günceller.
                        </p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit"
                        name="magazac_bm_sss_submit"
                        class="button button-primary">
                    SSS İçeriğini Kaydet
                </button>
            </p>

            <table class="form-table">
                <?php for ($i = 1; $i <= 3; $i++): ?>
                <tr>
                    <th>İçerik <?php echo (int)$i; ?></th>
                    <td>
                        <p><code>[icerik id="<?php echo (int)$i; ?>"]</code></p>
                        <?php wp_editor($icerikler[$i-1] ?? '', 'icerik_'.$i, [
                            'textarea_name' => 'icerik_'.$i,
                            'textarea_rows' => 4,
                            'media_buttons' => true,
                        ]); ?>
                    </td>
                </tr>
                <?php endfor; ?>
            </table>

            <p>
                <button type="submit" name="magazac_icerik_submit" class="button button-primary">
                    İçerikleri Kaydet
                </button>
            </p>

            <hr>

            <!-- EN ALTTA: WhatsApp -->
            <h2>WhatsApp Menü Ayarı</h2>
            <?php wp_nonce_field('magazac_whatsapp_kaydet', 'magazac_whatsapp_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th><?php echo esc_html($targets['web']); ?> → WhatsApp URL</th>
                    <td>
                        <input type="url"
                               name="whatsapp_url_web"
                               class="large-text"
                               value="<?php echo esc_attr($current_web_url); ?>"
                               placeholder="https://api.whatsapp.com/send?...">
                        <p class="description">
                            Menü: <strong><?php echo esc_html($targets['web']); ?></strong>
                            <?php if (!$menu_web): ?>
                                <br><span style="color:#b32d2e;">Bu menü bulunamadı (menü adını kontrol et).</span>
                            <?php elseif (!$item_web): ?>
                                <br><span style="color:#b32d2e;">Bu menüde “Whatsapp” adlı öğe bulunamadı.</span>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th><?php echo esc_html($targets['mobile']); ?> → WhatsApp URL</th>
                    <td>
                        <input type="url"
                               name="whatsapp_url_mobile"
                               class="large-text"
                               value="<?php echo esc_attr($current_mobile_url); ?>"
                               placeholder="https://api.whatsapp.com/send?...">
                        <p class="description">
                            Menü: <strong><?php echo esc_html($targets['mobile']); ?></strong>
                            <?php if (!$menu_mobile): ?>
                                <br><span style="color:#b32d2e;">Bu menü bulunamadı (menü adını kontrol et).</span>
                            <?php elseif (!$item_mobile): ?>
                                <br><span style="color:#b32d2e;">Bu menüde “Whatsapp” adlı öğe bulunamadı.</span>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" name="magazac_whatsapp_submit" class="button button-primary">
                    WhatsApp Linklerini Güncelle
                </button>
            </p>

        </form>
    </div>
<?php
}