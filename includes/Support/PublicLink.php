<?php

namespace VereinsPlugin\Support;

/**
 * Signierte Links und Gast-Wiedererkennung.
 *
 * Bewusst ohne eigene Tabelle: Alle Werte tragen ihre Gültigkeit als HMAC
 * mit sich. Das spart Speicher, ist zustandslos und lässt sich durch Ändern
 * der WordPress-Salts global entwerten.
 *
 * Drei Arten von Signaturen:
 *   instance  – öffentlicher Link auf eine Termininstanz (unbegrenzt gültig,
 *               damit geteilte Links nicht mitten in der Woche sterben)
 *   guest     – Wiedererkennung im Cookie (ein Jahr)
 *   confirm   – Bestätigungslink aus der E-Mail (14 Tage)
 */
class PublicLink {

    private const COOKIE = 'vp_guest';

    private const EVENTS_PAGE_CACHE = 'vp_events_page';

    // ------------------------------------------------------------------
    // Signieren und Prüfen
    // ------------------------------------------------------------------

    /**
     * Länge der Signatur je Verwendungszweck (in Hex-Zeichen).
     *
     * Terminlinks landen auf Flyern und in QR-Codes. Die vollen 64 Zeichen
     * machen den Code unnötig dicht und die URL unabtippbar. 20 Hex-Zeichen
     * sind 80 Bit – zum Fälschen müsste man im Mittel 2^79 Signaturen raten,
     * und jeder Versuch kostet einen HTTP-Aufruf. Das genügt für einen
     * Terminlink bei weitem.
     *
     * Cookie und Bestätigungslink stehen nirgends gedruckt und behalten die
     * volle Länge – dort kostet sie nichts.
     */
    private const LENGTHS = [ 'instance' => 20 ];

    private static function sign( string $scope, string $payload, int $valid_until = 0 ): string {
        $full = hash_hmac( 'sha256', $scope . '|' . $payload . '|' . $valid_until, wp_salt( 'auth' ) );

        return isset( self::LENGTHS[ $scope ] )
            ? substr( $full, 0, self::LENGTHS[ $scope ] )
            : $full;
    }

    /**
     * Baut einen signierten Wert der Form "payload.gültigBis.signatur".
     */
    private static function pack( string $scope, string $payload, int $valid_until = 0 ): string {
        return $payload . '.' . $valid_until . '.' . self::sign( $scope, $payload, $valid_until );
    }

    /**
     * Prüft einen signierten Wert und gibt die Nutzlast zurück – oder null.
     */
    private static function unpack( string $scope, string $value ): ?string {
        $parts = explode( '.', $value );
        if ( count( $parts ) < 3 ) {
            return null;
        }

        $signature   = array_pop( $parts );
        $valid_until = (int) array_pop( $parts );
        $payload     = implode( '.', $parts );

        if ( ! hash_equals( self::sign( $scope, $payload, $valid_until ), $signature ) ) {
            return null;
        }

        if ( $valid_until > 0 && time() > $valid_until ) {
            return null;
        }

        return $payload;
    }

    // ------------------------------------------------------------------
    // Öffentlicher Link auf eine Termininstanz
    // ------------------------------------------------------------------

    public static function instance_key( int $event_id, string $instance_date ): string {
        return self::pack( 'instance', $event_id . ':' . $instance_date );
    }

    /**
     * Prüft den Schlüssel aus der URL gegen Termin und Datum.
     */
    public static function verify_instance( string $key, int $event_id, string $instance_date ): bool {
        return self::unpack( 'instance', $key ) === $event_id . ':' . $instance_date;
    }

    /**
     * Öffentliche Adresse eines Termins. Basis ist die Seite, auf der der
     * Shortcode steht – sie wird beim Erzeugen mitgegeben.
     */
    public static function url( string $base_url, int $event_id, string $instance_date ): string {
        return add_query_arg( [
            'vp_event' => $event_id,
            'vp_date'  => $instance_date,
            'vp_key'   => self::instance_key( $event_id, $instance_date ),
        ], $base_url );
    }

    /**
     * Seite, auf der der Shortcode [vp_events] steht – Ziel aller
     * öffentlichen Terminlinks (Flyer, QR-Code, Banner).
     *
     * Leer, wenn es keine solche Seite gibt. Der Fund wird einen Tag lang
     * gemerkt und bei jedem Aufruf gegengeprüft: Der Banner steht auf der
     * Startseite, und dort soll nicht bei jedem Seitenaufruf der gesamte
     * Seiteninhalt durchsucht werden.
     */
    public static function events_page(): string {
        static $url = null;
        if ( $url !== null ) {
            return $url;
        }

        $passt = fn( int $id ): bool => $id > 0
            && get_post_status( $id ) === 'publish'
            && has_shortcode( (string) get_post_field( 'post_content', $id ), 'vp_events' );

        $gemerkt = (int) get_transient( self::EVENTS_PAGE_CACHE );
        if ( $passt( $gemerkt ) ) {
            return $url = (string) get_permalink( $gemerkt );
        }

        // Die Volltextsuche findet auch Seiten, die den Shortcode nur
        // erwähnen – deshalb mehrere Treffer holen und einzeln prüfen.
        $found = get_posts( [
            'post_type'      => [ 'page', 'post' ],
            'post_status'    => 'publish',
            's'              => '[vp_events',
            'posts_per_page' => 5,
            'fields'         => 'ids',
        ] );

        foreach ( $found as $id ) {
            if ( $passt( (int) $id ) ) {
                set_transient( self::EVENTS_PAGE_CACHE, (int) $id, DAY_IN_SECONDS );
                return $url = (string) get_permalink( $id );
            }
        }

        // Kein Treffer wird nicht gemerkt – sonst bliebe eine gerade
        // angelegte Terminseite einen Tag lang unentdeckt.
        return $url = '';
    }

    /**
     * Liest die Terminkennung aus der aktuellen Anfrage – nur wenn die
     * Signatur stimmt.
     *
     * @return array{event_id:int, instance_date:string}|null
     */
    public static function current_instance(): ?array {
        $event_id = isset( $_GET['vp_event'] ) ? (int) $_GET['vp_event'] : 0;
        $date     = isset( $_GET['vp_date'] ) ? substr( sanitize_text_field( $_GET['vp_date'] ), 0, 10 ) : '';
        $key      = isset( $_GET['vp_key'] ) ? sanitize_text_field( $_GET['vp_key'] ) : '';

        if ( ! $event_id || ! $date || ! $key ) {
            return null;
        }

        if ( ! self::verify_instance( $key, $event_id, $date ) ) {
            return null;
        }

        return [ 'event_id' => $event_id, 'instance_date' => $date ];
    }

    // ------------------------------------------------------------------
    // Gast-Wiedererkennung (Cookie)
    // ------------------------------------------------------------------

    public static function remember_guest( int $wp_user_id ): void {
        $value = self::pack( 'guest', (string) $wp_user_id, time() + YEAR_IN_SECONDS );

        setcookie(
            self::COOKIE,
            $value,
            [
                'expires'  => time() + YEAR_IN_SECONDS,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        // Damit die Kennung schon im selben Request greift.
        $_COOKIE[ self::COOKIE ] = $value;
    }

    /**
     * Wiedererkannter Gast der aktuellen Anfrage – oder 0.
     */
    public static function current_guest(): int {
        $raw = $_COOKIE[ self::COOKIE ] ?? '';
        if ( ! $raw ) {
            return 0;
        }

        $user_id = self::unpack( 'guest', sanitize_text_field( $raw ) );
        if ( ! $user_id ) {
            return 0;
        }

        // Zwischenzeitlich gelöschte Nutzer nicht durchlassen.
        return get_userdata( (int) $user_id ) ? (int) $user_id : 0;
    }

    public static function forget_guest(): void {
        setcookie( self::COOKIE, '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN );
        unset( $_COOKIE[ self::COOKIE ] );
    }

    // ------------------------------------------------------------------
    // Bestätigungslink aus der E-Mail
    // ------------------------------------------------------------------

    public static function confirm_url( int $wp_user_id, string $base_url ): string {
        return add_query_arg(
            [ 'vp_confirm' => self::pack( 'confirm', (string) $wp_user_id, time() + 14 * DAY_IN_SECONDS ) ],
            $base_url
        );
    }

    /**
     * Nutzer-ID aus einem Bestätigungslink – oder 0.
     */
    public static function confirming_user(): int {
        $raw = isset( $_GET['vp_confirm'] ) ? sanitize_text_field( $_GET['vp_confirm'] ) : '';
        if ( ! $raw ) {
            return 0;
        }

        $user_id = self::unpack( 'confirm', $raw );
        return $user_id ? (int) $user_id : 0;
    }
}
