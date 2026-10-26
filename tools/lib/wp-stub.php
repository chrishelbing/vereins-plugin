<?php
/**
 * Kleiner WordPress-Ersatz für die Tests unter tools/.
 *
 * Bildet nur nach, was das Plugin tatsächlich benutzt: Nutzer mit Rollen,
 * eine Handvoll Funktionen und eine Datenbank im Speicher, die die im
 * Plugin vorkommenden Abfragemuster versteht.
 *
 * Damit laufen die Tests ohne WordPress, ohne Datenbank und ohne
 * Installation – überall, wo PHP läuft.
 *
 * ACHTUNG: Der Ersatz ist absichtlich naiv. Wenn ein Test ein unerwartetes
 * Ergebnis liefert, ist immer zuerst zu prüfen, ob die Bedingung hier
 * überhaupt ausgewertet wird. Genau daran ist schon einmal ein Fehlalarm
 * entstanden: 'WHERE m.status = ...' wurde wegen des Tabellenkürzels still
 * ignoriert, und ein aktives Mitglied erschien fälschlich als migriert.
 */

declare( strict_types=1 );

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'COOKIEPATH', '/' );
define( 'COOKIE_DOMAIN', 'verein.test' );

// ---------------------------------------------------------------------
// Datenbank
// ---------------------------------------------------------------------

class StubDb {

    public string $prefix   = 'wp_';
    public string $users    = 'wp_users';
    public string $posts    = 'wp_posts';
    public string $comments = 'wp_comments';
    public int $insert_id   = 0;

    /** @var array<string, array<int, array>> Tabelle => Zeilen */
    public array $t = [];

    /**
     * Spaltenvorgaben je Tabelle, die der Ersatz nicht kennt.
     *
     * Eine echte Datenbank füllt weggelassene Spalten mit dem DEFAULT aus
     * dem Schema; hier fehlen sie dann schlicht. Eine Bedingung wie
     * "revoked = 0" trifft eine solche Zeile nie – und ein Test, der die
     * Zeile eigentlich finden müsste, meldet einen Fehler, den es im
     * Betrieb nicht gibt. Wer eine Tabelle prüft, deren DEFAULTs zählen,
     * trägt sie hier ein.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $defaults = [];

    private array $auto = [];

    public function insert( $table, $data, $fmt = null ) {
        $this->t[ $table ]  ??= [];
        $this->auto[ $table ] = ( $this->auto[ $table ] ?? 0 ) + 1;

        $data                 = array_merge( $this->defaults[ $table ] ?? [], $data );
        $data['id']           = $this->auto[ $table ];
        $data['created_at'] ??= '2026-08-02 10:00:00';

        $this->t[ $table ][] = $data;
        $this->insert_id     = $data['id'];

        return 1;
    }

    public function update( $table, $data, $where, $f = null, $wf = null ) {
        $n = 0;
        foreach ( $this->t[ $table ] ?? [] as $i => $row ) {
            foreach ( $where as $k => $v ) {
                if ( ( $row[ $k ] ?? null ) != $v ) {
                    continue 2;
                }
            }
            $this->t[ $table ][ $i ] = array_merge( $row, $data );
            $n++;
        }
        return $n;
    }

    public function delete( $table, $where, $f = null ) {
        $n = 0;
        foreach ( $this->t[ $table ] ?? [] as $i => $row ) {
            foreach ( $where as $k => $v ) {
                if ( ( $row[ $k ] ?? null ) != $v ) {
                    continue 2;
                }
            }
            unset( $this->t[ $table ][ $i ] );
            $n++;
        }
        $this->t[ $table ] = array_values( $this->t[ $table ] ?? [] );
        return $n;
    }

    public function prepare( $sql, ...$args ) {
        if ( isset( $args[0] ) && is_array( $args[0] ) ) {
            $args = $args[0];
        }
        foreach ( $args as $a ) {
            $ersatz = is_int( $a ) ? (string) $a : "'" . addslashes( (string) $a ) . "'";
            $sql    = preg_replace( '/%[ds]/', $ersatz, (string) $sql, 1 );
        }
        return $sql;
    }

    public function get_results( $sql, $out = null ) { return $this->filter( $sql ); }
    public function get_row( $sql, $out = null )     { return $this->filter( $sql )[0] ?? null; }

    public function get_col( $sql ) {
        preg_match( '/SELECT\s+(?:\w+\.)?(\w+)/i', (string) $sql, $m );
        return array_map( fn( $r ) => $r[ $m[1] ] ?? null, $this->filter( $sql ) );
    }

    public function get_var( $sql ) {
        if ( stripos( (string) $sql, 'COUNT(' ) !== false ) {
            return count( $this->filter( $sql ) );
        }
        $rows = $this->filter( $sql );
        preg_match( '/SELECT\s+(?:\w+\.)?(\w+)/i', (string) $sql, $m );
        return $rows ? ( $rows[0][ $m[1] ] ?? null ) : null;
    }

    public function query( $sql ) { return 1; }
    public function get_charset_collate() { return ''; }

    // -----------------------------------------------------------------

    private function tabelle( string $sql ): string {
        return preg_match( '/FROM\s+(\S+)/i', $sql, $m ) ? $m[1] : '';
    }

    private function filter( $sql ): array {
        $sql  = (string) $sql;
        $rows = $this->t[ $this->tabelle( $sql ) ] ?? [];

        if ( ! preg_match( '/WHERE\s+(.+?)(?:\s+ORDER|\s+LIMIT|$)/is', $sql, $m ) ) {
            return $rows;
        }

        $bedingung = $m[1];

        return array_values( array_filter( $rows, static function ( $r ) use ( $bedingung ) {
            foreach ( explode( ' AND ', $bedingung ) as $teil ) {
                // Tabellenkürzel am Anfang entfernen: 'm.status' wirkt wie
                // 'status'. Bewusst nur am Zeilenanfang – ein Muster wie
                // \b\w+\. schlüge sonst auch im Wert zu und machte aus
                // 'anna@example.test' ein 'anna@test'. Genau daran ist eine
                // frühere Fassung dieses Ersatzes gescheitert.
                $teil = preg_replace( '/^(\w+)\./', '', trim( $teil ) );

                if ( preg_match( '/^(\w+)\s*=\s*\'?([^\']*)\'?$/', $teil, $mm ) ) {
                    if ( (string) ( $r[ $mm[1] ] ?? '' ) !== $mm[2] ) {
                        return false;
                    }
                } elseif ( preg_match( '/^(\w+)\s+IN\s*\(([^)]*)\)/', $teil, $mm ) ) {
                    $werte = array_map( 'trim', explode( ',', $mm[2] ) );
                    if ( ! in_array( (string) ( $r[ $mm[1] ] ?? '' ), $werte, true ) ) {
                        return false;
                    }
                } elseif ( preg_match( '/^(\w+)\s+IS NULL$/', $teil, $mm ) ) {
                    if ( ( $r[ $mm[1] ] ?? null ) !== null ) {
                        return false;
                    }
                } elseif ( preg_match( '/^(\w+)\s*(<=|>=|<>|!=|<|>)\s*\'?([^\']*)\'?$/', $teil, $mm ) ) {
                    // Vergleiche über Gleichheit hinaus. Ohne diesen Zweig
                    // fiel eine Bedingung wie "expires_at > '…'" stillschweigend
                    // durch und jede abgelaufene Zeile galt als gültig – ein
                    // Test darauf hätte bestanden, ohne etwas zu prüfen.
                    $links  = $r[ $mm[1] ] ?? null;
                    $rechts = $mm[3];
                    $cmp    = ( is_numeric( $links ) && is_numeric( $rechts ) )
                        ? ( (float) $links <=> (float) $rechts )
                        : strcmp( (string) $links, (string) $rechts );

                    $erfuellt = match ( $mm[2] ) {
                        '<'          => $cmp < 0,
                        '<='         => $cmp <= 0,
                        '>'          => $cmp > 0,
                        '>='         => $cmp >= 0,
                        '<>', '!='   => $cmp !== 0,
                    };
                    if ( ! $erfuellt ) {
                        return false;
                    }
                }
            }
            return true;
        } ) );
    }
}

$GLOBALS['wpdb'] = new StubDb();

// ---------------------------------------------------------------------
// Nutzer und Rollen
// ---------------------------------------------------------------------

class StubRoles {
    public array $roles = [];
}

class StubUser {
    public int $ID = 0;
    public string $user_email = '';
    public string $user_login = '';
    public string $display_name = '';
    public array $roles = [];

    public function exists(): bool { return true; }

    public function add_role( string $role ): void {
        if ( ! in_array( $role, $this->roles, true ) ) {
            $this->roles[] = $role;
        }
    }
    public function remove_role( string $role ): void {
        $this->roles = array_values( array_diff( $this->roles, [ $role ] ) );
    }
}

class WP_Error {
    private string $code;
    private string $message;
    public function __construct( string $code = '', string $message = '', $data = null ) {
        $this->code    = $code;
        $this->message = $message;
    }
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}

class_alias( 'StubUser', 'WP_User' );

$GLOBALS['stub_roles']   = new StubRoles();
$GLOBALS['stub_users']   = [];
$GLOBALS['stub_current'] = 0;
$GLOBALS['stub_next_id'] = 10;
$GLOBALS['stub_options'] = [];

/**
 * Legt einen Nutzer an – der übliche Einstieg in jeden Testfall.
 */
function stub_user( string $name, array $rollen = [], string $email = '' ): int {
    $u = new StubUser();
    $u->ID           = $GLOBALS['stub_next_id']++;
    $u->user_login   = $name;
    $u->user_email   = $email ?: strtolower( str_replace( ' ', '.', $name ) ) . '@test.de';
    $u->display_name = $name;
    $u->roles        = $rollen;

    $GLOBALS['stub_users'][ $u->ID ] = $u;
    return $u->ID;
}

/** Die Standardrollen einer WordPress-Installation. */
function stub_default_roles(): void {
    $GLOBALS['stub_roles']->roles = [
        'administrator' => [ 'name' => 'Administrator', 'capabilities' => [ 'manage_options' => true, 'edit_posts' => true ] ],
        'editor'        => [ 'name' => 'Redakteur',     'capabilities' => [ 'edit_posts' => true, 'read' => true ] ],
        'author'        => [ 'name' => 'Autor',         'capabilities' => [ 'edit_posts' => true, 'read' => true ] ],
        'subscriber'    => [ 'name' => 'Abonnent',      'capabilities' => [ 'read' => true ] ],
    ];
}

// ---------------------------------------------------------------------
// Funktionen
// ---------------------------------------------------------------------

function is_wp_error( $t ): bool { return $t instanceof WP_Error; }
function wp_roles() { return $GLOBALS['stub_roles']; }

function add_role( $slug, $label, $caps = [] ) {
    // Wie im Original: eine vorhandene Rolle bleibt unangetastet.
    if ( isset( $GLOBALS['stub_roles']->roles[ $slug ] ) ) {
        return null;
    }
    $GLOBALS['stub_roles']->roles[ $slug ] = [ 'name' => $label, 'capabilities' => $caps ];
    return true;
}
function remove_role( $slug ) { unset( $GLOBALS['stub_roles']->roles[ $slug ] ); }
function get_role( $slug ) { return null; }
function get_editable_roles() { return $GLOBALS['stub_roles']->roles; }
function translate_user_role( $n ) { return $n; }

function get_userdata( $id ) { return $GLOBALS['stub_users'][ $id ] ?? false; }

function get_user_by( $feld, $wert ) {
    foreach ( $GLOBALS['stub_users'] as $u ) {
        if ( $feld === 'email' && $u->user_email === $wert ) {
            return $u;
        }
        if ( $feld === 'login' && $u->user_login === $wert ) {
            return $u;
        }
    }
    return false;
}

function get_users( $args = [] ) {
    $out = [];
    if ( isset( $args['role'] ) ) {
        foreach ( $GLOBALS['stub_users'] as $u ) {
            if ( in_array( $args['role'], $u->roles, true ) ) {
                $out[] = ( $args['fields'] ?? '' ) === 'ID' ? $u->ID : $u;
            }
        }
        return $out;
    }
    foreach ( $args['include'] ?? [] as $id ) {
        if ( isset( $GLOBALS['stub_users'][ $id ] ) ) {
            $out[] = $GLOBALS['stub_users'][ $id ];
        }
    }
    return $out;
}

function wp_insert_user( $d ) {
    foreach ( $GLOBALS['stub_users'] as $u ) {
        if ( $u->user_email === $d['user_email'] ) {
            return new WP_Error( 'existing_user_email', 'E-Mail bereits vergeben' );
        }
    }
    $id = stub_user( $d['user_login'], isset( $d['role'] ) ? [ $d['role'] ] : [], $d['user_email'] );
    $GLOBALS['stub_users'][ $id ]->display_name = $d['display_name'] ?? $d['user_login'];
    return $id;
}

function wp_delete_user( $id ) { unset( $GLOBALS['stub_users'][ $id ] ); return true; }

function wp_get_current_user() { return $GLOBALS['stub_users'][ $GLOBALS['stub_current'] ] ?? false; }
function get_current_user_id(): int { return $GLOBALS['stub_current']; }

function user_can( $user, $cap ): bool {
    $user = is_object( $user ) ? $user : ( $GLOBALS['stub_users'][ $user ] ?? null );
    if ( ! $user ) {
        return false;
    }
    foreach ( $user->roles as $rolle ) {
        if ( ! empty( $GLOBALS['stub_roles']->roles[ $rolle ]['capabilities'][ $cap ] ) ) {
            return true;
        }
    }
    return false;
}
function current_user_can( $cap ): bool { return user_can( wp_get_current_user(), $cap ); }

function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return $n === 1 ? $a : $b; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return trim( (string) $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function current_time( $t ) { return '2026-08-02 12:00:00'; }
function wp_generate_password( $l = 12, ...$r ) { return substr( md5( (string) mt_rand() ), 0, $l ); }
function wp_mail( ...$a ) { return true; }
function get_option( $k, $d = false ) { return $GLOBALS['stub_options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['stub_options'][ $k ] = $v; return true; }
function get_bloginfo( $k ) { return 'Testverein'; }
function home_url( $p = '/' ) { return 'https://verein.test' . $p; }
function add_query_arg( ...$a ) { return 'https://verein.test/?x'; }
function wp_salt( $s = '' ) { return 'teststreusalz'; }
function is_ssl() { return true; }
function wp_attachment_is_image( $id ) { return true; }
function wp_get_attachment_image_url( $id, $s ) { return ''; }
function wp_get_attachment_url( $id ) { return $id ? 'https://verein.test/wp-content/uploads/foto-' . $id . '.jpg' : false; }
function wp_delete_attachment( $id, $force = false ) { return true; }
function get_post_meta( ...$a ) { return ''; }
function mysql2date( $f, $d, $t = true ) { $dt = date_create( (string) $d ); return $dt ? $dt->format( $f ) : ''; }

// ---------------------------------------------------------------------
// Filter – nur so viel, wie die Tests brauchen: eine Liste angehängter
// Rückrufe je Haken, ohne Prioritäten und ohne Aktionen.
// ---------------------------------------------------------------------
$GLOBALS['stub_filters'] = [];

function add_filter( $haken, $rueckruf, $prio = 10, $args = 1 ) {
    $GLOBALS['stub_filters'][ $haken ][] = $rueckruf;
    return true;
}
function has_filter( $haken, $rueckruf = false ) {
    return ! empty( $GLOBALS['stub_filters'][ $haken ] );
}
function apply_filters( $haken, $wert, ...$args ) {
    foreach ( $GLOBALS['stub_filters'][ $haken ] ?? [] as $rueckruf ) {
        $wert = $rueckruf( $wert, ...$args );
    }
    return $wert;
}

// ---------------------------------------------------------------------
// Kleine Testhilfe
// ---------------------------------------------------------------------

final class Pruefung {

    private static int $fehler = 0;

    public static function ok( string $was, bool $ergebnis ): void {
        printf( "  %s %s\n", $ergebnis ? ' ' : '!', $was );
        if ( ! $ergebnis ) {
            self::$fehler++;
        }
    }

    public static function gleich( string $was, $ist, $soll ): void {
        $ok = $ist === $soll;
        printf(
            "  %s %-52s %s\n",
            $ok ? ' ' : '!',
            $was,
            $ok ? self::zeige( $ist ) : self::zeige( $ist ) . '  ← erwartet ' . self::zeige( $soll )
        );
        if ( ! $ok ) {
            self::$fehler++;
        }
    }

    private static function zeige( $wert ): string {
        if ( is_bool( $wert ) ) {
            return $wert ? 'ja' : 'nein';
        }
        if ( is_array( $wert ) ) {
            return $wert ? implode( ', ', $wert ) : '(leer)';
        }
        return (string) ( $wert ?? '(null)' );
    }

    public static function fehler(): int { return self::$fehler; }

    public static function abschluss( string $titel ): never {
        echo str_repeat( '-', 74 ), "\n";
        if ( self::$fehler ) {
            printf( "%s: %d Abweichung%s\n", $titel, self::$fehler, self::$fehler === 1 ? '' : 'en' );
            exit( 1 );
        }
        printf( "%s: alles wie erwartet.\n", $titel );
        exit( 0 );
    }
}

/** Lädt Plugin-Klassen in der angegebenen Reihenfolge. */
function stub_load( array $dateien ): void {
    $root = dirname( __DIR__, 2 );
    foreach ( $dateien as $d ) {
        require_once $root . '/includes/' . $d;
    }
}
