<?php

namespace VereinsPlugin\Frontend;

/**
 * Shortcode [vp_login]
 *
 * Ein Weg: Einmalcode per E-Mail (REST-API /auth/request-otp +
 * /auth/verify-otp). verify-otp setzt serverseitig auch das WP-Auth-Cookie,
 * sodass die Seite danach wie ein normaler eingeloggter Website-Besuch läuft.
 *
 * Bis 1.15 gab es hier zusätzlich einen Passwort-Reiter. Der lief ins Leere:
 * Mitglieder, die das Plugin anlegt oder aufnimmt, bekommen ein zufälliges
 * Passwort, das niemand kennt – und zwei Wege verwirrten nur. Wer ein
 * Passwort hat (Administratoren, Redakteure), meldet sich über das normale
 * WordPress-Login an; ein unauffälliger Link darauf steht unter dem Formular.
 */
class LoginShortcode {

    public static function describe(): array {
        return [
            'tag'         => 'vp_login',
            'title'       => __( 'Anmeldung', 'vereins-plugin' ),
            'description' => __( 'Anmeldung mit einem Einmalcode per E-Mail – ohne Passwort. Wer ein Passwort hat (Administratoren, Redakteure), meldet sich über das normale WordPress-Login an; ein Link darauf steht unter dem Formular.', 'vereins-plugin' ),
            'atts'        => [
                [ 'redirect_to', '—', __( 'Wird als URL-Parameter übergeben und bestimmt das Ziel nach dem Login.', 'vereins-plugin' ) ],
            ],
            'example'     => '[vp_login]',
        ];
    }

    public function register(): void {
        add_shortcode( 'vp_login', [ $this, 'render' ] );
    }

    public function render(): string {
        if ( is_user_logged_in() ) {
            return '<p class="vp-notice">' . esc_html__( 'Du bist bereits angemeldet.', 'vereins-plugin' ) . '</p>';
        }

        $redirect = ! empty( $_GET['redirect_to'] ) ? esc_url_raw( $_GET['redirect_to'] ) : ( get_permalink() ?: home_url( '/' ) );
        $api      = esc_url( rest_url( 'vereins-plugin/v1' ) );

        ob_start();
        ?>
        <div class="vp-login" id="vp-login">
            <div class="vp-login__panel">
                <p class="vp-login__intro"><?php esc_html_e( 'Gib deine E-Mail-Adresse ein. Du bekommst einen sechsstelligen Code, mit dem du dich anmeldest – ein Passwort brauchst du nicht.', 'vereins-plugin' ); ?></p>
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

            <p class="vp-login__classic">
                <a href="<?php echo esc_url( wp_login_url( $redirect ) ); ?>"><?php esc_html_e( 'Mit Passwort anmelden (WordPress-Login)', 'vereins-plugin' ); ?></a>
            </p>
        </div>

        <style>
        .vp-login{max-width:420px;font-family:inherit}
        .vp-login__intro{font-size:.9rem;color:#4b5563;margin:0 0 14px}
        .vp-login__classic{margin-top:18px;font-size:.8rem}
        .vp-login__classic a{color:#6b7280}
        .vp-form__group{display:flex;flex-direction:column;gap:4px;margin-bottom:14px}
        .vp-form__group label{font-size:.85rem;font-weight:600;color:#374151}
        .vp-form__group input{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;font-family:inherit}
        /* Handy: unter 16 px zoomt Safari beim Antippen heran */
        @media (max-width:782px),(pointer:coarse){.vp-form__group input{font-size:16px !important}}
        .vp-btn{background:#3b82f6;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-size:1rem;cursor:pointer;font-weight:600}
        .vp-btn:hover{background:#2563eb}
        .vp-form__msg{min-height:24px;font-size:.9rem;padding:4px 0}
        .vp-form__msg--ok{color:#059669}
        .vp-form__msg--err{color:#dc2626}
        .vp-notice--err{color:#dc2626}
        </style>

        <script>
        (function(){
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

            var reqBtn = document.getElementById('vp-otp-request');
            reqBtn.addEventListener('click', function(){
                var email = emailEl.value.trim();
                if (!email || reqBtn.disabled) { return; }
                // Gesperrt, bis die Antwort da ist: Ein zweiter Klick
                // erzeugte einen neuen Code und machte den ersten ungültig.
                reqBtn.disabled = true;
                setMsg('<?php echo esc_js( __( 'Sende Code…', 'vereins-plugin' ) ); ?>', true);
                fetch(API + '/auth/request-otp', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ identifier: email, channel: 'email' })
                }).then(function(r){ return r.json().then(function(d){ return {ok:r.ok, data:d}; }); })
                  .then(function(res){
                      reqBtn.disabled = false;
                      if (res.ok) {
                          setMsg('<?php echo esc_js( __( 'Code gesendet. Bitte E-Mail prüfen – es gilt nur der zuletzt gesendete Code.', 'vereins-plugin' ) ); ?>', true);
                          step1.hidden = true;
                          step2.hidden = false;
                      } else {
                          setMsg(res.data.message || '<?php echo esc_js( __( 'Fehler beim Senden.', 'vereins-plugin' ) ); ?>', false);
                      }
                  })
                  .catch(function(){ reqBtn.disabled = false; setMsg('<?php echo esc_js( __( 'Netzwerkfehler.', 'vereins-plugin' ) ); ?>', false); });
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
}
