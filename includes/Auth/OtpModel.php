<?php

namespace VereinsPlugin\Auth;

/**
 * Datenbankoperationen für OTP-Tokens.
 */
class OtpModel {

    /** So viele falsche Codes je Adresse, dann Sperre. */
    public const MAX_FAILURES = 5;

    /** Dauer der Sperre – und zugleich das Fenster, in dem gezählt wird. */
    public const LOCK_MINUTES = 15;

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'vp_otp_tokens';
    }

    /**
     * Erstellt einen neuen OTP-Eintrag und gibt den Klartext-Code zurück.
     */
    public function create( string $identifier, string $channel, string $ip = '' ): string {
        global $wpdb;

        // Alte, unbenutzte Tokens für diese Adresse löschen
        $this->invalidate_pending( $identifier );

        $code      = (string) random_int( 100000, 999999 );
        $hash      = password_hash( $code, PASSWORD_BCRYPT );
        $expires   = gmdate( 'Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS );

        $wpdb->insert(
            $this->table,
            [
                'identifier' => $identifier,
                'channel'    => $channel,
                'token_hash' => $hash,
                'expires_at' => $expires,
                'ip_address' => $ip,
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        return $code;
    }

    /**
     * Verifiziert einen Code. Bei Erfolg wird der Token als benutzt markiert.
     * Gibt true zurück, wenn gültig.
     */
    public function verify( string $identifier, string $code ): bool {
        global $wpdb;

        // Ein sechsstelliger Code hält nur, solange man ihn nicht
        // durchprobieren kann. Nach MAX_FAILURES falschen Eingaben ist für
        // diese Adresse Schluss, bis die Sperre abläuft – auch ein frisch
        // angeforderter Code hilft dann nicht, sonst finge das Raten mit
        // jedem neuen Code von vorn an.
        $fail_key = self::fail_key( $identifier );
        $failures = (int) get_transient( $fail_key );
        if ( $failures >= self::MAX_FAILURES ) {
            $this->invalidate_pending( $identifier );
            return false;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, token_hash FROM {$this->table}
                 WHERE identifier = %s
                   AND used_at   IS NULL
                   AND expires_at > %s
                 ORDER BY id DESC
                 LIMIT 1",
                $identifier,
                gmdate( 'Y-m-d H:i:s' )
            )
        );

        if ( ! $row || ! password_verify( $code, $row->token_hash ) ) {
            set_transient( $fail_key, $failures + 1, self::LOCK_MINUTES * MINUTE_IN_SECONDS );
            return false;
        }

        delete_transient( $fail_key );

        // Als benutzt markieren
        $wpdb->update(
            $this->table,
            [ 'used_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'id'      => $row->id ],
            [ '%s' ],
            [ '%d' ]
        );

        return true;
    }

    /** Ist die Adresse nach zu vielen falschen Codes gerade gesperrt? */
    public static function is_locked( string $identifier ): bool {
        return (int) get_transient( self::fail_key( $identifier ) ) >= self::MAX_FAILURES;
    }

    private static function fail_key( string $identifier ): string {
        return 'vp_otp_fail_' . md5( strtolower( trim( $identifier ) ) );
    }

    /**
     * Offene (unbenutzte) Tokens für eine Adresse invalidieren.
     */
    public function invalidate_pending( string $identifier ): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET used_at = %s
                 WHERE identifier = %s AND used_at IS NULL",
                gmdate( 'Y-m-d H:i:s' ),
                $identifier
            )
        );
    }

    /**
     * Abgelaufene Tokens aufräumen (kann per Cron aufgerufen werden).
     */
    public function purge_expired(): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table} WHERE expires_at < %s",
                gmdate( 'Y-m-d H:i:s' )
            )
        );
    }

    /**
     * Alle Tokens einer Adresse entfernen – für die vollständige Löschung
     * personenbezogener Daten (WordPress-Werkzeug „Persönliche Daten löschen").
     */
    public function delete_for_identifier( string $identifier ): void {
        global $wpdb;
        $wpdb->delete( $this->table, [ 'identifier' => $identifier ], [ '%s' ] );
    }
}
