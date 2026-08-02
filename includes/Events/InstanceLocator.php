<?php

namespace VereinsPlugin\Events;

/**
 * Eine einzelne Termininstanz zu Termin-ID und Datum auflösen.
 *
 * Bei Serienterminen bezeichnet erst das Paar aus ID und Datum eine konkrete
 * Veranstaltung. Mehrere Stellen brauchen genau das – der öffentliche
 * Terminlink und der Flyer –, deshalb an einer Stelle gebündelt.
 */
class InstanceLocator {

    /**
     * @param bool $only_approved Nur freigegebene Termine liefern. Für alles,
     *                            was nach außen geht, die richtige Wahl.
     */
    public static function find( int $event_id, string $date, bool $only_approved = true ): ?array {
        $date = substr( $date, 0, 10 );

        if ( $event_id <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return null;
        }

        $event = ( new EventModel() )->find( $event_id );

        if ( ! $event ) {
            return null;
        }
        if ( $only_approved && ( $event['approval_status'] ?? 'approved' ) !== 'approved' ) {
            return null;
        }

        // Typ-Daten nachladen, die der Expander und die Anzeigen erwarten.
        $type = ( new EventTypeModel() )->find( (int) $event['event_type_id'] );
        if ( $type ) {
            $event['type_name']     = $type['name'];
            $event['type_color']    = $type['color'];
            $event['allows_items']  = $type['allows_items'];
            $event['needs_helpers'] = $type['needs_helpers'];
            $event['allows_photos'] = $type['allows_photos'];
        }

        // Der Zeitraum wird bewusst weit gefasst: Ein mehrtägiger Termin
        // beginnt womöglich vor dem gesuchten Tag, ein Serientermin liegt
        // mit seinem Instanzdatum darin.
        $from = gmdate( 'Y-m-d H:i:s', strtotime( $date . ' 00:00:00' ) - 30 * DAY_IN_SECONDS );
        $to   = $date . ' 23:59:59';

        foreach ( RecurrenceExpander::expand( [ $event ], $from, $to ) as $instance ) {
            if ( self::date_of( $instance ) === $date ) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * Instanzdatum: bei Serien das ursprüngliche Datum, sonst der Starttag.
     */
    public static function date_of( array $event ): string {
        return $event['original_date'] ?? substr( $event['start_at'], 0, 10 );
    }
}
