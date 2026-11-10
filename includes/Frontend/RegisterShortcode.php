<?php

namespace VereinsPlugin\Frontend;

use VereinsPlugin\Members\MemberService;

/**
 * Shortcode [vp_register]
 *
 * Formular zur Selbstregistrierung. Legt einen WP-User (Rolle vp_member)
 * und eine vp_members-Zeile an. Je nach Einstellung "require_approval"
 * ist der Zugang sofort aktiv oder wartet auf Freischaltung.
 */
class RegisterShortcode {

    public static function describe(): array {
        return [
            'tag'         => 'vp_register',
            'title'       => __( 'Registrierung', 'vereins-plugin' ),
            'description' => __( 'Anmeldeformular für neue Mitglieder. Je nach Einstellung ist der Zugang sofort aktiv oder wartet auf Freischaltung.', 'vereins-plugin' ),
            'atts'        => [],
            'example'     => '[vp_register]',
        ];
    }

    public function register(): void {
        add_shortcode( 'vp_register', [ $this, 'render' ] );
        add_action( 'admin_post_nopriv_vp_register', [ $this, 'handle_submit' ] );
        add_action( 'admin_post_vp_register',         [ $this, 'handle_submit' ] );
    }

    public function render(): string {
        if ( is_user_logged_in() ) {
            return '<p class="vp-notice">' . esc_html__( 'Du bist bereits angemeldet.', 'vereins-plugin' ) . '</p>';
        }

        $status_param = sanitize_text_field( $_GET['vp_registered'] ?? '' );
        $error_param  = sanitize_text_field( $_GET['vp_error'] ?? '' );

        ob_start();

        if ( $status_param ) {
            $message = $status_param === 'active'
                ? __( 'Danke! Dein Zugang wurde erstellt und ist sofort aktiv – du kannst dich jetzt anmelden.', 'vereins-plugin' )
                : __( 'Danke für deine Anmeldung! Deine Anmeldung wird geprüft und in Kürze freigeschaltet.', 'vereins-plugin' );
            echo '<p class="vp-notice vp-notice--ok">' . esc_html( $message ) . '</p>';
            return ob_get_clean();
        }

        $action = admin_url( 'admin-post.php' );
        $nonce  = wp_create_nonce( 'vp_register' );
        ?>
        <div class="vp-form-wrap" id="vp-register">
            <?php if ( $error_param ) : ?>
                <p class="vp-notice vp-notice--err"><?php echo esc_html( $this->error_message( $error_param ) ); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( $action ); ?>" class="vp-form">
                <input type="hidden" name="action" value="vp_register">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
                <input type="hidden" name="vp_redirect" value="<?php echo esc_url( get_permalink() ?: home_url( '/' ) ); ?>">

                <div class="vp-form__group">
                    <label for="vp-reg-name"><?php esc_html_e( 'Name', 'vereins-plugin' ); ?></label>
                    <input type="text" id="vp-reg-name" name="display_name" required>
                </div>

                <div class="vp-form__group">
                    <label for="vp-reg-email"><?php esc_html_e( 'E-Mail', 'vereins-plugin' ); ?></label>
                    <input type="email" id="vp-reg-email" name="email" required>
                </div>

                <div class="vp-form__group">
                    <label for="vp-reg-phone"><?php esc_html_e( 'Telefon (optional)', 'vereins-plugin' ); ?></label>
                    <input type="tel" id="vp-reg-phone" name="phone">
                </div>

                <button type="submit" class="vp-btn"><?php esc_html_e( 'Anmelden', 'vereins-plugin' ); ?></button>
            </form>
        </div>

        <style>
        .vp-form-wrap{max-width:480px;font-family:inherit}
        .vp-form__group{display:flex;flex-direction:column;gap:4px;margin-bottom:14px}
        .vp-form__group label{font-size:.85rem;font-weight:600;color:#374151}
        .vp-form__group input{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;font-family:inherit}
        /* Handy: unter 16 px zoomt Safari beim Antippen heran */
        @media (max-width:782px),(pointer:coarse){.vp-form__group input{font-size:16px !important}}
        .vp-btn{background:#3b82f6;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-size:1rem;cursor:pointer;font-weight:600}
        .vp-btn:hover{background:#2563eb}
        .vp-notice{color:#6b7280;font-style:italic}
        .vp-notice--ok{color:#059669;font-style:normal}
        .vp-notice--err{color:#dc2626;font-style:normal}
        </style>
        <?php
        return ob_get_clean();
    }

    public function handle_submit(): void {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'vp_register' ) ) {
            wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen. Bitte versuche es erneut.', 'vereins-plugin' ) );
        }

        $redirect = ! empty( $_POST['vp_redirect'] ) ? esc_url_raw( $_POST['vp_redirect'] ) : home_url( '/' );

        $result = ( new MemberService() )->register( [
            'display_name' => sanitize_text_field( $_POST['display_name'] ?? '' ),
            'email'        => sanitize_email( $_POST['email'] ?? '' ),
            'phone'        => sanitize_text_field( $_POST['phone'] ?? '' ),
        ] );

        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( 'vp_error', $result->get_error_code(), $redirect ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( 'vp_registered', $result['status'], $redirect ) );
        exit;
    }

    private function error_message( string $code ): string {
        return match ( $code ) {
            'invalid_email' => __( 'Bitte gib eine gültige E-Mail-Adresse an.', 'vereins-plugin' ),
            'email_exists'  => __( 'Für diese E-Mail-Adresse existiert bereits ein Zugang.', 'vereins-plugin' ),
            default         => __( 'Anmeldung fehlgeschlagen. Bitte versuche es erneut.', 'vereins-plugin' ),
        };
    }
}
