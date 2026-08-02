<?php

namespace VereinsPlugin\Photos;

use VereinsPlugin\Admin\Settings;
use VereinsPlugin\Events\AttendanceModel;
use VereinsPlugin\Events\EventModel;
use VereinsPlugin\Events\RecurrenceExpander;

/**
 * Täglicher Aufräumjob für Termin-Fotos.
 *
 * Zwei Schritte:
 *   1. Vorwarnung – wer Fotos hochgeladen hat, die in Kürze ablaufen,
 *      bekommt einmalig eine E-Mail mit Downloadhinweis.
 *   2. Löschen – abgelaufene Fotos werden samt Mediathek-Eintrag entfernt.
 */
class PhotoCleanup {

    private const HOOK = 'vp_photo_cleanup';

    private PhotoModel $model;

    public function __construct() {
        $this->model = new PhotoModel();
    }

    public function register(): void {
        add_action( self::HOOK, [ $this, 'run' ] );

        if ( ! wp_next_scheduled( self::HOOK ) ) {
            // Nachts laufen lassen, wenn wenig los ist.
            wp_schedule_event( strtotime( 'tomorrow 3:30' ), 'daily', self::HOOK );
        }
    }

    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    public function run(): void {
        $this->notify_upload_closed();
        $this->send_warnings();
        $this->purge_expired();
    }

    // ------------------------------------------------------------------
    // Upload-Frist abgelaufen: Teilnehmer informieren
    // ------------------------------------------------------------------

    /**
     * Sucht Termininstanzen, deren Upload-Zeitraum gerade abgelaufen ist, und
     * schickt allen Zusagenden sowie allen Uploadern einmalig die Info, dass
     * die Fotosammlung jetzt vollständig ist.
     */
    private function notify_upload_closed(): void {
        global $wpdb;

        $service    = new PhotoService();
        $events     = new EventModel();
        $attendance = new AttendanceModel();

        // Rückblick über ein paar Wochen genügt – alles Ältere ist längst
        // benachrichtigt oder hat gar keine Fotos.
        $to   = current_time( 'mysql' );
        $from = gmdate( 'Y-m-d H:i:s', strtotime( $to . ' -90 days' ) );

        $raw       = $events->in_range( $from, $to, 'approved' );
        $instances = RecurrenceExpander::expand( $raw, $from, $to );

        foreach ( $instances as $instance ) {
            if ( empty( $instance['allows_photos'] ) ) {
                continue;
            }

            $event_id = (int) $instance['event_id'];
            $date     = $instance['original_date'] ?? substr( $instance['start_at'], 0, 10 );

            $window = $service->upload_window( $event_id, $date );
            if ( $window['open'] || $window['unlimited'] ) {
                continue;
            }

            // Schon informiert? Der UNIQUE-Key verhindert Doppelversand auch
            // bei parallel laufenden Cron-Durchgängen.
            $inserted = $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}vp_photo_notices (event_id, instance_date, notified_at)
                 VALUES (%d, %s, %s)",
                $event_id, $date, current_time( 'mysql' )
            ) );

            if ( ! $inserted ) {
                continue;
            }

            $this->send_closed_mails( $instance, $event_id, $date, $attendance );
        }
    }

    private function send_closed_mails( array $instance, int $event_id, string $date, AttendanceModel $attendance ): void {
        $photos = ( new PhotoModel() )->for_instance( $event_id, $date );

        // Ohne Fotos gibt es nichts anzukündigen.
        if ( empty( $photos ) ) {
            return;
        }

        $recipients = [];

        // Alle, die zugesagt haben …
        $grouped = $attendance->for_instance( $event_id, $date );
        foreach ( $grouped['yes'] as $entry ) {
            $recipients[ $entry['user_id'] ] = true;
        }

        // … plus alle, die Fotos beigesteuert haben.
        foreach ( $photos as $photo ) {
            $recipients[ $photo['user_id'] ] = true;
        }

        if ( empty( $recipients ) ) {
            return;
        }

        $subject = sprintf(
            __( '[%s] Fotos zu „%s“ sind vollständig', 'vereins-plugin' ),
            Settings::get( 'sender_name' ),
            $instance['title']
        );

        $retention = (int) Settings::get( 'photo_retention_days', 90 );
        $hint      = $retention > 0
            ? sprintf(
                __( "\n\nBitte denk daran: %d Tage nach dem Termin werden die Fotos automatisch gelöscht, um Speicherplatz freizugeben.", 'vereins-plugin' ),
                $retention
            )
            : '';

        foreach ( array_keys( $recipients ) as $user_id ) {
            $user = get_userdata( (int) $user_id );
            if ( ! $user ) {
                continue;
            }

            $body = sprintf(
                __(
                    "Hallo %s,\n\nder Upload-Zeitraum für „%s“ (%s) ist abgelaufen – die Fotosammlung ist damit vollständig.\n\nInsgesamt sind %d Fotos zusammengekommen. Auf der Terminseite kannst du sie ansehen oder gesammelt als ZIP herunterladen.%s\n\nViele Grüße",
                    'vereins-plugin'
                ),
                $user->display_name,
                $instance['title'],
                mysql2date( 'd.m.Y', $instance['start_at'] ),
                count( $photos ),
                $hint
            );

            wp_mail( $user->user_email, $subject, $body );
        }
    }

    // ------------------------------------------------------------------
    // Schritt 1: Vorwarnung
    // ------------------------------------------------------------------

    private function send_warnings(): void {
        $warn_days = (int) Settings::get( 'photo_warn_days', 7 );
        if ( $warn_days <= 0 ) {
            return;
        }

        $events = new EventModel();

        foreach ( $this->model->due_for_warning( $warn_days ) as $group ) {
            $user = get_userdata( (int) $group['wp_user_id'] );
            if ( ! $user ) {
                continue;
            }

            $event = $events->find( (int) $group['event_id'] );
            $title = $event['title'] ?? __( 'einem Termin', 'vereins-plugin' );

            $subject = sprintf(
                __( '[%s] Deine Fotos werden bald gelöscht', 'vereins-plugin' ),
                Settings::get( 'sender_name' )
            );

            $body = sprintf(
                __(
                    "Hallo %s,\n\ndeine %d Foto(s) zu „%s“ (%s) werden am %s automatisch gelöscht, um Speicherplatz freizugeben.\n\nWenn du sie behalten möchtest, lade sie vorher herunter – auf der Terminseite gibt es dafür einen Sammel-Download als ZIP.\n\nViele Grüße",
                    'vereins-plugin'
                ),
                $user->display_name,
                (int) $group['cnt'],
                $title,
                mysql2date( 'd.m.Y', $group['instance_date'] ),
                mysql2date( 'd.m.Y', $group['purge_after'] )
            );

            wp_mail( $user->user_email, $subject, $body );
            $this->model->mark_warned(
                (int) $group['wp_user_id'],
                (int) $group['event_id'],
                $group['instance_date']
            );
        }
    }

    // ------------------------------------------------------------------
    // Schritt 2: Löschen
    // ------------------------------------------------------------------

    private function purge_expired(): void {
        // In Häppchen abarbeiten, damit ein Rückstau den Cron nicht sprengt.
        foreach ( $this->model->expired( 200 ) as $photo ) {
            $this->model->delete( (int) $photo['id'] );
        }
    }
}
