<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Chat_Widget {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_footer',          array( $this, 'render_widget' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    public function enqueue_scripts() {
        $settings = get_option( 'wp_ru_max_settings', array() );
        if ( empty( $settings['chat_widget_enabled'] ) ) {
            return;
        }
        wp_enqueue_style(
            'wp-ru-max-chat',
            WP_RU_MAX_PLUGIN_URL . 'assets/chat-widget.css',
            array(),
            WP_RU_MAX_VERSION
        );
        wp_enqueue_script(
            'wp-ru-max-chat',
            WP_RU_MAX_PLUGIN_URL . 'assets/chat-widget.js',
            array(),
            WP_RU_MAX_VERSION,
            true
        );
    }

    /**
     * Map size key → pixel size + image file.
     */
    public static function get_size_config( $size ) {
        $map = array(
            'small'  => array( 'px' => 42,  'img' => 'max-32x32.png' ),
            'medium' => array( 'px' => 64,  'img' => 'max-64x64.png' ),
            'large'  => array( 'px' => 80,  'img' => 'max-256x256.png' ),
        );
        return isset( $map[ $size ] ) ? $map[ $size ] : $map['medium'];
    }

    public function render_widget() {
        $settings = get_option( 'wp_ru_max_settings', array() );

        if ( empty( $settings['chat_widget_enabled'] ) ) {
            return;
        }

        $size          = isset( $settings['chat_widget_size'] )          ? $settings['chat_widget_size']                  : 'medium';
        $url           = isset( $settings['chat_widget_url'] )           ? trim( $settings['chat_widget_url'] )           : '';
        $message       = isset( $settings['chat_widget_message'] )       ? $settings['chat_widget_message']               : 'Здравствуйте! У вас есть вопросы!? Мы всегда на связи. Кликните, чтобы нам написать!';
        $position      = isset( $settings['chat_widget_position'] )      ? $settings['chat_widget_position']              : 'right';
        $bottom_offset = isset( $settings['chat_widget_bottom_offset'] ) ? (int) $settings['chat_widget_bottom_offset']   : 20;
        $show_delay    = isset( $settings['chat_widget_show_delay'] )    ? (int) $settings['chat_widget_show_delay']      : 0;
        $sound         = isset( $settings['chat_widget_sound'] )         ? $settings['chat_widget_sound']                 : 'none';
        $sound_delay   = isset( $settings['chat_widget_sound_delay'] )   ? (int) $settings['chat_widget_sound_delay']     : 3;
        $animation     = isset( $settings['chat_widget_animation'] )     ? $settings['chat_widget_animation']             : 'none';
        $retention_enabled = ! empty( $settings['chat_widget_retention_enabled'] );
        $retention_title   = isset( $settings['chat_widget_retention_title'] )   ? $settings['chat_widget_retention_title']   : 'Специальное предложение!';
        $retention_message = isset( $settings['chat_widget_retention_message'] ) ? $settings['chat_widget_retention_message'] : 'Уже уходите? Получите скидку 10% на первый заказ, если ответим на ваш вопрос в течение 5 минут!';
        $r_text_align    = $settings['chat_widget_retention_text_align']    ?? 'left';
        $r_btn_align     = $settings['chat_widget_retention_buttons_align'] ?? 'right';
        $r_btn_radius    = isset( $settings['chat_widget_retention_btn_radius'] ) ? (int) $settings['chat_widget_retention_btn_radius'] : 8;
        $r_stay_text     = $settings['chat_widget_retention_stay_text']     ?? 'Остаться';
        $r_leave_text    = $settings['chat_widget_retention_leave_text']    ?? 'Все равно уйти';
        $r_stay_bg       = $settings['chat_widget_retention_stay_bg']       ?? '#4a90d9';
        $r_stay_color    = $settings['chat_widget_retention_stay_color']    ?? '#ffffff';
        $r_leave_bg      = $settings['chat_widget_retention_leave_bg']      ?? '#f0f0f0';
        $r_leave_color   = $settings['chat_widget_retention_leave_color']   ?? '#555555';

        $align_map = array( 'left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end' );
        $btn_justify = isset( $align_map[ $r_btn_align ] ) ? $align_map[ $r_btn_align ] : 'flex-end';
        $text_align  = in_array( $r_text_align, array( 'left', 'center', 'right' ), true ) ? $r_text_align : 'left';

        $cfg = self::get_size_config( $size );
        $px  = (int) $cfg['px'];
        $img = $cfg['img'];

        $href        = ! empty( $url ) ? esc_url( $url ) : '#';
        $target      = ! empty( $url ) ? ' target="_blank" rel="noopener noreferrer"' : '';
        $side_css    = ( 'left' === $position ) ? 'left:20px;right:auto;' : 'right:20px;left:auto;';
        $balloon_css = ( 'left' === $position ) ? 'left:0;' : 'right:0;';
        $arrow_css   = ( 'left' === $position ) ? 'left:14px;' : 'right:14px;';
        $icon_url    = esc_url( WP_RU_MAX_PLUGIN_URL . 'assets/' . $img );
        $anim_class  = ! empty( $animation ) && $animation !== 'none' ? ' wp-ru-max-anim-' . esc_attr( $animation ) : '';
        ?>
<div id="wp-ru-max-widget" style="position:fixed;bottom:<?php echo (int) $bottom_offset; ?>px;<?php echo esc_attr( $side_css ); ?>z-index:99999;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;display:none;">
    <div id="wp-ru-max-balloon" style="position:absolute;bottom:<?php echo ( $px + 14 ); ?>px;<?php echo esc_attr( $balloon_css ); ?>background:#fff;border:1px solid #e0e0e0;border-radius:14px;padding:12px 16px 12px 16px;max-width:265px;min-width:265px;box-shadow:0 4px 24px rgba(0,0,0,0.15);display:none;word-break:break-word;">
        <button id="wp-ru-max-close" type="button" style="position:absolute;top:6px;right:8px;background:none;border:none;cursor:pointer;color:#aaa;font-size:18px;line-height:1;padding:0 2px;" title="Закрыть" aria-label="Закрыть">&times;</button>
        <div id="wp-ru-max-typing" style="color:#222;font-size:14px;line-height:1.5;padding-right:18px;"></div>
        <div style="position:absolute;bottom:-8px;<?php echo esc_attr( $arrow_css ); ?>width:0;height:0;border-left:8px solid transparent;border-right:8px solid transparent;border-top:8px solid #fff;"></div>
    </div>
    <a href="<?php echo $href; ?>"<?php echo $target; ?>
       id="wp-ru-max-icon"
       class="wp-ru-max-icon<?php echo $anim_class; ?>"
       style="display:block;width:<?php echo $px; ?>px;height:<?php echo $px; ?>px;border-radius:50%;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.28);cursor:pointer;text-decoration:none;transition:transform 0.2s ease,box-shadow 0.2s ease;"
       aria-label="Написать нам в MAX"
       onmouseover="this.style.transform='scale(1.1)';this.style.boxShadow='0 6px 24px rgba(0,0,0,0.38)'"
       onmouseout="this.style.transform='scale(1)';this.style.boxShadow='0 4px 16px rgba(0,0,0,0.28)'">
        <img src="<?php echo $icon_url; ?>"
             width="<?php echo $px; ?>"
             height="<?php echo $px; ?>"
             alt="MAX"
             style="display:block;width:100%;height:100%;object-fit:cover;" />
    </a>
    <script>
    window.wpRuMaxSettings = <?php echo wp_json_encode( array(
        'message'           => $message,
        'showDelay'         => $show_delay * 1000,
        'sound'             => $sound,
        'soundDelay'        => $sound_delay * 1000,
        'animation'         => $animation,
        'retentionEnabled'  => (bool) $retention_enabled,
    ) ); ?>;
    </script>
</div>
<?php if ( $retention_enabled ) :
    $btn_radius_css = (int) $r_btn_radius . 'px';
?>
<div id="wp-ru-max-retention-modal" class="wp-ru-max-retention-modal" role="dialog" aria-modal="true" aria-labelledby="wp-ru-max-retention-title">
    <div class="wp-ru-max-retention-content" style="text-align:<?php echo esc_attr( $text_align ); ?>;">
        <div class="wp-ru-max-retention-header">
            <h3 id="wp-ru-max-retention-title" style="text-align:<?php echo esc_attr( $text_align ); ?>;"><?php echo nl2br( esc_html( $retention_title ) ); ?></h3>
        </div>
        <div class="wp-ru-max-retention-body">
            <p style="text-align:<?php echo esc_attr( $text_align ); ?>;"><?php echo nl2br( esc_html( $retention_message ) ); ?></p>
        </div>
        <div class="wp-ru-max-retention-actions" style="justify-content:<?php echo esc_attr( $btn_justify ); ?>;">
            <button class="wp-ru-max-retention-stay" type="button" style="background:<?php echo esc_attr( $r_stay_bg ); ?>;color:<?php echo esc_attr( $r_stay_color ); ?>;border-radius:<?php echo esc_attr( $btn_radius_css ); ?>;"><?php echo esc_html( $r_stay_text ); ?></button>
            <button class="wp-ru-max-retention-leave" type="button" style="background:<?php echo esc_attr( $r_leave_bg ); ?>;color:<?php echo esc_attr( $r_leave_color ); ?>;border-radius:<?php echo esc_attr( $btn_radius_css ); ?>;"><?php echo esc_html( $r_leave_text ); ?></button>
        </div>
    </div>
</div>
<?php endif; ?>
        <?php
    }
}
