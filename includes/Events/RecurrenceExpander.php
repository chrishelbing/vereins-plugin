<?php

namespace VereinsPlugin\Events;

/**
 * Expandiert Serien-Termine in konkrete Instanzen für einen Zeitraum.
 *
 * Eingabe:  Array von Stamm-Events (mit 'recurrence' und 'exceptions')
 * Ausgabe:  Flache Liste konkreter Termin-Instanzen im angefragten Zeitraum
 */
class RecurrenceExpander {

    /**
     * @param  array[]  $events  Rohe Events aus EventModel::in_range()
     * @param  string   $from    Y-m-d H:i:s
     * @param  string   $to      Y-m-d H:i:s
     * @return array[]  Expandierte Instanzen, sortiert nach start_at
     */
    public static function expand( array $events, string $from, string $to ): array {
        $instances = [];

        foreach ( $events as $event ) {
            if ( ! $event['is_recurring'] || empty( $event['recurrence'] ) ) {
                // Einmaliger Termin – nur aufnehmen wenn im Zeitraum
                if ( $event['start_at'] < $to && $event['end_at'] > $from ) {
                    $instances[] = self::to_instance( $event, $event['start_at'], $event['end_at'] );
                }
                continue;
            }

            $instances = array_merge(
                $instances,
                self::expand_recurring( $event, $from, $to )
            );
        }

        usort( $instances, fn( $a, $b ) => strcmp( $a['start_at'], $b['start_at'] ) );

        return $instances;
    }

    // ------------------------------------------------------------------
    // Interne Methoden
    // ------------------------------------------------------------------

    private static function expand_recurring( array $event, string $from, string $to ): array {
        $rule      = $event['recurrence'];
        $exceptions = self::index_exceptions( $event['exceptions'] ?? [] );
        $instances  = [];

        $duration   = strtotime( $event['end_at'] ) - strtotime( $event['start_at'] );
        $interval   = (int) ( $rule['interval_n'] ?? 1 );
        $frequency  = $rule['frequency'];
        $weekdays   = $rule['weekdays'] ?? null;   // ['MO','TU',...]
        $until      = $rule['until']    ? new \DateTime( $rule['until'] . ' 23:59:59' ) : null;
        $max_count  = $rule['count']    ?? PHP_INT_MAX;

        $cursor     = new \DateTime( $event['start_at'] );
        $from_dt    = new \DateTime( $from );
        $to_dt      = new \DateTime( $to );
        $count      = 0;

        // Tages-Map für Wochentags-Kurzzeichen
        $dow_map = [ 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 0 ];

        while ( $cursor <= $to_dt && $count < $max_count ) {
            if ( $until && $cursor > $until ) break;

            // Wochentags-Filter bei WEEKLY
            if ( $frequency === 'WEEKLY' && $weekdays ) {
                $current_dow = (int) $cursor->format( 'w' ); // 0=Sun
                $allowed     = array_map( fn( $d ) => $dow_map[ $d ] ?? -1, $weekdays );
                if ( ! in_array( $current_dow, $allowed ) ) {
                    $cursor->modify( '+1 day' );
                    continue;
                }
            }

            $original_date = $cursor->format( 'Y-m-d' );
            $inst_start    = $cursor->format( 'Y-m-d H:i:s' );
            $inst_end      = gmdate( 'Y-m-d H:i:s', $cursor->getTimestamp() + $duration );

            // Ausnahme prüfen
            if ( isset( $exceptions[ $original_date ] ) ) {
                $exc = $exceptions[ $original_date ];

                if ( $exc['exception_type'] === 'cancelled' ) {
                    // Instanz fällt aus – überspringen, aber zählen
                    $count++;
                    self::advance( $cursor, $frequency, $interval, $weekdays );
                    continue;
                }

                // Modifizierte Instanz
                $inst_start = $exc['start_at'] ?? $inst_start;
                $inst_end   = $exc['end_at']   ?? $inst_end;
                $instance   = self::to_instance( $event, $inst_start, $inst_end, $exc );
            } else {
                $instance = self::to_instance( $event, $inst_start, $inst_end );
            }

            // Nur aufnehmen wenn im Zielzeitraum
            if ( $inst_start < $to && $inst_end > $from ) {
                $instance['original_date'] = $original_date;
                $instances[] = $instance;
            }

            $count++;
            self::advance( $cursor, $frequency, $interval, $weekdays );
        }

        return $instances;
    }

    /**
     * Cursor zum nächsten Termin der Serie bewegen.
     */
    private static function advance( \DateTime $cursor, string $frequency, int $interval, ?array $weekdays ): void {
        match ( $frequency ) {
            'DAILY'   => $cursor->modify( "+{$interval} day" ),
            'WEEKLY'  => $weekdays
                         ? $cursor->modify( '+1 day' )  // bei Wochentags-Filter täglich iterieren
                         : $cursor->modify( "+{$interval} week" ),
            'MONTHLY' => $cursor->modify( "+{$interval} month" ),
            'YEARLY'  => $cursor->modify( "+{$interval} year" ),
            default   => $cursor->modify( '+1 day' ),
        };
    }

    /**
     * Baut ein Instanz-Array aus einem Stamm-Event + optionaler Ausnahme.
     */
    private static function to_instance( array $event, string $start, string $end, ?array $exception = null ): array {
        return [
            'event_id'      => (int) $event['id'],
            'event_type_id' => (int) $event['event_type_id'],
            'type_name'     => $event['type_name']  ?? null,
            'type_color'    => $event['type_color'] ?? null,
            'is_resource'   => (bool) ( $event['is_resource'] ?? false ),
            'allows_rsvp'   => EventTypeModel::rsvp_allowed( $event ),
            'allows_items'  => (bool) ( $event['allows_items'] ?? false ),
            'needs_helpers' => (bool) ( $event['needs_helpers'] ?? false ),
            'allows_photos' => (bool) ( $event['allows_photos'] ?? false ),
            // Für die Berechnung der Foto-Upload-Frist je Instanz.
            // 'end_at' der Instanz genügt dabei als Bezug: bei Einzelterminen
            // ist es das Terminende, bei Serien zählt ohnehin das Instanzdatum.
            'is_recurring'      => (bool) ( $event['is_recurring'] ?? false ),
            'photo_upload_days' => $event['photo_upload_days'] ?? null,
            // Titelbild der Kachel – gilt für alle Instanzen einer Serie.
            'image_attachment_id' => $event['image_attachment_id'] ?? null,
            // Banner auf der Website – gilt wie das Titelbild für die ganze Serie.
            'banner_days_before'  => $event['banner_days_before'] ?? null,
            // Tags gelten für die ganze Serie.
            'tags'                => \VereinsPlugin\Support\Tags::decode( $event['tags'] ?? null ),
            'title'         => $exception['title']       ?? $event['title'],
            'description'   => $exception['description'] ?? $event['description'],
            'location'      => $exception['location']    ?? $event['location'],
            'start_at'      => $start,
            'end_at'        => $end,
            'is_full_day'   => (bool) $event['is_full_day'],
            'is_modified'   => $exception !== null,
            'created_by'    => (int) $event['created_by'],
        ];
    }

    /**
     * Ausnahmen nach Datum indizieren für O(1)-Lookup.
     */
    private static function index_exceptions( array $exceptions ): array {
        $indexed = [];
        foreach ( $exceptions as $exc ) {
            $indexed[ $exc['original_date'] ] = $exc;
        }
        return $indexed;
    }
}
