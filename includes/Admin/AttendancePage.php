<?php

namespace VereinsPlugin\Admin;

use VereinsPlugin\Support\DateRange;
use VereinsPlugin\Events\AttendanceModel;
use VereinsPlugin\Events\EventModel;
use VereinsPlugin\Events\ItemModel;
use VereinsPlugin\Events\RecurrenceExpander;
use VereinsPlugin\Photos\PhotoModel;
use VereinsPlugin\Support\Capabilities;

/**
 * wp-admin-Seite "Listen": alles zu einer Termininstanz auf einen Blick –
 * Teilnehmer inklusive Begleitpersonen, Absagen, Helfer mit ihren Aufgaben
 * und die Mitbringliste. Jede Liste lässt sich als CSV exportieren.
 *
 * Erreichbar über die Aktion "Listen" in der Terminübersicht.
 */
class AttendancePage {

    private EventModel      $events;
    private AttendanceModel $attendance;
    private ItemModel       $items;
    private PhotoModel      $photos;

    public function __construct() {
        $this->events     = new EventModel();
        $this->attendance = new AttendanceModel();
        $this->items      = new ItemModel();
        $this->photos     = new PhotoModel();
    }

    public function register(): void {
        add_action( 'admin_post_vp_export_list', [ $this, 'handle_export' ] );
    }

    // ------------------------------------------------------------------
    // Ansicht
    // ------------------------------------------------------------------

    public function render(): void {
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;
        $event    = $event_id ? $this->events->find( $event_id ) : null;

        AdminStyles::print_inline();

        if ( ! $event ) {
            echo '<div class="wrap vp-admin-wrap"><h1>' . esc_html__( 'Listen', 'vereins-plugin' ) . '</h1>';
            echo '<p>' . esc_html__( 'Termin nicht gefunden.', 'vereins-plugin' ) . '</p>';
            echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=vp-events' ) ) . '">'
                 . esc_html__( 'Zurück zur Terminübersicht', 'vereins-plugin' ) . '</a></div>';
            return;
        }

        $instances = $this->instances_for( $event );
        $selected  = sanitize_text_field( $_GET['instance_date'] ?? '' );

        if ( ! $selected || ! in_array( $selected, array_column( $instances, 'date' ), true ) ) {
            $selected = $instances[0]['date'] ?? substr( $event['start_at'], 0, 10 );
        }

        $grouped = $this->attendance->for_instance( $event_id, $selected );
        $helpers = $this->items->helpers_for_instance( $event_id, $selected );
        $bring   = $this->items->for_instance( $event_id, $selected, 0, 'bring' );
        $photos  = $this->photos->for_instance( $event_id, $selected );
        ?>
        <div class="wrap vp-admin-wrap">
            <h1>
                <?php echo esc_html( $event['title'] ); ?>
                <span class="vp-listing__sub">
                    <?php echo esc_html( mysql2date( 'd.m.Y', $selected ) ); ?>
                    <?php if ( DateRange::is_multi_day( $event['start_at'], $event['end_at'] ) ) : ?>
                        &middot; <?php echo esc_html( DateRange::detail( $event['start_at'], $event['end_at'], ! empty( $event['is_full_day'] ) ) ); ?>
                    <?php endif; ?>
                </span>
            </h1>

            <p>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=vp-events' ) ); ?>">
                    &larr; <?php esc_html_e( 'Zurück zur Terminübersicht', 'vereins-plugin' ); ?>
                </a>
            </p>

            <?php if ( count( $instances ) > 1 ) : ?>
                <form method="get" class="vp-form-card" style="padding:14px;">
                    <input type="hidden" name="page" value="vp-events">
                    <input type="hidden" name="view" value="lists">
                    <input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
                    <label for="vp-instance"><strong><?php esc_html_e( 'Termin der Serie', 'vereins-plugin' ); ?></strong></label>
                    <div style="display:flex;gap:8px;margin-top:6px;">
                        <select id="vp-instance" name="instance_date">
                            <?php foreach ( $instances as $inst ) : ?>
                                <option value="<?php echo esc_attr( $inst['date'] ); ?>" <?php selected( $selected, $inst['date'] ); ?>>
                                    <?php echo esc_html( $inst['label'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button"><?php esc_html_e( 'Anzeigen', 'vereins-plugin' ); ?></button>
                    </div>
                </form>
            <?php endif; ?>

            <?php
            $this->render_attendees( $grouped, $event_id, $selected );
            $this->render_declines( $grouped, $event_id, $selected );
            $this->render_helpers( $helpers, $event_id, $selected );
            $this->render_bring( $bring, $event_id, $selected );
            $this->render_photo_note( $photos );
            ?>
        </div>

        <style>
        .vp-admin-wrap .vp-listing__sub { font-size: .6em; font-weight: 400; color: #6b7280; margin-left: 8px; }
        .vp-admin-wrap .vp-listing { margin-top: 26px; }
        .vp-admin-wrap .vp-listing__head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .vp-admin-wrap .vp-listing__head h2 { margin: 0; }
        .vp-admin-wrap .vp-listing__via { color: #6b7280; }
        .vp-admin-wrap .vp-listing__pending { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 999px; background: #FEF3C7; color: #92400E; font-size: 11px; }
        </style>
        <?php
    }

    // ------------------------------------------------------------------
    // Einzelne Listen
    // ------------------------------------------------------------------

    private function render_attendees( array $grouped, int $event_id, string $date ): void {
        $rows = $grouped['attending'] ?? [];
        ?>
        <div class="vp-listing">
            <div class="vp-listing__head">
                <h2><?php printf( esc_html__( 'Teilnehmer (%d)', 'vereins-plugin' ), count( $rows ) ); ?></h2>
                <?php $this->export_button( 'attendees', $event_id, $date, count( $rows ) ); ?>
            </div>

            <table class="vp-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Art', 'vereins-plugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="2"><?php esc_html_e( 'Noch keine Zusagen.', 'vereins-plugin' ); ?></td></tr>
                <?php endif; ?>
                <?php foreach ( $rows as $person ) : ?>
                    <tr>
                        <td><?php echo esc_html( $person['name'] ); ?><?php $this->pending_mark( $person ); ?></td>
                        <td>
                            <?php if ( $person['via'] ) : ?>
                                <span class="vp-listing__via">
                                    <?php printf( esc_html__( 'Begleitung von %s', 'vereins-plugin' ), esc_html( $person['via'] ) ); ?>
                                </span>
                            <?php else : ?>
                                <?php esc_html_e( 'Mitglied', 'vereins-plugin' ); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_declines( array $grouped, int $event_id, string $date ): void {
        $rows = $grouped['no'] ?? [];
        ?>
        <div class="vp-listing">
            <div class="vp-listing__head">
                <h2><?php printf( esc_html__( 'Absagen (%d)', 'vereins-plugin' ), count( $rows ) ); ?></h2>
                <?php $this->export_button( 'declines', $event_id, $date, count( $rows ) ); ?>
            </div>

            <table class="vp-table">
                <thead>
                    <tr><th><?php esc_html_e( 'Name', 'vereins-plugin' ); ?></th></tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td><?php esc_html_e( 'Keine Absagen.', 'vereins-plugin' ); ?></td></tr>
                <?php endif; ?>
                <?php foreach ( $rows as $person ) : ?>
                    <tr><td><?php echo esc_html( $person['name'] ); ?><?php $this->pending_mark( $person ); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_helpers( array $helpers, int $event_id, string $date ): void {
        ?>
        <div class="vp-listing">
            <div class="vp-listing__head">
                <h2><?php printf( esc_html__( 'Helfer (%d)', 'vereins-plugin' ), count( $helpers ) ); ?></h2>
                <?php $this->export_button( 'helpers', $event_id, $date, count( $helpers ) ); ?>
            </div>

            <table class="vp-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Aufgaben', 'vereins-plugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $helpers ) ) : ?>
                    <tr><td colspan="2"><?php esc_html_e( 'Noch keine Helfer eingeteilt.', 'vereins-plugin' ); ?></td></tr>
                <?php endif; ?>
                <?php foreach ( $helpers as $helper ) : ?>
                    <tr>
                        <td>
                            <?php echo esc_html( $helper['name'] ); ?><?php $this->pending_mark( $helper ); ?>
                            <?php if ( $helper['via'] ) : ?>
                                <span class="vp-listing__via">
                                    (<?php printf( esc_html__( 'Begleitung von %s', 'vereins-plugin' ), esc_html( $helper['via'] ) ); ?>)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $helper['help_task'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_bring( array $items, int $event_id, string $date ): void {
        ?>
        <div class="vp-listing">
            <div class="vp-listing__head">
                <h2><?php printf( esc_html__( 'Mitbringliste (%d)', 'vereins-plugin' ), count( $items ) ); ?></h2>
                <?php $this->export_button( 'bring', $event_id, $date, count( $items ) ); ?>
            </div>

            <table class="vp-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Posten', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Benötigt', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Offen', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Wer bringt mit', 'vereins-plugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $items ) ) : ?>
                    <tr><td colspan="4"><?php esc_html_e( 'Nichts eingetragen.', 'vereins-plugin' ); ?></td></tr>
                <?php endif; ?>
                <?php foreach ( $items as $item ) : ?>
                    <tr>
                        <td><?php echo esc_html( $item['label'] ); ?></td>
                        <td><?php echo esc_html( $item['quantity_needed'] ); ?></td>
                        <td>
                            <?php if ( $item['open'] > 0 ) : ?>
                                <strong style="color:#b45309;"><?php echo esc_html( $item['open'] ); ?></strong>
                            <?php else : ?>
                                <span style="color:#059669;"><?php esc_html_e( 'vollständig', 'vereins-plugin' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $this->claim_names( $item['claims'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_photo_note( array $photos ): void {
        if ( empty( $photos ) ) {
            return;
        }
        ?>
        <p class="description" style="margin-top:20px;">
            <?php printf( esc_html__( 'Zu diesem Termin liegen %d Fotos vor.', 'vereins-plugin' ), count( $photos ) ); ?>
        </p>
        <?php
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    public function handle_export(): void {
        $event_id = (int) ( $_GET['event_id'] ?? 0 );
        $date     = substr( sanitize_text_field( $_GET['instance_date'] ?? '' ), 0, 10 );
        $kind     = sanitize_text_field( $_GET['kind'] ?? '' );

        check_admin_referer( 'vp_export_list_' . $event_id . '_' . $date . '_' . $kind );

        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $event = $this->events->find( $event_id );
        if ( ! $event ) {
            wp_die( esc_html__( 'Termin nicht gefunden.', 'vereins-plugin' ) );
        }

        [ $header, $rows ] = $this->export_data( $kind, $event_id, $date );

        $this->stream_csv(
            sanitize_title( $event['title'] ) . '-' . $date . '-' . $kind . '.csv',
            $header,
            $rows
        );
    }

    /**
     * @return array{0: string[], 1: array<int, string[]>}
     */
    private function export_data( string $kind, int $event_id, string $date ): array {
        switch ( $kind ) {
            case 'helpers':
                $rows = [];
                foreach ( $this->items->helpers_for_instance( $event_id, $date ) as $helper ) {
                    $rows[] = [
                        $helper['name'],
                        $helper['via'] ?: '',
                        $helper['help_task'],
                        $this->contact_state( $helper ),
                    ];
                }
                return [
                    [ __( 'Name', 'vereins-plugin' ), __( 'Begleitung von', 'vereins-plugin' ), __( 'Aufgaben', 'vereins-plugin' ), __( 'Kontakt', 'vereins-plugin' ) ],
                    $rows,
                ];

            case 'declines':
                $grouped = $this->attendance->for_instance( $event_id, $date );
                $rows    = array_map(
                    fn( $p ) => [ $p['name'], $this->contact_state( $p ) ],
                    $grouped['no'] ?? []
                );
                return [ [ __( 'Name', 'vereins-plugin' ), __( 'Kontakt', 'vereins-plugin' ) ], $rows ];

            case 'bring':
                $rows = [];
                foreach ( $this->items->for_instance( $event_id, $date, 0, 'bring' ) as $item ) {
                    $rows[] = [
                        $item['label'],
                        (string) $item['quantity_needed'],
                        (string) $item['open'],
                        $this->claim_names( $item['claims'] ),
                    ];
                }
                return [
                    [
                        __( 'Posten', 'vereins-plugin' ),
                        __( 'Benötigt', 'vereins-plugin' ),
                        __( 'Offen', 'vereins-plugin' ),
                        __( 'Wer bringt mit', 'vereins-plugin' ),
                    ],
                    $rows,
                ];

            case 'attendees':
            default:
                $grouped = $this->attendance->for_instance( $event_id, $date );
                $rows    = [];
                foreach ( $grouped['attending'] ?? [] as $person ) {
                    $rows[] = [
                        $person['name'],
                        $person['via'] ? __( 'Begleitung', 'vereins-plugin' ) : __( 'Mitglied', 'vereins-plugin' ),
                        $person['via'] ?: '',
                        $this->contact_state( $person ),
                    ];
                }
                return [
                    [ __( 'Name', 'vereins-plugin' ), __( 'Art', 'vereins-plugin' ), __( 'Begleitung von', 'vereins-plugin' ), __( 'Kontakt', 'vereins-plugin' ) ],
                    $rows,
                ];
        }
    }

    /**
     * CSV mit Semikolon und BOM – so öffnet Excel die Datei ohne Nachfrage
     * und stellt Umlaute korrekt dar.
     */
    private function stream_csv( string $filename, array $header, array $rows ): void {
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        echo "\xEF\xBB\xBF"; // UTF-8 BOM

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, $header, ';' );
        foreach ( $rows as $row ) {
            fputcsv( $out, $row, ';' );
        }
        fclose( $out );

        exit;
    }

    // ------------------------------------------------------------------
    // Helfer
    // ------------------------------------------------------------------

    private function export_button( string $kind, int $event_id, string $date, int $count ): void {
        if ( $count < 1 ) {
            return;
        }

        $url = add_query_arg( [
            'action'        => 'vp_export_list',
            'kind'          => $kind,
            'event_id'      => $event_id,
            'instance_date' => $date,
            '_wpnonce'      => wp_create_nonce( 'vp_export_list_' . $event_id . '_' . $date . '_' . $kind ),
        ], admin_url( 'admin-post.php' ) );
        ?>
        <a class="button button-small" href="<?php echo esc_url( $url ); ?>">
            <?php esc_html_e( 'Als CSV exportieren', 'vereins-plugin' ); ?>
        </a>
        <?php
    }

    /**
     * Kennzeichnet Personen, die über einen Terminlink zugesagt haben und
     * deren Kontaktangabe noch nicht bestätigt ist.
     */
    private function pending_mark( array $person ): void {
        if ( empty( $person['unconfirmed'] ) ) {
            return;
        }
        printf(
            ' <span class="vp-listing__pending" title="%s">%s</span>',
            esc_attr__( 'Kontakt noch nicht bestätigt', 'vereins-plugin' ),
            esc_html__( 'unbestätigt', 'vereins-plugin' )
        );
    }

    private function contact_state( array $person ): string {
        return empty( $person['unconfirmed'] )
            ? __( 'bestätigt', 'vereins-plugin' )
            : __( 'unbestätigt', 'vereins-plugin' );
    }

    private function claim_names( array $claims ): string {
        return implode( ', ', array_map( function ( $claim ) {
            $label = $claim['name'];
            if ( ! empty( $claim['via'] ) ) {
                $label .= sprintf( __( ' (Begleitung von %s)', 'vereins-plugin' ), $claim['via'] );
            }
            if ( (int) $claim['quantity'] > 1 ) {
                $label .= ' (' . (int) $claim['quantity'] . ')';
            }
            return $label;
        }, $claims ) );
    }

    /**
     * Auswählbare Termine: bei Serien die Instanzen im Bereich von einem Jahr
     * rückwärts bis ein Jahr vorwärts, sonst nur der eine Termin.
     *
     * @return array<int, array{date:string, label:string}>
     */
    private function instances_for( array $event ): array {
        if ( empty( $event['is_recurring'] ) ) {
            $date = substr( $event['start_at'], 0, 10 );
            return [ [ 'date' => $date, 'label' => mysql2date( 'd.m.Y', $event['start_at'] ) ] ];
        }

        $from = gmdate( 'Y-m-d H:i:s', strtotime( '-1 year' ) );
        $to   = gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) );

        $expanded = RecurrenceExpander::expand( [ $event ], $from, $to );

        $list = [];
        foreach ( $expanded as $instance ) {
            $date = $instance['original_date'] ?? substr( $instance['start_at'], 0, 10 );
            $list[ $date ] = [
                'date'  => $date,
                'label' => mysql2date( 'd.m.Y', $instance['start_at'] ),
            ];
        }

        // Der nächstgelegene Termin zuerst – das ist meist der gesuchte.
        krsort( $list );
        return array_values( $list );
    }
}
