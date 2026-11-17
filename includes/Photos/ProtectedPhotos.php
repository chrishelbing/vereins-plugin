<?php

namespace VereinsPlugin\Photos;

use VereinsPlugin\Support\Capabilities;

/**
 * Termin-Fotos nur für freigeschaltete Mitglieder.
 *
 * Bis 1.15.6 lagen die Fotos als gewöhnliche Medien in wp-content/uploads:
 * Die Terminliste zeigte sie zwar nur Mitgliedern, die Dateien selbst waren
 * aber für jeden mit der Adresse abrufbar, und die WordPress-Schnittstelle
 * (/wp/v2/media) listete sie samt „Quelle: <Name>“ öffentlich auf.
 *
 * Seit 1.15.7:
 *
 *  1. Neue Fotos landen in uploads/vereins-fotos/JJJJ/MM/. Der Ordner trägt
 *     eine .htaccess, die jeden direkten Abruf abweist (Apache; bei nginx
 *     müsste der Server selbst den Ordner sperren).
 *  2. Ausgeliefert wird über admin-post.php?action=vp_photo – mit
 *     Anmeldeprüfung. Die Terminliste bekommt nur noch diese Adressen.
 *  3. Die Mediathek-Schnittstelle und Anhangseiten zeigen die Fotos nur
 *     Konten, die Medien hochladen dürfen (Redakteure, Administratoren).
 *
 * Das Titelbild eines Termins (Banner) bleibt bewusst öffentlich und ist
 * hiervon nicht betroffen.
 */
class ProtectedPhotos {

    /** Unterordner in uploads/, der von außen gesperrt ist. */
    public const DIR = 'vereins-fotos';

    /** Kennzeichen am Anhang, gesetzt von PhotoService::store(). */
    public const META = '_vp_event_photo';

    public function register(): void {
        add_action( 'admin_post_vp_photo',        [ $this, 'serve' ] );
        add_action( 'admin_post_nopriv_vp_photo', [ $this, 'deny' ] );

        add_filter( 'rest_attachment_query',          [ $this, 'hide_in_media_query' ], 10, 1 );
        add_filter( 'ajax_query_attachments_args',    [ $this, 'hide_in_media_query' ], 10, 1 );
        add_filter( 'rest_request_before_callbacks',  [ $this, 'hide_single_in_rest' ], 10, 3 );
        add_action( 'template_redirect',              [ $this, 'hide_attachment_page' ] );
    }

    // ------------------------------------------------------------------
    // Ablage
    // ------------------------------------------------------------------

    /**
     * Filter für upload_dir – nur während eines Foto-Uploads angehängt.
     * Behält die Jahr/Monat-Gliederung bei, nur eine Ebene tiefer.
     */
    public static function upload_dir( array $dirs ): array {
        $sub            = '/' . self::DIR . ( $dirs['subdir'] ?? '' );
        $dirs['path']   = $dirs['basedir'] . $sub;
        $dirs['url']    = $dirs['baseurl'] . $sub;
        $dirs['subdir'] = $sub;
        return $dirs;
    }

    /**
     * Sperre für den Ordner anlegen, falls sie fehlt. Läuft vor jedem Upload
     * und beim Update – billig, weil nur bei Bedarf geschrieben wird.
     */
    public static function ensure_guard(): bool {
        $uploads = wp_upload_dir( null, false );
        if ( ! empty( $uploads['error'] ) ) {
            return false;
        }

        $dir = trailingslashit( $uploads['basedir'] ) . self::DIR;
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return false;
        }

        $htaccess = $dir . '/.htaccess';
        if ( ! is_file( $htaccess ) ) {
            $regeln = "# Vereins-Plugin: Termin-Fotos nur über die Website ausliefern.\n"
                    . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                    . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
            if ( false === @file_put_contents( $htaccess, $regeln ) ) {
                return false;
            }
        }

        if ( ! is_file( $dir . '/index.php' ) ) {
            @file_put_contents( $dir . '/index.php', "<?php\n// Stille.\n" );
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Auslieferung
    // ------------------------------------------------------------------

    /** Adresse, unter der ein Foto für Mitglieder abrufbar ist. */
    public static function url( int $photo_id, string $size = 'full' ): string {
        return add_query_arg( [
            'action' => 'vp_photo',
            'id'     => $photo_id,
            'size'   => $size === 'thumb' ? 'thumb' : 'full',
        ], admin_url( 'admin-post.php' ) );
    }

    public function deny(): void {
        status_header( 403 );
        nocache_headers();
        exit;
    }

    public function serve(): void {
        $photo_id = (int) ( $_GET['id'] ?? 0 );
        $size     = ( $_GET['size'] ?? '' ) === 'thumb' ? 'thumb' : 'full';

        if ( ! Capabilities::is_active_member() ) {
            $this->deny();
        }

        $photo = $photo_id ? ( new PhotoModel() )->find( $photo_id ) : null;
        $path  = $photo ? self::file_path( (int) $photo['attachment_id'], $size ) : '';

        if ( $path === '' || ! is_file( $path ) || ! is_readable( $path ) ) {
            status_header( 404 );
            nocache_headers();
            exit;
        }

        $typ = wp_check_filetype( $path );

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        status_header( 200 );
        header( 'Content-Type: ' . ( $typ['type'] ?: 'application/octet-stream' ) );
        header( 'Content-Length: ' . filesize( $path ) );
        // Privat: nur der eigene Browser darf zwischenspeichern, kein Proxy.
        header( 'Cache-Control: private, max-age=86400' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: inline; filename="' . basename( $path ) . '"' );

        readfile( $path );
        exit;
    }

    /**
     * Pfad zur Datei der gewünschten Größe. Für das Vorschaubild die
     * Zwischengröße „medium“, sonst das (bereits verkleinerte) Original.
     */
    public static function file_path( int $attachment_id, string $size ): string {
        $original = (string) get_attached_file( $attachment_id );
        if ( $original === '' ) {
            return '';
        }

        if ( $size === 'thumb' ) {
            $meta = wp_get_attachment_metadata( $attachment_id );
            $name = $meta['sizes']['medium']['file'] ?? '';
            if ( $name !== '' && is_file( dirname( $original ) . '/' . $name ) ) {
                return dirname( $original ) . '/' . $name;
            }
        }

        return $original;
    }

    // ------------------------------------------------------------------
    // Aus Mediathek und Schnittstelle heraushalten
    // ------------------------------------------------------------------

    /** Wer darf die Fotos in der Mediathek sehen (um sie weiterzuverwenden)? */
    private static function may_browse(): bool {
        return current_user_can( 'upload_files' );
    }

    /** Für rest_attachment_query und die Mediathek im Backend. */
    public function hide_in_media_query( array $args ): array {
        if ( self::may_browse() ) {
            return $args;
        }

        $args['meta_query']   = (array) ( $args['meta_query'] ?? [] );
        $args['meta_query'][] = [ 'key' => self::META, 'compare' => 'NOT EXISTS' ];
        return $args;
    }

    /** Einzelabruf /wp/v2/media/<id> für ein Foto: wie nicht vorhanden. */
    public function hide_single_in_rest( $antwort, $handler, $anfrage ) {
        if ( self::may_browse() || ! is_object( $anfrage ) ) {
            return $antwort;
        }

        if ( preg_match( '#^/wp/v2/media/(\d+)#', (string) $anfrage->get_route(), $m )
            && get_post_meta( (int) $m[1], self::META, true ) ) {
            return new \WP_Error( 'rest_post_invalid_id', __( 'Ungültige ID.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        return $antwort;
    }

    /** Anhangseite eines Fotos (?attachment_id=…) für Gäste: 404. */
    public function hide_attachment_page(): void {
        if ( ! is_attachment() || Capabilities::is_active_member() ) {
            return;
        }

        if ( get_post_meta( (int) get_queried_object_id(), self::META, true ) ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            nocache_headers();
        }
    }
}
