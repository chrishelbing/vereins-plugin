<?php

namespace VereinsPlugin\Frontend;

use VereinsPlugin\Events\InstanceLocator;
use VereinsPlugin\Flyer\FlyerPage;
use VereinsPlugin\Events\AttendanceModel;
use VereinsPlugin\Events\CompanionModel;
use VereinsPlugin\Events\EventModel;
use VereinsPlugin\Events\EventTypeModel;
use VereinsPlugin\Events\ItemModel;
use VereinsPlugin\Events\RecurrenceExpander;
use VereinsPlugin\Admin\Settings;
use VereinsPlugin\Photos\PhotoDownload;
use VereinsPlugin\Photos\PhotoModel;
use VereinsPlugin\Photos\PhotoService;
use VereinsPlugin\Members\GuestService;
use VereinsPlugin\Support\Capabilities;
use VereinsPlugin\Support\DateRange;
use VereinsPlugin\Support\PublicLink;
use VereinsPlugin\Support\Icons;

/**
 * Shortcode [vp_events]
 *
 * Zeigt anstehende Termine und darunter vergangene, solange deren Fotos
 * noch abrufbar sind.
 *
 * Parameter:
 *   days  – wie viele Tage in die Zukunft (Standard: 30)
 *   limit – max. Anzahl Termine je Abschnitt (Standard: 10)
 *
 * Beispiel: [vp_events days="60" limit="5"]
 */
class EventsShortcode {

    /**
     * Beschreibung für die Shortcode-Übersicht im Backend.
     */
    public static function describe(): array {
        return [
            'tag'         => 'vp_events',
            'title'       => __( 'Terminliste', 'vereins-plugin' ),
            'description' => __( 'Zeigt kommende Termine als Kacheln mit Zu-/Absage, Helferaufgaben, Mitbringliste und Fotos. Darunter vergangene Termine, solange deren Fotos noch abrufbar sind. Angemeldete Mitglieder sehen die ganze Liste. Über einen geteilten Terminlink ist zusätzlich ein einzelner Termin ohne Anmeldung erreichbar – dort ohne Namen und ohne Fotos.', 'vereins-plugin' ),
            'atts'        => [
                [ 'days',  '30', __( 'Wie viele Tage in die Zukunft geschaut wird.', 'vereins-plugin' ) ],
                [ 'limit', '10', __( 'Maximale Anzahl Termine je Abschnitt.', 'vereins-plugin' ) ],
            ],
            'example'     => '[vp_events days="60" limit="20"]',
        ];
    }

    public function register(): void {
        add_shortcode( 'vp_events', [ $this, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function render( array $atts ): string {
        $notice = $this->handle_confirmation();

        // Öffentlicher Termin-Link: Wer den Link hat, sieht genau diesen einen
        // Termin und kann zusagen, ohne sich anzumelden.
        if ( ! is_user_logged_in() ) {
            $public = PublicLink::current_instance();

            if ( $public ) {
                $instance = $this->public_instance( $public['event_id'], $public['instance_date'] );

                if ( ! $instance ) {
                    return '<p class="vp-notice">' . esc_html__( 'Dieser Termin ist nicht mehr verfügbar.', 'vereins-plugin' ) . '</p>';
                }

                return $notice . $this->render_list(
                    [ $instance ],
                    [],
                    PublicLink::instance_key( $public['event_id'], $public['instance_date'] )
                );
            }

            return $notice . '<p class="vp-notice">'
                   . esc_html__( 'Bitte melde dich an, um die Termine zu sehen.', 'vereins-plugin' )
                   . '</p>';
        }

        if ( ! Capabilities::is_active_member() ) {
            return '<p class="vp-notice">'
                   . esc_html__( 'Dein Zugang wurde noch nicht freigeschaltet oder ist deaktiviert.', 'vereins-plugin' )
                   . '</p>';
        }

        $atts = shortcode_atts( [ 'days' => 30, 'limit' => 10 ], $atts );
        $days  = max( 1, min( 365, (int) $atts['days']  ) );
        $limit = max( 1, min( 100, (int) $atts['limit'] ) );

        $now = current_time( 'Y-m-d H:i:s' );
        $to  = gmdate( 'Y-m-d H:i:s', strtotime( "+{$days} days" ) );

        // Auch zurückblicken: Vergangene Termine bleiben sichtbar, solange
        // ihre Fotos noch abrufbar sind. Der Rückblick deckt Upload-Frist und
        // Aufbewahrung ab, gefiltert wird gleich instanzgenau.
        $lookback = max(
            (int) Settings::get( 'photo_upload_days', 14 ),
            (int) Settings::get( 'photo_retention_days', 90 )
        );
        $from = $lookback > 0
            ? gmdate( 'Y-m-d H:i:s', strtotime( $now . " -{$lookback} days" ) )
            : gmdate( 'Y-m-d H:i:s', strtotime( $now . ' -2 years' ) );

        $model     = new EventModel();
        $raw       = $model->in_range( $from, $to );
        $instances = RecurrenceExpander::expand( $raw, $from, $to );

        [ $upcoming, $past ] = $this->split_by_time( $instances, $now );

        $upcoming = array_slice( $upcoming, 0, $limit );
        $past     = array_slice( $past, 0, $limit );

        if ( empty( $upcoming ) && empty( $past ) ) {
            return '<p class="vp-notice">'
                   . esc_html__( 'Keine Termine in diesem Zeitraum.', 'vereins-plugin' )
                   . '</p>';
        }

        return $this->render_list( $upcoming, $past );
    }

    /**
     * Löst einen Bestätigungslink aus der E-Mail ein.
     */
    private function handle_confirmation(): string {
        $user_id = PublicLink::confirming_user();
        if ( ! $user_id ) {
            return '';
        }

        $ok = ( new GuestService() )->confirm( $user_id );

        return '<p class="vp-notice vp-notice--ok">' . esc_html(
            $ok
                ? __( 'Danke, deine Angaben sind bestätigt. Beim nächsten Mal erkennen wir dich automatisch.', 'vereins-plugin' )
                : __( 'Dieser Bestätigungslink ist nicht mehr gültig.', 'vereins-plugin' )
        ) . '</p>';
    }

    /**
     * Eine einzelne Termininstanz für den öffentlichen Link auflösen.
     * Nur freigegebene Termine sind über den Link erreichbar.
     */
    private function public_instance( int $event_id, string $date ): ?array {
        return InstanceLocator::find( $event_id, $date );
    }

    /**
     * Trennt kommende von vergangenen Instanzen.
     *
     * Vergangene bleiben nur stehen, wenn es dort noch etwas zu holen gibt:
     * entweder läuft die Upload-Frist noch (dann sollen Fotos hochgeladen
     * werden können) oder es liegen Fotos vor. Räumt der Cron die Fotos ab,
     * verschwindet der Termin von selbst aus der Liste.
     *
     * @return array{0: array, 1: array}  [kommend aufsteigend, vergangen absteigend]
     */
    private function split_by_time( array $instances, string $now ): array {
        $upcoming   = [];
        $candidates = [];

        foreach ( $instances as $instance ) {
            if ( $instance['end_at'] >= $now ) {
                $upcoming[] = $instance;
                continue;
            }

            // Ohne Fotos gibt es nach dem Termin nichts mehr zu sehen.
            if ( ! empty( $instance['allows_photos'] ) ) {
                $candidates[] = $instance;
            }
        }

        $past = [];

        if ( $candidates ) {
            // Fotostände für alle Kandidaten in einer Abfrage holen.
            $keys = array_map( fn( $i ) => [
                'event_id'      => (int) $i['event_id'],
                'instance_date' => $this->instance_date( $i ),
            ], $candidates );

            $counts        = ( new PhotoModel() )->counts_for_instances( $keys );
            $photo_service = new PhotoService();

            foreach ( $candidates as $instance ) {
                $event_id = (int) $instance['event_id'];
                $date     = $this->instance_date( $instance );
                $window   = $photo_service->upload_window( $event_id, $date, $instance );

                // Sichtbar bleiben, solange hochgeladen werden darf oder noch
                // Fotos da sind. Räumt der Cron sie ab, verschwindet der
                // Termin von selbst.
                if ( $window['open'] || ! empty( $counts[ $event_id . '|' . $date ] ) ) {
                    $past[] = $instance;
                }
            }
        }

        // Vergangene: das zuletzt Gewesene zuerst.
        usort( $past, fn( $a, $b ) => strcmp( $b['start_at'], $a['start_at'] ) );

        return [ $upcoming, $past ];
    }

    /**
     * Sammelt in wenigen Abfragen alles ein, was an den Kacheln steht.
     *
     * Nur Zahlen und die eigene Antwort – die Namen holt erst das jeweilige
     * Overlay bei Bedarf nach, damit die Seite schlank bleibt. Gesammelt wird
     * über alle Instanzen zugleich; einzeln wären es fünf Abfragen je Kachel.
     *
     * @return array{attendance: array, items: array, help: array, photos: array, companions: array}
     *         Jeweils nach "termin-id|instanz-datum" abgelegt.
     */
    private function collect_stats( array $instances, int $user_id ): array {
        $keys = [];
        foreach ( $instances as $event ) {
            $keys[] = [
                'event_id'      => (int) $event['event_id'],
                'instance_date' => $this->instance_date( $event ),
            ];
        }

        $item_model = new ItemModel();

        // Eigene Begleitpersonen je Instanz – für den Zähler am Button.
        $companion_model = new CompanionModel();
        $companions      = [];
        foreach ( $keys as $k ) {
            $companions[ $k['event_id'] . '|' . $k['instance_date'] ] =
                $companion_model->for_attendance( $k['event_id'], $k['instance_date'], $user_id );
        }

        return [
            'attendance' => ( new AttendanceModel() )->summary_for_instances( $keys, $user_id ),
            'items'      => $item_model->summary_for_instances( $keys, 'bring' ),
            'help'       => $item_model->summary_for_instances( $keys, 'help' ),
            'photos'     => ( new PhotoModel() )->counts_for_instances( $keys ),
            'companions' => $companions,
        ];
    }

    /**
     * @param string $link_key  Gesetzt, wenn die Liste über einen öffentlichen
     *                          Terminlink aufgerufen wurde. Dann sind Namen
     *                          anderer verborgen und Aktionen fragen zuerst
     *                          nach einer Kennung.
     */
    private function render_list( array $upcoming, array $past = [], string $link_key = '' ): string {
        $is_public = $link_key !== '';
        $user_id   = $is_public ? PublicLink::current_guest() : get_current_user_id();
        $instances = array_merge( $upcoming, $past );

        $stats = $this->collect_stats( $instances, (int) $user_id );

        // Titelbilder pflegt das Orgateam direkt an der Kachel.
        $can_edit_cover = ! $is_public && Capabilities::is_orga();

        // Lohnt der Bearbeiten-Dialog überhaupt? Er kommt samt Formular und
        // Termintypen, deshalb nur, wenn wenigstens eine Kachel ihn braucht.
        $kann_bearbeiten = false;
        if ( ! $is_public ) {
            foreach ( $instances as $moeglich ) {
                if ( Capabilities::can_edit_event( $moeglich ) ) {
                    $kann_bearbeiten = true;
                    break;
                }
            }
        }

        $api   = esc_url( rest_url( 'vereins-plugin/v1' ) );
        $nonce = wp_create_nonce( 'wp_rest' );

        // Stil und Skript liegen in assets/. Das Skript kommt in den Fußbereich –
        // dort ist die Liste bereits im Dokument. Angefordert wird beides erst
        // hier, damit es nur mitkommt, wenn wirklich etwas gerendert wird.
        $this->enqueue_for_list( $api, $nonce, $link_key, $is_public, (int) $user_id, $upcoming, $kann_bearbeiten );

        ob_start();
        ?>
        <div class="vp-events" id="vp-events">
            <?php if ( empty( $upcoming ) ) : ?>
                <p class="vp-notice"><?php esc_html_e( 'Keine anstehenden Termine.', 'vereins-plugin' ); ?></p>
            <?php endif; ?>

            <ul class="vp-events__list">
                <?php $past_starts_at = count( $upcoming ); ?>
                <?php foreach ( $instances as $index => $event ) : ?>
                    <?php if ( $index === $past_starts_at && $past ) : ?>
                        <?php // Liste schließen, Überschrift setzen, neue Liste öffnen ?>
                        </ul>
                        <h3 class="vp-events__heading"><?php esc_html_e( 'Vergangene Termine', 'vereins-plugin' ); ?></h3>
                        <p class="vp-events__hint">
                            <?php esc_html_e( 'Bleiben sichtbar, solange die Fotos verfügbar sind.', 'vereins-plugin' ); ?>
                        </p>
                        <ul class="vp-events__list">
                    <?php endif; ?>
                    <?php
                    $this->render_tile(
                        $this->prepare_tile( $event, $index >= $past_starts_at, $stats, $is_public ),
                        $is_public,
                        $can_edit_cover
                    );
                    ?>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php

        // Die Overlays heißen wie die Module in assets/js/events/, die sie
        // bedienen. Sie stehen einmal je Seite, nicht je Kachel.
        $this->overlay_names();
        $this->overlay_items();

        if ( $kann_bearbeiten ) {
            // Der Dialog steht einmal je Seite und wird beim Klick auf den
            // Stift gefüllt – die Werte holt edit.js über GET /events/{id}.
            $this->overlay_edit();
        }

        if ( $can_edit_cover ) {
            // Auswahlfeld für das Titelbild; ausgelöst wird es von tile.js.
            echo '<input type="file" id="vp-cover-input" accept="image/*" hidden>';
        }

        if ( $is_public ) {
            $this->overlay_identity();
        }

        $this->overlay_people();
        $this->overlay_photos();

        return ob_get_clean();
    }

    /**
     * Rechnet zusammen, was an einer Kachel steht.
     *
     * Die Textwerte kommen hier bereits maskiert heraus und werden im Markup
     * ohne weiteres esc_*() eingesetzt. Das war vorher genauso; wer das Feld
     * ergänzt, maskiert an dieser Stelle mit.
     */
    private function prepare_tile( array $event, bool $is_past, array $stats, bool $is_public ): array {
        $event_id  = (int) $event['event_id'];
        $inst_date = $this->instance_date( $event );
        $key       = $event_id . '|' . $inst_date;
        $rsvp      = $stats['attendance'][ $key ] ?? AttendanceModel::empty_summary();
        $full_day  = (bool) $event['is_full_day'];

        // Mehrtägige Termine – etwa ein Camp von Freitag 16 Uhr bis Sonntag
        // 17 Uhr – brauchen beide Eckpunkte.
        $multi_day = DateRange::is_multi_day( $event['start_at'], $event['end_at'] );
        $badge     = DateRange::badge( $event['start_at'], $event['end_at'] );

        $allows_rsvp = EventTypeModel::rsvp_allowed( $event );

        return [
            'event_id'      => $event_id,
            'inst_date'     => $inst_date,
            'is_past'       => $is_past,
            'color'         => esc_attr( $event['type_color'] ?? '#3B82F6' ),
            'type'          => esc_html( $event['type_name'] ?? '' ),
            'title'         => esc_html( $event['title'] ),
            'tags'          => \VereinsPlugin\Support\Tags::decode( $event['tags'] ?? null ),
            'loc'           => $event['location'] ? esc_html( $event['location'] ) : '',
            'multi_day'     => $multi_day,
            'day'           => $badge['day'],
            'month'         => $badge['month'],
            'time'          => esc_html( DateRange::short( $event['start_at'], $event['end_at'], $full_day ) ),
            'span'          => $multi_day
                                  ? esc_html( DateRange::detail( $event['start_at'], $event['end_at'], $full_day ) )
                                  : '',
            'cover'         => PhotoService::cover_urls( (int) ( $event['image_attachment_id'] ?? 0 ) ),
            'rsvp'          => $rsvp,
            'own'           => $rsvp['own'],
            'own_guests'    => count( $stats['companions'][ $key ] ?? [] ),
            'items'         => $stats['items'][ $key ] ?? [ 'items' => 0, 'open' => 0 ],
            'helps'         => $stats['help'][ $key ] ?? [ 'items' => 0, 'open' => 0 ],
            'photo_count'   => $stats['photos'][ $key ] ?? 0,
            // Was der Termintyp erlaubt, entscheidet über die Bereiche.
            // Ein reiner Infotermin kennt weder Zusagen noch Helfer noch
            // Mitbringliste – auch wenn an einem älteren Typ noch etwas
            // davon eingetragen sein sollte.
            'allows_rsvp'   => $allows_rsvp,
            'allows_items'  => $allows_rsvp && ! empty( $event['allows_items'] ),
            'needs_helpers' => $allows_rsvp && ! empty( $event['needs_helpers'] ),
            // Fotos bleiben angemeldeten Mitgliedern vorbehalten.
            'allows_photos' => ! $is_public && ! empty( $event['allows_photos'] ),
            // Bearbeiten darf, wer den Termin angelegt hat, dazu das
            // Orgateam. Über einen öffentlichen Terminlink nie – dort ist
            // gar nicht sicher, wer da klickt.
            'can_edit'      => ! $is_public && Capabilities::can_edit_event( $event ),
        ];
    }

    /**
     * Eine Kachel. $t kommt aus prepare_tile().
     */
    private function render_tile( array $t, bool $is_public, bool $can_edit_cover ): void {
        ?>
        <li class="vp-events__item<?php echo $t['is_past'] ? ' is-past' : ''; ?>"
            id="<?php echo esc_attr( self::anchor( $t['event_id'], $t['inst_date'] ) ); ?>"
            data-event-id="<?php echo esc_attr( $t['event_id'] ); ?>"
            data-instance-date="<?php echo esc_attr( $t['inst_date'] ); ?>">

        <?php $this->tile_cover( $t, $can_edit_cover ); ?>

            <div class="vp-tile__body">
                <?php if ( $t['type'] ) : ?>
                    <span class="vp-events__type" style="color:<?php echo $t['color']; ?>"><?php echo $t['type']; ?></span>
                <?php endif; ?>
                <span class="vp-events__title"><?php echo $t['title']; ?></span>
                <?php if ( $t['tags'] ) : ?>
                    <span class="vp-events__tags">
                        <?php foreach ( $t['tags'] as $tag ) : ?>
                            <span class="vp-events__tag"><?php echo esc_html( $tag ); ?></span>
                        <?php endforeach; ?>
                    </span>
                <?php endif; ?>
                <span class="vp-events__meta">
                    <?php echo $t['time']; ?>
                    <?php if ( $t['loc'] ) : ?>
                        &middot; <?php echo $t['loc']; ?>
                    <?php endif; ?>
                </span>

                <?php if ( $t['span'] ) : ?>
                    <span class="vp-events__span">
                        <?php echo Icons::svg( 'clock' ); ?>
                        <?php echo $t['span']; ?>
                    </span>
                <?php endif; ?>

        <?php
        if ( $t['allows_rsvp'] ) {
            $this->tile_rsvp( $t, $is_public );
        }
        $this->tile_chips( $t );
        ?>
        </div><?php // /vp-tile__body ?>
        </li>
        <?php
    }

    /**
     * Kopfbereich der Kachel: Bild, sonst Farbfläche des Termintyps.
     * Darüber liegen für das Orgateam die Knöpfe für Titelbild, Flyer und
     * Link – und der Stift für alle, die diesen Termin bearbeiten dürfen.
     */
    private function tile_cover( array $t, bool $can_edit_cover ): void {
        ?>
            <?php // Kopfbereich: Bild, sonst Farbfläche des Termintyps ?>
            <div class="vp-tile__cover<?php echo $t['cover'] ? ' has-image' : ''; ?>"
                 style="<?php echo $t['cover']
                     ? 'background-image:url(' . esc_url( $t['cover']['tile'] ) . ')'
                     : 'background:' . $t['color']; ?>">
                <div class="vp-tile__date<?php echo $t['multi_day'] ? ' is-span' : ''; ?>">
                    <span class="vp-tile__day"><?php echo esc_html( $t['day'] ); ?></span>
                    <span class="vp-tile__month"><?php echo esc_html( $t['month'] ); ?></span>
                </div>

                <?php if ( $can_edit_cover || $t['can_edit'] ) : ?>
                    <div class="vp-tile__coveractions">
                        <?php if ( $t['can_edit'] ) : ?>
                            <button type="button" class="vp-tile__coverbtn" data-edit
                                    title="<?php esc_attr_e( 'Termin bearbeiten', 'vereins-plugin' ); ?>">
                                <?php echo Icons::svg( 'pencil' ); ?>
                            </button>
                        <?php endif; ?>
                        <?php if ( $can_edit_cover ) : ?>
                        <button type="button" class="vp-tile__coverbtn" data-cover-pick
                                title="<?php esc_attr_e( 'Titelbild wählen', 'vereins-plugin' ); ?>">
                            <?php echo Icons::svg( 'image' ); ?>
                        </button>
                        <?php if ( $t['cover'] ) : ?>
                            <button type="button" class="vp-tile__coverbtn" data-cover-remove
                                    title="<?php esc_attr_e( 'Titelbild entfernen', 'vereins-plugin' ); ?>">
                                <?php echo Icons::svg( 'trash' ); ?>
                            </button>
                        <?php endif; ?>
                        <a class="vp-tile__coverbtn" target="_blank" rel="noopener"
                           href="<?php echo esc_url( FlyerPage::url( $t['event_id'], $t['inst_date'] ) ); ?>"
                           title="<?php esc_attr_e( 'Flyer und QR-Code erzeugen', 'vereins-plugin' ); ?>">
                            <?php echo Icons::svg( 'qr' ); ?>
                        </a>
                        <button type="button" class="vp-tile__coverbtn" data-share
                                data-url="<?php echo esc_url( PublicLink::url( get_permalink() ?: home_url( '/' ), $t['event_id'], $t['inst_date'] ) ); ?>"
                                title="<?php esc_attr_e( 'Link zum Teilen kopieren', 'vereins-plugin' ); ?>">
                            <?php echo Icons::svg( 'share' ); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php
    }

    /**
     * Sprungmarke einer Kachel. Der Banner hängt sie an seinen Link, damit
     * angemeldete Mitglieder – die statt des Einzeltermins die ganze Liste
     * sehen – direkt bei der richtigen Kachel landen.
     */
    public static function anchor( int $event_id, string $instance_date ): string {
        return 'vp-termin-' . $event_id . '-' . $instance_date;
    }

    /**
     * Zu- und Absage samt den Zahlen darunter.
     * Über einen öffentlichen Terminlink bleiben die Namen verborgen, der
     * Knopf zum Aufklappen ist dann abgeschaltet.
     */
    private function tile_rsvp( array $t, bool $is_public ): void {
        ?>
            <div class="vp-rsvp">
                <?php if ( ! $t['is_past'] ) : ?>
                <div class="vp-rsvp__buttons">
                    <button type="button" class="vp-rsvp__btn vp-rsvp__btn--yes<?php echo $t['own'] === 'yes' ? ' is-active' : ''; ?>" data-response="yes">
                        <?php esc_html_e( 'Zusagen', 'vereins-plugin' ); ?>
                    </button>
                    <button type="button" class="vp-rsvp__btn vp-rsvp__btn--no<?php echo $t['own'] === 'no' ? ' is-active' : ''; ?>" data-response="no">
                        <?php esc_html_e( 'Absagen', 'vereins-plugin' ); ?>
                    </button>
                <?php // Anzahl steht in $t, gezählt wird sie in prepare_tile(). ?>
                    <span class="vp-rsvp__guests"<?php echo $t['own_guests'] ? '' : ' hidden'; ?>>
                        <?php printf( esc_html__( '+%d', 'vereins-plugin' ), $t['own_guests'] ); ?>
                    </span>
                    <span class="vp-rsvp__status" aria-live="polite"></span>
                </div>
                <?php endif; ?>

                <div class="vp-rsvp__summary">
                    <button type="button" class="vp-rsvp__toggle"<?php echo $is_public ? ' disabled' : ''; ?> title="<?php esc_attr_e( 'Namen anzeigen', 'vereins-plugin' ); ?>">
                        <span class="vp-rsvp__count vp-rsvp__count--yes">
                            <?php
                            printf( esc_html__( 'Zusagen: %d', 'vereins-plugin' ), (int) $t['rsvp']['yes'] );
                            // Begleitpersonen sichtbar machen, sobald welche dabei sind.
                            if ( (int) $t['rsvp']['people'] > (int) $t['rsvp']['yes'] ) {
                                echo ' · ';
                                printf( esc_html__( '%d Personen', 'vereins-plugin' ), (int) $t['rsvp']['people'] );
                            }
                            ?>
                        </span>
                        <span class="vp-rsvp__count vp-rsvp__count--no">
                            <?php printf( esc_html__( 'Absagen: %d', 'vereins-plugin' ), (int) $t['rsvp']['no'] ); ?>
                        </span>
                        <?php if ( $t['needs_helpers'] ) : ?>
                        <span class="vp-rsvp__count vp-rsvp__count--help">
                            <?php printf( esc_html__( 'Helfer: %d', 'vereins-plugin' ), (int) $t['rsvp']['helpers'] ); ?>
                        </span>
                        <?php endif; ?>
                    </button>
                </div>
            </div>
        <?php
    }

    /**
     * Helferaufgaben, Mitbringliste und Fotos als Chip-Reihe – gleiche
     * Formsprache wie die Zu-/Absage-Pills, nur zurückhaltender.
     */
    private function tile_chips( array $t ): void {
        ?>
            <?php
            // Alle Zusatzbereiche als Chip-Reihe – gleiche Formsprache
            // wie die Zu-/Absage-Pills, nur zurückhaltender.
            $show_help  = $t['needs_helpers'] && ! $t['is_past'];
            $show_items = $t['allows_items']  && ! $t['is_past'];
            ?>
            <?php if ( $show_help || $show_items || $t['allows_photos'] ) : ?>
            <div class="vp-chips">
                <?php if ( $show_help ) : ?>
                    <button type="button" class="vp-chip vp-bring__open" data-kind="help">
                        <?php echo Icons::svg( 'user-check' ); ?>
                        <span class="vp-chip__label"><?php esc_html_e( 'Helferaufgaben', 'vereins-plugin' ); ?></span>
                        <span class="vp-chip__stat vp-bring__stat"><?php echo esc_html( $this->stat_label( $t['helps'] ) ); ?></span>
                    </button>
                <?php endif; ?>

                <?php if ( $show_items ) : ?>
                    <button type="button" class="vp-chip vp-bring__open" data-kind="bring">
                        <?php echo Icons::svg( 'package' ); ?>
                        <span class="vp-chip__label"><?php esc_html_e( 'Mitbringliste', 'vereins-plugin' ); ?></span>
                        <span class="vp-chip__stat vp-bring__stat"><?php echo esc_html( $this->stat_label( $t['items'] ) ); ?></span>
                    </button>
                <?php endif; ?>

                <?php if ( $t['allows_photos'] ) : ?>
                    <button type="button" class="vp-chip vp-chip--photos vp-photos__open"
                            data-zip="<?php echo esc_url( PhotoDownload::url( $t['event_id'], $t['inst_date'] ) ); ?>">
                        <?php echo Icons::svg( 'camera' ); ?>
                        <span class="vp-chip__label"><?php esc_html_e( 'Fotos', 'vereins-plugin' ); ?></span>
                        <span class="vp-chip__stat vp-photos__count">
                            <?php
                            echo $t['photo_count'] > 0
                                ? esc_html( sprintf( _n( '%d Foto', '%d Fotos', $t['photo_count'], 'vereins-plugin' ), $t['photo_count'] ) )
                                : esc_html__( 'noch keine', 'vereins-plugin' );
                            ?>
                        </span>
                    </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php
    }

    /**
     * Namen werden erst bei Bedarf geladen und hier gezeigt, damit die Liste
     * bei vielen Rückmeldungen keinen Platz kostet. Bedient von names.js.
     */
    private function overlay_names(): void {
        ?>
        <div class="vp-modal" id="vp-rsvp-modal" hidden>
            <div class="vp-modal__backdrop" data-close></div>
            <div class="vp-modal__box" role="dialog" aria-modal="true" aria-labelledby="vp-modal-title">
                <div class="vp-modal__head">
                    <h3 class="vp-modal__title" id="vp-modal-title"></h3>
                    <button type="button" class="vp-modal__close" data-close aria-label="<?php esc_attr_e( 'Schließen', 'vereins-plugin' ); ?>">&times;</button>
                </div>
                <div class="vp-modal__body">
                    <p class="vp-modal__loading"><?php esc_html_e( 'Lade…', 'vereins-plugin' ); ?></p>
                    <div class="vp-modal__content" hidden>
                        <div class="vp-modal__group">
                            <strong class="vp-modal__label vp-modal__label--yes" data-yes-label></strong>
                            <ul class="vp-modal__names" data-yes-names></ul>
                        </div>
                        <div class="vp-modal__group">
                            <strong class="vp-modal__label vp-modal__label--help" data-help-label></strong>
                            <ul class="vp-modal__names" data-help-names></ul>
                        </div>
                        <div class="vp-modal__group">
                            <strong class="vp-modal__label vp-modal__label--no" data-no-label></strong>
                            <ul class="vp-modal__names" data-no-names></ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Ein Overlay für Mitbringliste und Helferaufgaben; welches von beiden,
     * entscheidet erst der Klick. Bedient von items.js.
     */
    private function overlay_items(): void {
        ?>
        <div class="vp-modal" id="vp-bring-modal" hidden>
            <div class="vp-modal__backdrop" data-close></div>
            <div class="vp-modal__box" role="dialog" aria-modal="true" aria-labelledby="vp-bring-title">
                <div class="vp-modal__head">
                    <h3 class="vp-modal__title" id="vp-bring-title"><?php esc_html_e( 'Mitbringliste', 'vereins-plugin' ); ?></h3>
                    <button type="button" class="vp-modal__close" data-close aria-label="<?php esc_attr_e( 'Schließen', 'vereins-plugin' ); ?>">&times;</button>
                </div>
                <div class="vp-modal__body">
                    <p class="vp-modal__loading"><?php esc_html_e( 'Lade…', 'vereins-plugin' ); ?></p>

                    <div class="vp-bring__content" hidden>
                        <ul class="vp-bring__list" data-bring-list></ul>

                        <div class="vp-bring__add" data-bring-addbox>
                            <strong class="vp-modal__label"><?php esc_html_e( 'Ich bringe etwas mit', 'vereins-plugin' ); ?></strong>
                            <div class="vp-bring__addrow">
                                <input type="text" data-bring-label placeholder="<?php esc_attr_e( 'z. B. Kuchen, Salat, Getränke', 'vereins-plugin' ); ?>">
                                <button type="button" class="vp-bring__addbtn" data-bring-add><?php esc_html_e( 'Eintragen', 'vereins-plugin' ); ?></button>
                            </div>
                        </div>

                        <?php // Nur für Orgateam/Admin: Bedarf bzw. Aufgaben vorgeben ?>
                        <div class="vp-bring__request" data-bring-request hidden>
                            <strong class="vp-modal__label" data-request-head><?php esc_html_e( 'Bedarf vorgeben (Orgateam)', 'vereins-plugin' ); ?></strong>
                            <div class="vp-bring__addrow">
                                <input type="text" data-request-label placeholder="<?php esc_attr_e( 'z. B. Kuchen', 'vereins-plugin' ); ?>">

                                <input type="number" data-request-qty min="1" value="1" title="<?php esc_attr_e( 'Wie viele werden gebraucht?', 'vereins-plugin' ); ?>">
                                <button type="button" class="vp-bring__addbtn" data-request-add><?php esc_html_e( 'Hinzufügen', 'vereins-plugin' ); ?></button>
                            </div>
                        </div>

                        <p class="vp-bring__error" data-bring-error hidden></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Die Kennung wird erst gefragt, wenn tatsächlich etwas gespeichert werden
     * soll – die Entscheidung ist dann schon gefallen. Steht nur beim
     * öffentlichen Terminlink. Bedient von identity.js.
     */
    private function overlay_identity(): void {
        ?>
        <div class="vp-modal" id="vp-ident-modal" hidden>
            <div class="vp-modal__backdrop" data-close></div>
            <div class="vp-modal__box" role="dialog" aria-modal="true" aria-labelledby="vp-ident-title">
                <div class="vp-modal__head">
                    <h3 class="vp-modal__title" id="vp-ident-title"><?php esc_html_e( 'Wer bist du?', 'vereins-plugin' ); ?></h3>
                    <button type="button" class="vp-modal__close" data-close aria-label="<?php esc_attr_e( 'Schließen', 'vereins-plugin' ); ?>">&times;</button>
                </div>
                <div class="vp-modal__body">
                    <div class="vp-ident__field">
                        <strong class="vp-modal__label"><?php esc_html_e( 'Dein Name', 'vereins-plugin' ); ?></strong>
                        <div class="vp-bring__addrow">
                            <input type="text" id="vp-ident-name" autocomplete="name"
                                   placeholder="<?php esc_attr_e( 'z. B. Anna Berger', 'vereins-plugin' ); ?>">
                        </div>
                        <p class="vp-people__note">
                            <?php esc_html_e( 'Dein Name erscheint in der Teilnehmerliste dieses Termins, damit die Organisatoren wissen, wer kommt. Anmelden musst du dich dafür nicht.', 'vereins-plugin' ); ?>
                        </p>
                    </div>

                    <div class="vp-ident__field">
                        <strong class="vp-modal__label">
                            <?php esc_html_e( 'E-Mail-Adresse', 'vereins-plugin' ); ?>
                            <span class="vp-ident__optional"><?php esc_html_e( 'freiwillig', 'vereins-plugin' ); ?></span>
                        </strong>
                        <div class="vp-bring__addrow">
                            <input type="email" id="vp-ident-email" autocomplete="email"
                                   placeholder="<?php esc_attr_e( 'name@beispiel.de', 'vereins-plugin' ); ?>">
                        </div>
                        <p class="vp-people__note">
                            <?php esc_html_e( 'Nur nötig, wenn du bei Änderungen an diesem Termin und bei neuen Terminen benachrichtigt werden möchtest. Ohne E-Mail bleibt deine Zusage genauso gültig.', 'vereins-plugin' ); ?>
                        </p>
                    </div>

                    <p class="vp-bring__error" data-ident-error hidden></p>

                    <div class="vp-ident__actions">
                        <button type="button" class="vp-bring__addbtn" data-ident-save>
                            <?php echo Icons::svg( 'user-check' ); ?>
                            <?php esc_html_e( 'Weiter', 'vereins-plugin' ); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Wer kommt mit? Bedient von people.js.
     */
    /**
     * Termin bearbeiten – derselbe Feldersatz wie [vp_create_event].
     *
     * Steht einmal je Seite und ist leer, bis jemand auf den Stift einer
     * Kachel klickt; gefüllt wird über GET /events/{id}. Bedient von
     * edit.js.
     *
     * Gehört ein Termin zu einer Serie, fragt der Dialog vor dem Speichern
     * nach, ob nur dieser Termin oder die ganze Serie gemeint ist – wie man
     * es aus Kalenderprogrammen kennt. Beides landet an verschiedenen
     * Stellen: die einzelne Instanz als Ausnahme, die Serie am Stammsatz.
     */
    private function overlay_edit(): void {
        $types = ( new EventTypeModel() )->all();
        ?>
        <div class="vp-modal" id="vp-edit-modal" hidden>
            <div class="vp-modal__backdrop" data-close></div>
            <div class="vp-modal__box vp-modal__box--form" role="dialog" aria-modal="true" aria-labelledby="vp-edit-title">
                <div class="vp-modal__head">
                    <h3 class="vp-modal__title" id="vp-edit-title"><?php esc_html_e( 'Termin bearbeiten', 'vereins-plugin' ); ?></h3>
                    <button type="button" class="vp-modal__close" data-close aria-label="<?php esc_attr_e( 'Schließen', 'vereins-plugin' ); ?>">&times;</button>
                </div>
                <div class="vp-modal__body">
                    <p class="vp-modal__loading" data-edit-loading><?php esc_html_e( 'Lade…', 'vereins-plugin' ); ?></p>

                    <form class="vp-form vp-form-wrap" data-edit-form novalidate hidden>

                        <?php // Nur bei Serien; edit.js blendet es ein. ?>
                        <div class="vp-edit__scope" data-edit-scope hidden>
                            <strong><?php esc_html_e( 'Dieser Termin gehört zu einer Serie.', 'vereins-plugin' ); ?></strong>
                            <label>
                                <input type="radio" name="scope" value="instance" checked>
                                <span>
                                    <?php esc_html_e( 'Nur diesen Termin', 'vereins-plugin' ); ?>
                                    <small><?php esc_html_e( 'Die übrigen Termine der Serie bleiben unverändert. Termintyp und Wiederholung lassen sich dabei nicht ändern.', 'vereins-plugin' ); ?></small>
                                </span>
                            </label>
                            <label>
                                <input type="radio" name="scope" value="series">
                                <span>
                                    <?php esc_html_e( 'Die ganze Serie', 'vereins-plugin' ); ?>
                                    <small><?php esc_html_e( 'Gilt für alle Termine der Serie, auch für die bereits vergangenen.', 'vereins-plugin' ); ?></small>
                                </span>
                            </label>
                        </div>

                        <div class="vp-edit__fields" data-edit-fields>
                            <?php EventForm::felder( $types, 'vp-edit-' ); ?>
                        </div>

                        <div class="vp-form__msg" data-edit-msg aria-live="polite"></div>

                        <div class="vp-edit__foot">
                            <button type="submit" class="vp-btn" data-edit-save><?php esc_html_e( 'Änderungen speichern', 'vereins-plugin' ); ?></button>
                            <button type="button" class="vp-edit__delete" data-edit-delete><?php esc_html_e( 'Termin löschen', 'vereins-plugin' ); ?></button>
                        </div>

                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    private function overlay_people(): void {
        ?>
        <div class="vp-modal" id="vp-people-modal" hidden>
            <div class="vp-modal__backdrop" data-close></div>
            <div class="vp-modal__box" role="dialog" aria-modal="true" aria-labelledby="vp-people-title">
                <div class="vp-modal__head">
                    <h3 class="vp-modal__title" id="vp-people-title"><?php esc_html_e( 'Wer kommt mit?', 'vereins-plugin' ); ?></h3>
                    <button type="button" class="vp-modal__close" data-close aria-label="<?php esc_attr_e( 'Schließen', 'vereins-plugin' ); ?>">&times;</button>
                </div>
                <div class="vp-modal__body">
                    <p class="vp-modal__loading"><?php esc_html_e( 'Lade…', 'vereins-plugin' ); ?></p>

                    <div class="vp-people__content" hidden>
                        <?php // Eine Zeile je Person, gleiche Pill-Buttons wie am Termin ?>
                        <ul class="vp-bring__list" data-people-list></ul>

                        <div class="vp-bring__add">
                            <strong class="vp-modal__label"><?php esc_html_e( 'Weitere Person', 'vereins-plugin' ); ?></strong>
                            <div class="vp-bring__addrow">
                                <input type="text" data-people-new placeholder="<?php esc_attr_e( 'z. B. Mia', 'vereins-plugin' ); ?>">
                                <button type="button" class="vp-bring__addbtn" data-people-add><?php esc_html_e( 'Hinzufügen', 'vereins-plugin' ); ?></button>
                            </div>
                            <p class="description vp-people__note">
                                <?php esc_html_e( 'Einmal eingetragen, steht die Person beim nächsten Termin gleich zur Auswahl.', 'vereins-plugin' ); ?>
                            </p>
                        </div>

                        <p class="vp-bring__error" data-people-error hidden></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Hochladen und Ansehen der Fotos. Bedient von photos.js.
     */
    private function overlay_photos(): void {
        ?>
        <div class="vp-modal" id="vp-photos-modal" hidden>
            <div class="vp-modal__backdrop" data-close></div>
            <div class="vp-modal__box vp-modal__box--wide" role="dialog" aria-modal="true" aria-labelledby="vp-photos-title">
                <div class="vp-modal__head">
                    <h3 class="vp-modal__title" id="vp-photos-title"><?php esc_html_e( 'Fotos', 'vereins-plugin' ); ?></h3>
                    <button type="button" class="vp-modal__close" data-close aria-label="<?php esc_attr_e( 'Schließen', 'vereins-plugin' ); ?>">&times;</button>
                </div>
                <div class="vp-modal__body">
                    <p class="vp-modal__loading"><?php esc_html_e( 'Lade…', 'vereins-plugin' ); ?></p>

                    <div class="vp-photos__content" hidden>
                        <?php
                        // Pflicht-Zustimmung: ohne Häkchen bleibt die Auswahl
                        // gesperrt. Der Wert geht bei jedem Upload als
                        // consent-Feld mit und wird serverseitig je Foto mit
                        // Zeitstempel festgehalten.
                        ?>
                        <label class="vp-photos__consent" data-photos-consent-label>
                            <input type="checkbox" id="vp-photos-consent" data-photos-consent>
                            <?php esc_html_e( 'Achtung, hiermit bestätigst du, dass das Foto auf der Website und in sozialen Medien verwendet werden kann.', 'vereins-plugin' ); ?>
                        </label>

                        <?php
                        // Upload: multiple + accept öffnet auf dem Handy direkt
                        // die Fotos-App mit Mehrfachauswahl.
                        ?>
                        <div class="vp-photos__drop is-disabled" data-photos-drop>
                            <input type="file" id="vp-photos-input" accept="image/*" multiple hidden disabled>
                            <label for="vp-photos-input" class="vp-photos__droplabel">
                                <strong>
                                    <?php echo Icons::svg( 'upload' ); ?>
                                    <?php esc_html_e( 'Fotos auswählen', 'vereins-plugin' ); ?>
                                </strong>
                                <span data-photos-drophint><?php esc_html_e( 'Bitte zuerst der Nutzung zustimmen', 'vereins-plugin' ); ?></span>
                            </label>
                        </div>

                        <div class="vp-photos__progress" data-photos-progress hidden>
                            <div class="vp-photos__bar"><span data-photos-bar></span></div>
                            <span class="vp-photos__progresstext" data-photos-progresstext></span>
                        </div>

                        <p class="vp-photos__closed" data-photos-closed hidden></p>
                        <p class="vp-photos__quota" data-photos-quota></p>
                        <p class="vp-bring__error" data-photos-error hidden></p>

                        <div class="vp-photos__grid" data-photos-grid></div>

                        <p class="vp-photos__actions">
                            <a class="vp-bring__addbtn" data-photos-zip href="#" hidden>
                                <?php echo Icons::svg( 'download' ); ?>
                                <?php esc_html_e( 'Alle als ZIP herunterladen', 'vereins-plugin' ); ?>
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }


    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Datum, unter dem die Antworten zu dieser Instanz gespeichert werden.
     * Bei Serien liefert der Expander 'original_date', einmalige Termine
     * nutzen ihr Startdatum.
     */
    private function instance_date( array $event ): string {
        return $event['original_date'] ?? substr( $event['start_at'], 0, 10 );
    }

    /**
     * Kurzstatus für die Buttons: "3 offen" / "alles vergeben" / "noch leer".
     */
    private function stat_label( array $stats ): string {
        if ( $stats['open'] > 0 ) {
            return sprintf( __( '%d offen', 'vereins-plugin' ), (int) $stats['open'] );
        }
        return $stats['items'] > 0
            ? __( 'alles vergeben', 'vereins-plugin' )
            : __( 'noch leer', 'vereins-plugin' );
    }


    /**
     * Läuft auf wp_enqueue_scripts und meldet nur an – ausgegeben wird nichts.
     *
     * Geladen wird erst, wenn der Shortcode tatsächlich etwas rendert. Das
     * frühere Abklopfen von `$post->post_content` entfällt damit; es griff
     * ohnehin nicht, wenn der Shortcode aus einem Block oder Widget kam.
     */
    public function enqueue_assets(): void {
        self::register_assets();
    }

    /**
     * Fordert Stil und Skript an und legt dem Skript die Werte aus PHP bei.
     *
     * Die Werte stehen erst beim Rendern fest – Link-Schlüssel, Gast-Kennung
     * und der erste anstehende Termin hängen am Aufruf. Deshalb wird das
     * Skript im Fußbereich ausgeliefert und die Angaben davor eingesetzt.
     *
     * @param array $upcoming Anstehende Termine; der erste dient dem
     *                        Kennungs-Dialog des öffentlichen Terminlinks.
     */
    private function enqueue_for_list(
        string $api,
        string $nonce,
        string $link_key,
        bool $is_public,
        int $user_id,
        array $upcoming,
        bool $kann_bearbeiten = false
    ): void {
        // Sonst hat der Aufruf keinen Anker: Wird der Shortcode gerendert,
        // ohne dass wp_enqueue_scripts lief, ist noch nichts angemeldet.
        self::register_assets();

        wp_enqueue_style( self::STYLE );

        // Der Bearbeiten-Dialog zeigt dieselben Felder wie [vp_create_event]
        // und braucht deshalb denselben Stil.
        if ( $kann_bearbeiten ) {
            EventForm::enqueue_style();
        }

        foreach ( self::SCRIPTS as $modul ) {
            wp_enqueue_script( self::handle( $modul ) );
        }

        // Mehrere Terminlisten auf einer Seite teilen sich ein Skript; das
        // Skript bedient ohnehin nur die erste. Also auch nur einmal beilegen.
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;

        $config = [
            'api'       => $api,
            'nonce'     => $nonce,
            'key'       => $link_key,
            // Ob der Gast schon eine Kennung hinterlegt hat.
            'known'     => ! $is_public || (bool) $user_id,
            'iconAlert' => Icons::svg( 'alert' ),
            'identify'  => [
                'id'           => (int) ( $upcoming[0]['event_id'] ?? 0 ),
                'instanceDate' => $upcoming ? $this->instance_date( $upcoming[0] ) : '',
            ],
            'canEdit'   => $kann_bearbeiten,
            't'         => self::script_texts(),
        ];

        // JSON_HEX_TAG: Das Alarm-Symbol ist SVG. Ohne die Maskierung stünde
        // eine spitze Klammer wörtlich im <script>-Element – JavaScript liest
        // < genauso, der Parser des Browsers kann daran aber nicht mehr
        // stolpern.
        wp_add_inline_script(
            self::handle( 'core' ),
            'window.VP_EVENTS = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
            'before'
        );
    }

    /**
     * Die Teile von assets/js/events/ in Ladereihenfolge.
     *
     * `core` legt window.vpEvents an, alle übrigen bauen darauf auf. Weiter
     * verzahnt sind sie nicht: Wo ein Modul etwas aus einem anderen braucht,
     * wird erst beim Klick nachgeschlagen. Die Reihenfolge hier ist deshalb
     * nur Lesbarkeit, keine Bedingung – bis auf `core` zuerst.
     */
    private const SCRIPTS = [
        'core',      // gemeinsame Werte, Kopfzeilen, Ablage
        'names',     // Overlay mit den Namen
        'rsvp',      // Zusage / Absage / Helfen
        'items',     // Mitbringliste & Helferaufgaben
        'identity',  // Kennung ohne Login
        'tile',      // Link teilen, Titelbild
        'edit',      // Termin bearbeiten und löschen
        'people',    // Begleitpersonen
        'photos',    // Fotogalerie
    ];

    private const STYLE = 'vp-events';

    /**
     * Meldet Stil und Skriptdateien an. Mehrfaches Aufrufen ist unschädlich.
     */
    private static function register_assets(): void {
        if ( wp_script_is( self::handle( 'core' ), 'registered' ) ) {
            return;
        }

        wp_register_style(
            self::STYLE,
            plugins_url( 'assets/css/events.css', VP_PLUGIN_FILE ),
            [],
            VP_VERSION
        );

        foreach ( self::SCRIPTS as $modul ) {
            wp_register_script(
                self::handle( $modul ),
                plugins_url( "assets/js/events/{$modul}.js", VP_PLUGIN_FILE ),
                'core' === $modul ? [] : [ self::handle( 'core' ) ],
                VP_VERSION,
                true
            );
        }
    }

    private static function handle( string $modul ): string {
        return 'vp-events-' . $modul;
    }

    /**
     * Textbausteine für die Dateien in assets/js/events/.
     *
     * Dort heißen sie T.<schlüssel>. Ein fehlender Schlüssel erscheint im
     * Browser als "undefined", deshalb hält `tools/check.php` diese Liste
     * gegen die Verwendungen in den Skripten.
     */
    public static function script_texts(): array {
        return [
            'loading'       => __( 'Lade…', 'vereins-plugin' ),
            'yes'           => __( 'Zusagen: %d', 'vereins-plugin' ),
            'no'            => __( 'Absagen: %d', 'vereins-plugin' ),
            'none'          => __( 'Niemand', 'vereins-plugin' ),
            'error'         => __( 'Konnte nicht gespeichert werden.', 'vereins-plugin' ),
            'saving'        => __( 'Speichere…', 'vereins-plugin' ),
            'yesLabel'      => __( 'Zugesagt (%d)', 'vereins-plugin' ),
            'noLabel'       => __( 'Abgesagt (%d)', 'vereins-plugin' ),
            'helpCount'     => __( 'Helfer: %d', 'vereins-plugin' ),
            'helpLabel'     => __( 'Packen mit an (%d)', 'vereins-plugin' ),
            'loadError'     => __( 'Namen konnten nicht geladen werden.', 'vereins-plugin' ),
            'open'          => __( '%d offen', 'vereins-plugin' ),
            'allTaken'      => __( 'alles vergeben', 'vereins-plugin' ),
            'listEmpty'     => __( 'noch leer', 'vereins-plugin' ),
            'noItems'       => __( 'Noch nichts eingetragen.', 'vereins-plugin' ),
            'noTasks'       => __( 'Noch keine Aufgaben festgelegt.', 'vereins-plugin' ),
            'titleBring'    => __( 'Mitbringliste', 'vereins-plugin' ),
            'titleHelp'     => __( 'Helferaufgaben', 'vereins-plugin' ),
            'headBring'     => __( 'Bedarf vorgeben (Orgateam)', 'vereins-plugin' ),
            'headHelp'      => __( 'Aufgabe hinzufügen (Orgateam)', 'vereins-plugin' ),
            'phBring'       => __( 'z. B. Kuchen', 'vereins-plugin' ),
            'phHelp'        => __( 'z. B. Auf- und Abbau, Catering, Einlass', 'vereins-plugin' ),
            'take'          => __( 'Übernehmen', 'vereins-plugin' ),
            'takeFor'       => __( 'Für %s übernehmen', 'vereins-plugin' ),
            'release'       => __( 'Zurückziehen', 'vereins-plugin' ),
            'remove'        => __( 'Löschen', 'vereins-plugin' ),
            'openOf'        => __( '%1$d von %2$d offen', 'vereins-plugin' ),
            'covered'       => __( 'vollständig', 'vereins-plugin' ),
            'noPhotos'      => __( 'Noch keine Fotos zu diesem Termin.', 'vereins-plugin' ),
            'photoOne'      => __( '1 Foto', 'vereins-plugin' ),
            'photoMany'     => __( '%d Fotos', 'vereins-plugin' ),
            'noneYet'       => __( 'noch keine', 'vereins-plugin' ),
            'quota'         => __( 'Dein Speicher für diesen Termin: %1$s von %2$s', 'vereins-plugin' ),
            'quotaFree'     => __( 'Von dir hochgeladen: %s', 'vereins-plugin' ),
            'uploading'     => __( '%1$d von %2$d hochgeladen', 'vereins-plugin' ),
            'uploadClosed'  => __( 'Der Upload-Zeitraum ist am %s abgelaufen. Die Sammlung ist damit vollständig und lässt sich als ZIP herunterladen.', 'vereins-plugin' ),
            'uploadOpen'    => __( 'Noch %1$d Tage Zeit zum Hochladen – bis zum %2$s.', 'vereins-plugin' ),
            'uploadLastDay' => __( 'Letzter Tag zum Hochladen!', 'vereins-plugin' ),
            'people'        => __( '%d Personen', 'vereins-plugin' ),
            'forget'        => __( 'Dauerhaft aus meiner Liste entfernen', 'vereins-plugin' ),
            'selfComing'    => __( 'Ich', 'vereins-plugin' ),
            'comingYes'     => __( 'Dabei', 'vereins-plugin' ),
            'comingNo'      => __( 'Nicht dabei', 'vereins-plugin' ),
            'viaMember'     => __( ' (Begleitung von %s)', 'vereins-plugin' ),
            'linkCopied'    => __( 'Link kopiert', 'vereins-plugin' ),
            'linkCopy'      => __( 'Link zum Teilen:', 'vereins-plugin' ),
            'unconfirmed'   => __( 'Kontakt noch nicht bestätigt', 'vereins-plugin' ),
            'nameNeeded'    => __( 'Bitte gib deinen Namen an.', 'vereins-plugin' ),
            'dropReady'     => __( 'oder hierher ziehen', 'vereins-plugin' ),
            'dropLocked'    => __( 'Bitte zuerst der Nutzung zustimmen', 'vereins-plugin' ),
            'consentNeeded' => __( 'Bitte bestätige zuerst, dass das Foto auf der Website und in sozialen Medien verwendet werden kann.', 'vereins-plugin' ),
            'editSave'      => __( 'Änderungen speichern', 'vereins-plugin' ),
            'editSaved'     => __( 'Gespeichert.', 'vereins-plugin' ),
            'editLoadError' => __( 'Der Termin konnte nicht geladen werden.', 'vereins-plugin' ),
            'editDeleteAsk' => __( 'Diesen Termin endgültig löschen? Mit ihm verschwinden Zusagen, Mitbringliste und Helferaufgaben.', 'vereins-plugin' ),
            'editSeriesAsk' => __( 'Die ganze Serie löschen? Damit verschwinden alle Termine dieser Serie samt Zusagen.', 'vereins-plugin' ),
            'editCancelAsk' => __( 'Diesen einzelnen Termin absagen? Die übrige Serie bleibt bestehen.', 'vereins-plugin' ),
            'editDelete'    => __( 'Termin löschen', 'vereins-plugin' ),
            'editCancel'    => __( 'Diesen Termin absagen', 'vereins-plugin' ),
        ];
    }
}
