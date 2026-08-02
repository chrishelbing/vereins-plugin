<?php

namespace VereinsPlugin\Photos;

use VereinsPlugin\Events\EventModel;
use VereinsPlugin\Support\Capabilities;

/**
 * Sammel-Download: alle Fotos einer Termininstanz als ZIP.
 *
 * Läuft über admin-post.php statt über die REST-API, weil hier eine Datei
 * ausgeliefert und nicht JSON zurückgegeben wird.
 */
class PhotoDownload {

    public function register(): void {
        add_action( 'admin_post_vp_photos_zip', [ $this, 'handle' ] );
    }

    public function handle(): void {
        $event_id = (int) ( $_GET['event_id'] ?? 0 );
        $date     = substr( sanitize_text_field( $_GET['instance_date'] ?? '' ), 0, 10 );

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'vp_photos_zip_' . $event_id . '_' . $date ) ) {
            wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'vereins-plugin' ) );
        }

        if ( ! Capabilities::is_active_member() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $event = ( new EventModel() )->find( $event_id );
        if ( ! $event ) {
            wp_die( esc_html__( 'Termin nicht gefunden.', 'vereins-plugin' ) );
        }

        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_die( esc_html__( 'Auf diesem Server ist kein ZIP-Support verfügbar. Die Fotos lassen sich einzeln herunterladen.', 'vereins-plugin' ) );
        }

        $ids = ( new PhotoModel() )->attachment_ids( $event_id, $date );
        if ( empty( $ids ) ) {
            wp_die( esc_html__( 'Zu diesem Termin gibt es noch keine Fotos.', 'vereins-plugin' ) );
        }

        $zip_path = $this->build_zip( $ids, $event['title'], $date );
        if ( ! $zip_path ) {
            wp_die( esc_html__( 'Das Archiv konnte nicht erstellt werden.', 'vereins-plugin' ) );
        }

        $this->stream( $zip_path, $this->filename( $event['title'], $date ) );
    }

    // ------------------------------------------------------------------
    // Interne Helfer
    // ------------------------------------------------------------------

    /**
     * @param  int[] $attachment_ids
     * @return string|null  Pfad zur temporären ZIP-Datei
     */
    private function build_zip( array $attachment_ids, string $title, string $date ): ?string {
        $tmp = wp_tempnam( 'vp-photos-' . $date . '.zip' );
        if ( ! $tmp ) {
            return null;
        }

        $zip = new \ZipArchive();
        if ( $zip->open( $tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
            return null;
        }

        $used = [];
        foreach ( $attachment_ids as $attachment_id ) {
            $path = get_attached_file( $attachment_id );
            if ( ! $path || ! file_exists( $path ) ) {
                continue;
            }

            // Gleiche Dateinamen aus verschiedenen Uploads auseinanderhalten.
            $name = basename( $path );
            if ( isset( $used[ $name ] ) ) {
                $used[ $name ]++;
                $info = pathinfo( $name );
                $name = $info['filename'] . '-' . $used[ $name ] . '.' . ( $info['extension'] ?? 'jpg' );
            } else {
                $used[ $name ] = 1;
            }

            $zip->addFile( $path, $name );
        }

        $count = $zip->numFiles;
        $zip->close();

        if ( $count === 0 ) {
            @unlink( $tmp );
            return null;
        }

        return $tmp;
    }

    private function stream( string $path, string $filename ): void {
        // Alles verwerfen, was WordPress bis hierhin ausgegeben hat –
        // sonst landet HTML im Archiv.
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $path ) );

        readfile( $path );
        @unlink( $path );
        exit;
    }

    private function filename( string $title, string $date ): string {
        $slug = sanitize_title( $title ) ?: 'termin';
        return $slug . '-' . $date . '.zip';
    }

    /**
     * URL für den Download-Button (inklusive Nonce).
     */
    public static function url( int $event_id, string $instance_date ): string {
        return add_query_arg( [
            'action'        => 'vp_photos_zip',
            'event_id'      => $event_id,
            'instance_date' => $instance_date,
            '_wpnonce'      => wp_create_nonce( 'vp_photos_zip_' . $event_id . '_' . $instance_date ),
        ], admin_url( 'admin-post.php' ) );
    }
}
