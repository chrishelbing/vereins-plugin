<?php

namespace VereinsPlugin\Auth;

/**
 * Datenbankoperationen für App-Sessions (Bearer-Token).
 */
class SessionModel {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'vp_sessions';
    }

    /**
     * Erzeugt eine neue Session und gibt den Klartext-Token zurück.
     * Token-Format: 40 zufällige Bytes als Hex-String.
     */
    public function create( int $wp_user_id, string $device = '' ): string {
        global $wpdb;

        $token     = bin2hex( random_bytes( 40 ) );
        $hash      = hash( 'sha256', $token );
        $expires   = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );

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
            )
        );

        if ( ! $row ) {
            return null;
        }

        // last_active aktualisieren
        $wpdb->update(
            $this->table,
            [ 'last_active_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'id' => $row->id ],
            [ '%s' ],
            [ '%d' ]
        );

        return (int) $row->wp_user_id;
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
