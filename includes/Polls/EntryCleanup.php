<?php

namespace VereinsPlugin\Polls;

/**
 * Täglicher Aufräumjob: Meldungen nach dem Wettkampf löschen.
 *
 * Geburtsjahr, Geschlecht und Gewichtsklasse von Kindern braucht nach dem
 * Wettkampf niemand mehr. Gelöscht wird purge_days Tage nach dem
 * Wettkampftag (Einstellung an der Meldung, Vorgabe 30); 0 heißt „nicht
 * automatisch löschen“. Die Umfrage selbst bleibt mit Titel stehen.
 */
class EntryCleanup {

    private const HOOK = 'vp_poll_entry_cleanup';

    public function register(): void {
        add_action( self::HOOK, [ $this, 'run' ] );
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( strtotime( 'tomorrow 4:00' ), 'daily', self::HOOK );
        }
    }

    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    /** Wann sind die Meldungen dieser Umfrage fällig? null = nie. */
    public static function due_date( array $poll ): ?string {
        if ( ( $poll['kind'] ?? '' ) !== 'entry' || empty( $poll['event_date'] ) || (int) $poll['purge_days'] <= 0 ) {
            return null;
        }
        return gmdate( 'Y-m-d', strtotime( $poll['event_date'] . ' +' . (int) $poll['purge_days'] . ' days' ) );
    }

    public function run(): int {
        $model  = new PollModel();
        $heute  = substr( current_time( 'mysql' ), 0, 10 );
        $anzahl = 0;
        foreach ( $model->all() as $poll ) {
            $faellig = self::due_date( $poll );
            if ( $faellig && $faellig <= $heute && empty( $poll['purged_at'] ) ) {
                $model->delete_entries( (int) $poll['id'] );
                $model->mark_purged( (int) $poll['id'] );
                $anzahl++;
            }
        }
        return $anzahl;
    }
}
