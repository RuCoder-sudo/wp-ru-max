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

        $size     = isset( $settings['chat_widget_size'] )     ? $settings['chat_widget_size']     : 'medium';
        $url      = isset( $settings['chat_widget_url'] )      ? trim( $settings['chat_widget_url'] ) : '';
        $message  = isset( $settings['chat_widget_message'] )  ? $settings['chat_widget_message']  : 'Здравствуйте! Мы всегда на связи. Кликните, чтобы нам написать!';
        $position = isset( $settings['chat_widget_position'] ) ? $settings['chat_widget_position'] : 'right';

        $cfg = self::get_size_config( $size );
        $px  = (int) $cfg['px'];
        $img = $cfg['img'];

        $href        = ! empty( $url ) ? esc_url( $url ) : '#';
        $target      = ! empty( $url ) ? ' target="_blank" rel="noopener noreferrer"' : '';
        $side_css    = ( 'left' === $position ) ? 'left:20px;right:auto;' : 'right:20px;left:auto;';
        $balloon_css = ( 'left' === $position ) ? 'left:0;' : 'right:0;';
        $arrow_css   = ( 'left' === $position ) ? 'left:14px;' : 'right:14px;';
        $icon_url    = esc_url( WP_RU_MAX_PLUGIN_URL . 'assets/' . $img );
        ?>
<div id="wp-ru-max-widget" style="position:fixed;bottom:20px;<?php echo esc_attr( $side_css ); ?>z-index:99999;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    <div id="wp-ru-max-balloon" style="position:absolute;bottom:<?php echo ( $px + 14 ); ?>px;<?php echo esc_attr( $balloon_css ); ?>background:#fff;border:1px solid #e0e0e0;border-radius:14px;padding:12px 16px;max-width:230px;min-width:150px;box-shadow:0 4px 24px rgba(0,0,0,0.15);display:none;word-break:break-word;">
        <div id="wp-ru-max-typing" style="color:#222;font-size:14px;line-height:1.5;"></div>
        <div style="position:absolute;bottom:-8px;<?php echo esc_attr( $arrow_css ); ?>width:0;height:0;border-left:8px solid transparent;border-right:8px solid transparent;border-top:8px solid #fff;"></div>
    </div>
    <a href="<?php echo $href; ?>"<?php echo $target; ?>
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
    <script>window.wpRuMaxMessage=<?php echo wp_json_encode( $message ); ?>;</script>
</div>
        <?php
    }
}
