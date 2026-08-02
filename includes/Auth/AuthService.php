<?php

namespace VereinsPlugin\Auth;

/**
 * Fachlogik für Authentifizierung.
 * Koordiniert OtpModel, SessionModel und WordPress User-API.
 */
class AuthService {

    private OtpModel     $otp_model;
    private SessionModel $session_model;

    public function __construct() {
        $this->otp_model     = new OtpModel();
        $this->session_model = new SessionModel();
    }

    // ------------------------------------------------------------------
    // Schritt 1: OTP anfordern
    // ------------------------------------------------------------------

    /**
     * Erzeugt einen OTP-Code und sendet ihn an die angegebene Adresse.
     *
     * @param  string $identifier  E-Mail-Adresse (oder später Telefonnummer)
     * @param  string $channel     'email' | 'sms' | 'whatsapp'
     * @return bool   true wenn versendet
     */
    public function request_otp( string $identifier, string $channel = 'email' ): bool {
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
        $code = $this->otp_model->create( $identifier, $channel, $ip );

        return match ( $channel ) {
            'email'    => $this->send_email_otp( $identifier, $code ),
            'sms'      => $this->send_sms_otp( $identifier, $code ),
            'whatsapp' => $this->send_whatsapp_otp( $identifier, $code ),
            default    => false,
        };
    }

    // ------------------------------------------------------------------
    // Schritt 2: OTP verifizieren → Session-Token zurückgeben
    // ------------------------------------------------------------------

    /**
     * Verifiziert den OTP-Code und legt eine Session an.
     *
     * Registrierung läuft über [vp_register] bzw. einen Admin im wp-admin –
     * OTP-Login provisioniert daher KEINE neuen User mehr, sondern setzt
     * einen bestehenden WordPress-Account voraus.
     *
     * @return array{token: string, user_id: int}|null  null bei ungültigem Code oder unbekannter Adresse
     */
    public function verify_otp( string $identifier, string $code, string $device = '' ): ?array {
        if ( ! $this->otp_model->verify( $identifier, $code ) ) {
            return null;
        }

        $wp_user = get_user_by( 'email', $identifier );
        if ( ! $wp_user ) {
            return null;
        }

        $token = $this->session_model->create( $wp_user->ID, $device );

        return [
            'token'   => $token,
            'user_id' => $wp_user->ID,
        ];
    }

    // ------------------------------------------------------------------
    // Session auflösen (für Middleware)
    // ------------------------------------------------------------------

    public function resolve_session( string $token ): ?int {
        return $this->session_model->resolve( $token );
    }

    public function revoke_session( int $user_id, ?string $token = null ): void {
        $this->session_model->revoke( $user_id, $token );
    }

    // ------------------------------------------------------------------
    // Interne Hilfsmethoden
    // ------------------------------------------------------------------

    private function send_email_otp( string $email, string $code ): bool {
        $subject = sprintf(
            __( 'Dein Einmal-Code: %s', 'vereins-plugin' ),
            $code
        );
        $body = sprintf(
            __( "Hallo,\n\nDein Einmal-Code lautet: %s\n\nEr ist 10 Minuten gültig.\n\nViele Grüße,\nDein Vereins-Plugin", 'vereins-plugin' ),
            $code
        );

        return wp_mail( $email, $subject, $body );
    }

    /**
     * Platzhalter – wird durch Twilio o.ä. ersetzt.
     */
    private function send_sms_otp( string $phone, string $code ): bool {
        // TODO: Twilio / SMSGateway integrieren
        // apply_filters( 'vp_send_sms', $phone, $code );
        return apply_filters( 'vp_send_sms_otp', false, $phone, $code );
    }

    /**
     * Platzhalter – wird durch WhatsApp Business API ersetzt.
     */
    private function send_whatsapp_otp( string $phone, string $code ): bool {
        // TODO: WhatsApp Business API
        return apply_filters( 'vp_send_whatsapp_otp', false, $phone, $code );
    }
}
