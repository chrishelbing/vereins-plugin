<?php

namespace VereinsPlugin\Auth;

/**
 * Datenbankoperationen für App-Sessions (Bearer-Token).
 */
class SessionModel {

    /**
     * Wie lange ein Token gilt – gerechnet ab der letzten Benutzung, nicht
     * ab der Ausstellung.
     *
     * Die Unterscheidung ist der ganze Punkt: Eine feste Frist ab Ausstellung
     * wirft auch den hinaus, der die App täglich öffnet, und zwar ohne
     * erkennbaren Anlass – aus Sicht des Mitglieds meldet sich die App eines
     * Morgens grundlos ab. Gerechnet ab der letzten Benutzung trifft die
     * Frist nur, wer die App einen Monat lang nicht angefasst hat. Dort ist
     * eine neue Anmeldung zumutbar und aus Sicherheitssicht auch erwünscht.
     */
    private const LIFETIME_DAYS = 30;

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'vp_sessions';
    }

    /** Ablaufzeitpunkt für eine gerade benutzte Session. */
    private function expiry(): string {
        return gmdate( 'Y-m-d H:i:s', time() + self::LIFETIME_DAYS * DAY_IN_SECONDS );
    }

    /**
     * Erzeugt eine neue Session und gibt den Klartext-Token zurück.
     * Token-Format: 40 zufällige Bytes als Hex-String.
     */
    public function create( int $wp_user_id, string $device = '' ): string {
        global $wpdb;

        $token     = bin2hex( random_bytes( 40 ) );
        $hash      = hash( 'sha256', $token );
        $expires   = $this->expiry();

        $wpdb->insert(
            $this->table,
            [
                'wp_user_id'  => $wp_user_id,
                'token_hash'  => $hash,
                'device_name' => $device,
                'expires_at'  => $expires,
            ],
            [ '%d', '%s', '%s', '%s' ]
        );

        return $token;
    }

    /**
     * Gibt die WP-User-ID für einen gültigen Bearer-Token zurück, oder null.
     *
     * Nebenwirkung mit Absicht: Jede erfolgreiche Auflösung schiebt das
     * Ablaufdatum wieder nach vorn (siehe LIFETIME_DAYS). Ein widerrufenes
     * oder bereits abgelaufenes Token kommt hier nicht durch und wird
     * deshalb auch nicht wiederbelebt.
     */
    public function resolve( string $token ): ?int {
        global $wpdb;

        $hash = hash( 'sha256', $token );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, wp_user_id FROM {$this->table}
                 WHERE token_hash = %s
                   AND revoked    = 0
                   AND expires_at > %s
                 LIMIT 1",
                $hash,
                gmdate( 'Y-m-d H:i:s' )
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        // Benutzung vermerken und die Frist verlängern.
        $wpdb->update(
            $this->table,
            [
                'last_active_at' => gmdate( 'Y-m-d H:i:s' ),
                'expires_at'     => $this->expiry(),
            ],
            [ 'id' => (int) $row['id'] ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return (int) $row['wp_user_id'];
    }

    /**
     * Widerruft alle Sessions eines Users (Logout-all) oder einen einzelnen Token.
     */
    public function revoke( int $wp_user_id, ?string $token = null ): void {
        global $wpdb;

        if ( $token ) {
            $hash = hash( 'sha256', $token );
            $wpdb->update(
                $this->table,
                [ 'revoked' => 1 ],
                [ 'token_hash' => $hash ],
                [ '%d' ],
                [ '%s' ]
            );
        } else {
            $wpdb->update(
                $this->table,
                [ 'revoked' => 1 ],
                [ 'wp_user_id' => $wp_user_id ],
                [ '%d' ],
                [ '%d' ]
            );
        }
    }
}
