<?php

namespace VereinsPlugin\Photos;

use VereinsPlugin\Admin\Settings;
use VereinsPlugin\Events\EventModel;
use WP_Error;

/**
 * Fachlogik für Termin-Fotos: Upload annehmen, verkleinern, Metadaten
 * verwerfen, Kontingent prüfen und als Mediathek-Eintrag ablegen.
 *
 * Das Verkleinern ist der wichtigste Hebel gegen volle Festplatten: aus einem
 * 8-MB-Handyfoto werden typischerweise ein paar hundert Kilobyte. Nebenbei
 * fallen dabei EXIF-Daten (u. a. GPS-Koordinaten) weg, weil das Bild neu
 * kodiert wird.
 */
class PhotoService {

    private const ALLOWED_TYPES = [ 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ];

    private PhotoModel $model;

    public function __construct() {
        $this->model = new PhotoModel();
    }

    /**
     * Eine hochgeladene Datei verarbeiten.
     *
     * Die Zustimmung zur Nutzung auf Website und in sozialen Medien ist
     * Pflicht (Checkbox im Frontend) und wird mit Zeitstempel je Foto
     * festgehalten – das Frontend blockiert den Upload zwar schon ohne
     * Häkchen, aber ein serverseitiger Nachweis darf sich nicht allein auf
     * das Frontend verlassen.
     *
     * @param  array $file  Ein Eintrag aus $_FILES
     * @return array{photo_id:int, file_size:int}|WP_Error
     */
    public function store( array $file, int $event_id, string $instance_date, int $wp_user_id, bool $consent = false ): array|WP_Error {
        if ( ! $consent ) {
            return new WP_Error(
                'consent_required',
                __( 'Ohne Zustimmung zur Nutzung auf Website und in sozialen Medien kann das Foto nicht hochgeladen werden.', 'vereins-plugin' ),
                [ 'status' => 422 ]
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $window = $this->upload_window( $event_id, $instance_date );
        if ( ! $window['open'] ) {
            return new WP_Error(
                'upload_closed',
                sprintf(
                    __( 'Der Upload-Zeitraum für diesen Termin ist am %s abgelaufen.', 'vereins-plugin' ),
                    mysql2date( 'd.m.Y', $window['deadline'] )
                ),
                [ 'status' => 403 ]
            );
        }

        $check = $this->validate( $file );
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        // Kontingent vorab grob prüfen (endgültig nach dem Verkleinern).
        $quota = $this->quota_bytes();
        $used  = $this->model->used_bytes( $event_id, $instance_date, $wp_user_id );
        if ( $quota > 0 && $used >= $quota ) {
            return new WP_Error(
                'quota_exceeded',
                __( 'Dein Speicherplatz für diesen Termin ist aufgebraucht. Lösche ältere Fotos, um Platz zu schaffen.', 'vereins-plugin' ),
                [ 'status' => 413 ]
            );
        }

        $upload = wp_handle_upload( $file, [
            'test_form' => false,
            'mimes'     => [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png'          => 'image/png',
                'webp'         => 'image/webp',
                'heic'         => 'image/heic',
                'heif'         => 'image/heif',
            ],
        ] );

        if ( isset( $upload['error'] ) ) {
            return new WP_Error( 'upload_failed', $upload['error'], [ 'status' => 400 ] );
        }

        $this->shrink( $upload['file'] );

        $size = (int) @filesize( $upload['file'] );

        // Nach dem Verkleinern erneut prüfen – sonst ließe sich das
        // Kontingent mit einer einzigen riesigen Datei überschreiten.
        if ( $quota > 0 && $used + $size > $quota ) {
            @unlink( $upload['file'] );
            return new WP_Error(
                'quota_exceeded',
                __( 'Das Foto passt nicht mehr in dein Kontingent für diesen Termin.', 'vereins-plugin' ),
                [ 'status' => 413 ]
            );
        }

        // Name der hochladenden Person als Beschreibung ans Attachment, damit
        // Redakteure bei einer Weiterverwendung die Quelle angeben können –
        // bewusst nicht im Dateinamen, sonst stünde der Klarname in der
        // öffentlichen Bild-URL, sobald das Foto auf der Website eingebunden wird.
        $uploader = get_userdata( $wp_user_id );
        $source   = $uploader
            ? sprintf( __( 'Quelle: %s', 'vereins-plugin' ), $uploader->display_name )
            : '';

        $attachment_id = wp_insert_attachment( [
            'post_mime_type' => $upload['type'],
            'post_title'     => sanitize_file_name( pathinfo( $upload['file'], PATHINFO_FILENAME ) ),
            'post_content'   => $source,
            'post_status'    => 'inherit',
            'post_author'    => $wp_user_id,
        ], $upload['file'] );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            @unlink( $upload['file'] );
            return new WP_Error( 'attachment_failed', __( 'Foto konnte nicht gespeichert werden.', 'vereins-plugin' ), [ 'status' => 500 ] );
        }

        wp_update_attachment_metadata(
            $attachment_id,
            wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
        );

        // Als Plugin-Foto markieren, damit es sich von anderen Medien
        // unterscheiden lässt.
        update_post_meta( $attachment_id, '_vp_event_photo', $event_id );

        $photo_id = $this->model->create( [
            'event_id'      => $event_id,
            'instance_date' => $instance_date,
            'wp_user_id'    => $wp_user_id,
            'attachment_id' => $attachment_id,
            'file_size'     => $size,
            'purge_after'   => $this->purge_date( $event_id, $instance_date ),
            'consent_at'    => current_time( 'mysql' ),
        ] );

        return [ 'photo_id' => $photo_id, 'file_size' => $size ];
    }

    // ------------------------------------------------------------------
    // Titelbild des Termins
    // ------------------------------------------------------------------

    /**
     * Titelbild setzen. Ein vorhandenes wird ersetzt und aus der Mediathek
     * entfernt, damit keine Karteileichen zurückbleiben.
     *
     * @return array{image: array{full:string, tile:string}}|WP_Error
     */
    public function store_cover( array $file, int $event_id, int $wp_user_id ): array|WP_Error {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $check = $this->validate( $file );
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $upload = wp_handle_upload( $file, [ 'test_form' => false ] );
        if ( isset( $upload['error'] ) ) {
            return new WP_Error( 'upload_failed', $upload['error'], [ 'status' => 400 ] );
        }

        $this->shrink( $upload['file'] );

        $attachment_id = wp_insert_attachment( [
            'post_mime_type' => $upload['type'],
            'post_title'     => sanitize_file_name( pathinfo( $upload['file'], PATHINFO_FILENAME ) ),
            'post_status'    => 'inherit',
            'post_author'    => $wp_user_id,
        ], $upload['file'] );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            @unlink( $upload['file'] );
            return new WP_Error( 'attachment_failed', __( 'Bild konnte nicht gespeichert werden.', 'vereins-plugin' ), [ 'status' => 500 ] );
        }

        wp_update_attachment_metadata(
            $attachment_id,
            wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
        );
        update_post_meta( $attachment_id, '_vp_event_cover', $event_id );

        $this->remove_cover( $event_id );

        ( new EventModel() )->update( $event_id, [ 'image_attachment_id' => $attachment_id ] );

        return [ 'image' => self::cover_urls( $attachment_id ) ];
    }

    /**
     * Titelbild entfernen (Datei inklusive).
     */
    public function remove_cover( int $event_id ): void {
        $model = new EventModel();
        $event = $model->find( $event_id );

        if ( $event && ! empty( $event['image_attachment_id'] ) ) {
            wp_delete_attachment( (int) $event['image_attachment_id'], true );
        }

        $model->update( $event_id, [ 'image_attachment_id' => null ] );
    }

    /**
     * URLs eines Titelbilds – oder null, wenn das Attachment fehlt.
     *
     * @return array{full:string, tile:string}|null
     */
    public static function cover_urls( ?int $attachment_id ): ?array {
        if ( ! $attachment_id ) {
            return null;
        }

        $full = wp_get_attachment_url( $attachment_id );
        if ( ! $full ) {
            return null;
        }

        return [
            'full' => $full,
            'tile' => wp_get_attachment_image_url( $attachment_id, 'large' ) ?: $full,
        ];
    }

    // ------------------------------------------------------------------
    // Upload-Zeitraum
    // ------------------------------------------------------------------

    /**
     * Fotos lassen sich nur eine begrenzte Zeit nach dem Termin hochladen.
     * Die Frist steht am Termin (photo_upload_days); ohne eigenen Wert gilt
     * der globale Standard. 0 bedeutet: kein Zeitlimit.
     *
     * @return array{open:bool, deadline:?string, days_left:?int, unlimited:bool}
     */
    public function upload_window( int $event_id, string $instance_date, ?array $event = null ): array {
        $event = $event ?: ( new EventModel() )->find( $event_id );

        $days = $event && $event['photo_upload_days'] !== null
            ? (int) $event['photo_upload_days']
            : (int) Settings::get( 'photo_upload_days', 14 );

        if ( $days <= 0 ) {
            return [ 'open' => true, 'deadline' => null, 'days_left' => null, 'unlimited' => true ];
        }

        $deadline = $this->deadline( $event, $instance_date, $days );
        $now      = current_time( 'timestamp' );
        $end_ts   = strtotime( $deadline );

        return [
            'open'      => $now <= $end_ts,
            'deadline'  => $deadline,
            'days_left' => (int) max( 0, ceil( ( $end_ts - $now ) / DAY_IN_SECONDS ) ),
            'unlimited' => false,
        ];
    }

    /**
     * Ende des Upload-Zeitraums als MySQL-Datum.
     * Bei Serien zählt der Tag der jeweiligen Instanz.
     */
    private function deadline( ?array $event, string $instance_date, int $days ): string {
        $base = $instance_date;

        if ( $event && empty( $event['is_recurring'] ) ) {
            $base = substr( $event['end_at'], 0, 10 );
        }

        return gmdate( 'Y-m-d H:i:s', strtotime( $base . " 23:59:59 +{$days} days" ) );
    }

    // ------------------------------------------------------------------
    // Kontingent
    // ------------------------------------------------------------------

    /**
     * Kontingent je Mitglied und Termin in Bytes (0 = unbegrenzt).
     */
    public function quota_bytes(): int {
        return max( 0, (int) Settings::get( 'photo_quota_mb', 50 ) ) * 1024 * 1024;
    }

    /**
     * Kontingentstand für die Anzeige im Frontend.
     *
     * @return array{used:int, quota:int, percent:int}
     */
    public function quota_status( int $event_id, string $instance_date, int $wp_user_id ): array {
        $quota = $this->quota_bytes();
        $used  = $this->model->used_bytes( $event_id, $instance_date, $wp_user_id );

        return [
            'used'    => $used,
            'quota'   => $quota,
            'percent' => $quota > 0 ? (int) min( 100, round( $used / $quota * 100 ) ) : 0,
        ];
    }

    // ------------------------------------------------------------------
    // Interne Helfer
    // ------------------------------------------------------------------

    private function validate( array $file ): true|WP_Error {
        if ( ! empty( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'upload_error', __( 'Die Datei konnte nicht übertragen werden.', 'vereins-plugin' ), [ 'status' => 400 ] );
        }

        $max = max( 1, (int) Settings::get( 'photo_max_upload_mb', 15 ) ) * 1024 * 1024;
        if ( (int) ( $file['size'] ?? 0 ) > $max ) {
            return new WP_Error(
                'file_too_large',
                sprintf(
                    __( 'Die Datei ist größer als %d MB und wurde nicht angenommen.', 'vereins-plugin' ),
                    (int) Settings::get( 'photo_max_upload_mb', 15 )
                ),
                [ 'status' => 413 ]
            );
        }

        $type = wp_check_filetype_and_ext( $file['tmp_name'] ?? '', $file['name'] ?? '' );
        $mime = $type['type'] ?: ( $file['type'] ?? '' );

        if ( ! in_array( $mime, self::ALLOWED_TYPES, true ) ) {
            return new WP_Error( 'invalid_type', __( 'Nur Bilddateien sind erlaubt.', 'vereins-plugin' ), [ 'status' => 415 ] );
        }

        return true;
    }

    /**
     * Bild auf die konfigurierte Kantenlänge herunterrechnen und neu
     * komprimieren. Schlägt das fehl, bleibt das Original bestehen – lieber
     * ein großes Foto als gar keines.
     */
    private function shrink( string $path ): void {
        $max_edge = max( 400, (int) Settings::get( 'photo_max_edge', 2000 ) );

        $editor = wp_get_image_editor( $path );
        if ( is_wp_error( $editor ) ) {
            return;
        }

        $size = $editor->get_size();
        if ( ! empty( $size['width'] ) && ! empty( $size['height'] )
             && ( $size['width'] > $max_edge || $size['height'] > $max_edge ) ) {
            $editor->resize( $max_edge, $max_edge, false );
        }

        $editor->set_quality( 82 );
        $editor->save( $path );
    }

    /**
     * Löschzeitpunkt: Terminende plus Aufbewahrungsfrist.
     * Frist 0 bedeutet "nicht automatisch löschen".
     */
    private function purge_date( int $event_id, string $instance_date ): ?string {
        $days = (int) Settings::get( 'photo_retention_days', 90 );
        if ( $days <= 0 ) {
            return null;
        }

        // Bei Serien zählt der Tag der jeweiligen Instanz, nicht der Serienstart.
        $event = ( new EventModel() )->find( $event_id );
        $base  = $instance_date;

        if ( $event && ! $event['is_recurring'] ) {
            $base = substr( $event['end_at'], 0, 10 );
        }

        return gmdate( 'Y-m-d H:i:s', strtotime( $base . " 23:59:59 +{$days} days" ) );
    }
}
