<?php
/**
 * Plugin Name: English Title Slug Converter
 * Description: 日本語の記事タイトルを英語に翻訳し、URL 用のスラッグとして設定します。Google Cloud Translation API の設定（API キー）を管理画面から行えます。
 * Version: 1.2
 * Author: Jackie Wu
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // 直接アクセス防止
}

/**
 * 翻訳 API を利用して日本語タイトルを英語に翻訳する関数
 *
 * Google Cloud Translation API の API キーは管理画面で設定した値を利用します。
 *
 * @param string $title 日本語タイトル
 * @param string $target 翻訳先言語（例: 'en'）
 * @return string 翻訳後のタイトル（エラー時は元のタイトルを返す）
 */
function lsc_translate_title( $title, $target = 'en' ) {
    // 管理画面で設定した API キーを取得（未設定なら空文字）
    $apiKey = get_option( 'lsc_google_api_key', '' );
    if ( empty( $apiKey ) ) {
        // API キーが未設定の場合は、翻訳せずに元のタイトルを返す
        return $title;
    }
    $url = "https://translation.googleapis.com/language/translate/v2?key=" . $apiKey;

    $body = array(
        'q'      => $title,
        'target' => $target,
        'format' => 'text',
    );
    $args = array(
        'body'    => json_encode( $body ),
        'headers' => array( 'Content-Type' => 'application/json' ),
        'timeout' => 10,
    );

    $response = wp_remote_post( $url, $args );
    if ( is_wp_error( $response ) ) {
        return $title;
    }

    $response_body = wp_remote_retrieve_body( $response );
    $result = json_decode( $response_body, true );
    if ( isset( $result['data']['translations'][0]['translatedText'] ) ) {
        return $result['data']['translations'][0]['translatedText'];
    }
    return $title;
}

/**
 * 翻訳後のタイトルを URL 用に整形する関数
 */
function lsc_format_title_to_slug( $title ) {
    $title = strtolower( $title );
    // 英数字とハイフン、スペース以外を除去
    $title = preg_replace( '/[^a-z0-9\s-]/', '', $title );
    $title = trim( $title );
    // 空白や連続ハイフンを単一のハイフンに変換
    $title = preg_replace( '/[\s-]+/', '-', $title );
    return $title;
}

/**
 * 日本語タイトルを翻訳してから URL 用スラッグを生成する関数
 */
function lsc_convert_title_to_slug( $title ) {
    // 日本語タイトルを英語に翻訳
    $translatedTitle = lsc_translate_title( $title, 'en' );
    return lsc_format_title_to_slug( $translatedTitle );
}

/**
 * 投稿データ保存前にスラッグ（post_name）を変更するフィルター
 */
function lsc_modify_post_slug( $data, $postarr ) {
    // 既にスラッグが設定されている場合は変更しない
    if ( ! empty( $postarr['ID'] ) ) {
        $existing_slug = get_post_field( 'post_name', $postarr['ID'] );
        if ( ! empty( $existing_slug ) ) {
            return $data;
        }
    }

    // 投稿タイプが "post" の記事に対して処理する
    if ( 'post' !== $data['post_type'] ) {
        return $data;
    }

    // 設定で選択された変換パターンを取得（デフォルトはタイトルのみ）
    $convert_pattern = get_option( 'lsc_convert_pattern', 'title' );
    $slug = '';

    if ( 'title' === $convert_pattern ) {
        // タイトルのみの変換
        $slug = lsc_convert_title_to_slug( $data['post_title'] );
    } elseif ( 'date_title' === $convert_pattern ) {
        // 公開日を YYYYMMDD 形式で前に付与し、アンダースコアで連結
        if ( ! empty( $data['post_date'] ) && '0000-00-00 00:00:00' !== $data['post_date'] ) {
            $date = date( 'Ymd', strtotime( $data['post_date'] ) );
        } else {
            $date = current_time( 'Ymd' );
        }
        $slug = $date . '_' . lsc_convert_title_to_slug( $data['post_title'] );
    }

    if ( ! empty( $slug ) ) {
        $data['post_name'] = $slug;
    }
    return $data;
}
add_filter( 'wp_insert_post_data', 'lsc_modify_post_slug', 10, 2 );


/**
 * 管理画面の設定登録
 */
function lsc_register_settings() {
    // 翻訳パターンと Google API キーを登録
    register_setting( 'lsc_settings_group', 'lsc_convert_pattern' );
    register_setting( 'lsc_settings_group', 'lsc_google_api_key' );

    add_settings_section( 'lsc_main_section', 'Slug 変換設定', '__return_false', 'lsc_settings_page' );

    // 変換パターン選択フィールド
    add_settings_field( 'lsc_field_convert_pattern', '変換パターンの選択', 'lsc_convert_pattern_callback', 'lsc_settings_page', 'lsc_main_section' );
    // Google Cloud Translation API キー入力フィールド
    add_settings_field( 'lsc_field_google_api_key', 'Google Cloud Translation API キー', 'lsc_google_api_key_callback', 'lsc_settings_page', 'lsc_main_section' );
}
add_action( 'admin_init', 'lsc_register_settings' );

/**
 * 変換パターン選択フィールドのコールバック
 */
function lsc_convert_pattern_callback() {
    $value = get_option( 'lsc_convert_pattern', 'title' );
    ?>
    <select name="lsc_convert_pattern">
        <option value="title" <?php selected( $value, 'title' ); ?>>タイトルだけの変換</option>
        <option value="date_title" <?php selected( $value, 'date_title' ); ?>>公開日_タイトルの変換</option>
    </select>
    <?php
}

/**
 * Google Cloud Translation API キー入力フィールドのコールバック
 */
function lsc_google_api_key_callback() {
    $value = get_option( 'lsc_google_api_key', '' );
    ?>
    <input type="text" name="lsc_google_api_key" value="<?php echo esc_attr( $value ); ?>" size="50" />
    <p class="description">Google Cloud Translation API の API キーを入力してください。</p>
    <?php
}

/**
 * 設定ページを管理画面の「設定」メニューに追加する
 */
function lsc_add_settings_page() {
    add_options_page( 'English Title Slug Converter 設定', 'Slug Converter', 'manage_options', 'lsc_settings_page', 'lsc_settings_page_display' );
}
add_action( 'admin_menu', 'lsc_add_settings_page' );

/**
 * 設定ページの表示
 */
function lsc_settings_page_display() {
    ?>
    <div class="wrap">
        <h1>Slug Converter 設定</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'lsc_settings_group' );
            do_settings_sections( 'lsc_settings_page' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}
