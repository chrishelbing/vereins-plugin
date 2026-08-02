<?php

namespace VereinsPlugin\Frontend;

use VereinsPlugin\Members\PeopleModel;

/**
 * Shortcode [vp_login]
 *
 * Zwei gleichwertige Login-Wege auf der Website:
 *  1. Klassisches Passwort (wp_signon, via admin-post.php)
 *  2. E-Mail-OTP (REST-API /auth/request-otp + /auth/verify-otp).
 *     verify-otp setzt serverseitig auch das WP-Auth-Cookie, sodass
 *     die Seite danach wie ein normaler eingeloggter Website-Besuch läuft.
 */
class LoginShortcode {

    public static function describe(): array {
        return [
            'tag'         => 'vp_login',
            'title'       => __( 'Anmeldung', 'vereins-plugin' ),
            'description' => __( 'Login mit zwei gleichwertigen Wegen: klassisches Passwort oder Einmalcode per E-Mail.', 'vereins-plugin' ),
            'atts'        => [
                [ 'redirect_to', '—', __( 'Wird als URL-Parameter übergeben und bestimmt das Ziel nach dem Login.', 'vereins-plugin' ) ],
            ],
            'example'     => '[vp_login]',
        ];
    }

    public function register(): void {
        add_shortcode( 'vp_login', [ $this, 'render' ] );
        add_action( 'admin_post_nopriv_vp_login_password', [ $this, 'handle_password_login' ] );
        add_action( 'admin_post_vp_login_password',         [ $this, 'handle_password_login' ] );
    }

    public function render(): string {
        if ( is_user_logged_in() ) {
            return '<p class="vp-notice">' . esc_html__( 'Du bist bereits angemeldet.', 'vereins-plugin' ) . '</p>';
        }

        $redirect = ! empty( $_GET['redirect_to'] ) ? esc_url_raw( $_GET['redirect_to'] ) : ( get_permalink() ?: home_url( '/' ) );
        $error    = sanitize_text_field( $_GET['vp_login_error'] ?? '' );

        $action = admin_url( 'admin-post.php' );
        $api    = esc_url( rest_url( 'vereins-plugin/v1' ) );

        ob_start();
        ?>
        <div class="vp-login" id="vp-login">
            <?php if ( $error ) : ?>
                <p class="vp-notice vp-notice--err"><?php echo esc_html__( 'Anmeldung fehlgeschlagen: ', 'vereins-plugin' ) . esc_html( $error ); ?></p>
            <?php endif; ?>

            <div class="vp-login__tabs">
                <button type="button" class="vp-login__tab is-active" data-tab="password"><?php esc_html_e( 'Passwort', 'vereins-plugin' ); ?></button>
                <button type="button" class="vp-login__tab" data-tab="otp"><?php esc_html_e( 'Code per E-Mail', 'vereins-plugin' ); ?></button>
            </div>

            <div class="vp-login__panel" data-panel="password">
                <form method="post" action="<?php echo esc_url( $action ); ?>" class="vp-form">
                    <input type="hidden" name="action" value="vp_login_password">
                    <?php wp_nonce_field( 'vp_login_password' ); ?>
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">

                    <div class="vp-form__group">
                        <label for="vp-login-user"><?php esc_html_e( 'E-Mail', 'vereins-plugin' ); ?></label>
                        <input type="text" id="vp-login-user" name="username" required>
                    </div>
                    <div class="vp-form__group">
                        <label for="vp-login-pass"><?php esc_html_e( 'Passwort', 'vereins-plugin' ); ?></label>
                        <input type="password" id="vp-login-pass" name="password" required>
                    </div>
                    <button type="submit" class="vp-btn"><?php esc_html_e( 'Anmelden', 'vereins-plugin' ); ?></button>
                </form>
            </div>

            <div class="vp-login__panel" data-panel="otp" hidden>
                <div id="vp-otp-step-1">
                    <div class="vp-form__group">
                        <label for="vp-otp-email"><?php esc_html_e( 'E-Mail', 'vereins-plugin' ); ?></label>
                        <input type="email" id="vp-otp-email" required>
                    </div>
                    <button type="button" id="vp-otp-request" class="vp-btn"><?php esc_html_e( 'Code senden', 'vereins-plugin' ); ?></button>
                </div>
                <div id="vp-otp-step-2" hidden>
                    <div class="vp-form__group">
                        <label for="vp-otp-code"><?php esc_html_e( '6-stelliger Code', 'vereins-plugin' ); ?></label>
                        <input type="text" id="vp-otp-code" inputmode="numeric" pattern="\d{6}" maxlength="6">
                    </div>
                    <button type="button" id="vp-otp-verify" class="vp-btn"><?php esc_html_e( 'Bestätigen', 'vereins-plugin' ); ?></button>
                </div>
                <div id="vp-otp-msg" class="vp-form__msg" aria-live="polite"></div>
            </div>
        </div>

        <style>
        .vp-login{max-width:420px;font-family:inherit}
        .vp-login__tabs{display:flex;gap:8px;margin-bottom:16px}
        .vp-login__tab{background:#f3f4f6;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:600;color:#374151}
        .vp-login__tab.is-active{background:#3b82f6;color:#fff}
        .vp-form__group{display:flex;flex-direction:column;gap:4px;margin-bottom:14px}
        .vp-form__group label{font-size:.85rem;font-weight:600;color:#374151}
        .vp-form__group input{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;font-family:inherit}
        .vp-btn{background:#3b82f6;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-size:1rem;cursor:pointer;font-weight:600}
        .vp-btn:hover{background:#2563eb}
        .vp-form__msg{min-height:24px;font-size:.9rem;padding:4px 0}
        .vp-form__msg--ok{color:#059669}
        .vp-form__msg--err{color:#dc2626}
        .vp-notice--err{color:#dc2626}
        </style>

        <script>
        (function(){
            var tabs   = document.querySelectorAll('#vp-login .vp-login__tab');
            var panels = document.querySelectorAll('#vp-login .vp-login__panel');
            tabs.forEach(function(tab){
                tab.addEventListener('click', function(){
                    tabs.forEach(function(t){ t.classList.remove('is-active'); });
                    tab.classList.add('is-active');
                    panels.forEach(function(p){ p.hidden = p.dataset.panel !== tab.dataset.tab; });
                });
            });

            var API      = <?php echo json_encode( $api ); ?>;
            var REDIRECT = <?php echo json_encode( $redirect ); ?>;
            var msg      = document.getElementById('vp-otp-msg');
            var step1    = document.getElementById('vp-otp-step-1');
            var step2    = document.getElementById('vp-otp-step-2');
            var emailEl  = document.getElementById('vp-otp-email');
            var codeEl   = document.getElementById('vp-otp-code');

            function setMsg(text, ok) {
                msg.textContent = text;
                msg.className = 'vp-form__msg ' + (ok ? 'vp-form__msg--ok' : 'vp-form__msg--err');
            }

            document.getElementById('vp-otp-request').addEventListener('click', function(){
                var email = emailEl.value.trim();
                if (!email) { return; }
                setMsg('<?php echo esc_js( __( 'Sende Code…', 'vereins-plugin' ) ); ?>', true);
                fetch(API + '/auth/request-otp', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ identifier: email, channel: 'email' })
                }).then(function(r){ return r.json().then(function(d){ return {ok:r.ok, data:d}; }); })
                  .then(function(res){
                      if (res.ok) {
                          setMsg('<?php echo esc_js( __( 'Code gesendet. Bitte E-Mail prüfen.', 'vereins-plugin' ) ); ?>', true);
                          step1.hidden = true;
                          step2.hidden = false;
                      } else {
                          setMsg(res.data.message || '<?php echo esc_js( __( 'Fehler beim Senden.', 'vereins-plugin' ) ); ?>', false);
                      }
                  })
                  .catch(function(){ setMsg('<?php echo esc_js( __( 'Netzwerkfehler.', 'vereins-plugin' ) ); ?>', false); });
            });

            document.getElementById('vp-otp-verify').addEventListener('click', function(){
                var email = emailEl.value.trim();
                var code  = codeEl.value.trim();
                if (!code) { return; }
                setMsg('<?php echo esc_js( __( 'Prüfe Code…', 'vereins-plugin' ) ); ?>', true);
                fetch(API + '/auth/verify-otp', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ identifier: email, code: code, device: navigator.userAgent })
                }).then(function(r){ return r.json().then(function(d){ return {ok:r.ok, data:d}; }); })
                  .then(function(res){
                      if (res.ok) {
                          setMsg('<?php echo esc_js( __( 'Angemeldet! Du wirst weitergeleitet…', 'vereins-plugin' ) ); ?>', true);
                          window.location.href = REDIRECT;
                      } else {
                          setMsg(res.data.message || '<?php echo esc_js( __( 'Ungültiger Code.', 'vereins-plugin' ) ); ?>', false);
                      }
                  })
                  .catch(function(){ setMsg('<?php echo esc_js( __( 'Netzwerkfehler.', 'vereins-plugin' ) ); ?>', false); });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public function handle_password_login(): void {
        check_admin_referer( 'vp_login_password' );

        $redirect = ! empty( $_POST['redirect_to'] ) ? esc_url_raw( $_POST['redirect_to'] ) : home_url( '/' );

        $login = sanitize_text_field( $_POST['username'] ?? '' );

        // Wer sich registriert hat, aber noch nicht aufgenommen wurde, hat
        // noch gar keinen WordPress-Zugang. Ohne diesen Hinweis stünde dort
        // „unbekannter Benutzername“ – und die Person versuchte es erneut.
        if ( is_email( $login ) && ( new PeopleModel() )->find_by_email( $login ) ) {
            wp_safe_redirect( add_query_arg(
                'vp_login_error',
                rawurlencode( __( 'Deine Anmeldung liegt vor, ist aber noch nicht freigeschaltet. Du bekommst eine E-Mail, sobald der Verein sie bestätigt hat.', 'vereins-plugin' ) ),
                $redirect
            ) );
            exit;
        }

        $user = wp_signon( [
            'user_login'    => $login,
            'user_password' => $_POST['password'] ?? '',
            'remember'      => true,
        ], is_ssl() );

        if ( is_wp_error( $user ) ) {
            wp_safe_redirect( add_query_arg( 'vp_login_error', rawurlencode( $user->get_error_message() ), $redirect ) );
            exit;
        }

        wp_safe_redirect( $redirect );
        exit;
    }
}
