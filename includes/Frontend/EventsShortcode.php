<?php

namespace VereinsPlugin\Frontend;

use VereinsPlugin\Events\InstanceLocator;
use VereinsPlugin\Flyer\FlyerPage;
use VereinsPlugin\Events\AttendanceModel;
use VereinsPlugin\Events\CompanionModel;
use VereinsPlugin\Events\EventModel;
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
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
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
     * @param string $link_key  Gesetzt, wenn die Liste über einen öffentlichen
     *                          Terminlink aufgerufen wurde. Dann sind Namen
     *                          anderer verborgen und Aktionen fragen zuerst
     *                          nach einer Kennung.
     */
    private function render_list( array $upcoming, array $past = [], string $link_key = '' ): string {
        $is_public = $link_key !== '';
        $user_id   = $is_public ? PublicLink::current_guest() : get_current_user_id();
        $instances = array_merge( $upcoming, $past );

        // Nur Zahlen + eigene Antwort laden – die Namen holt erst das Overlay
        // bei Bedarf nach (siehe unten), damit die Seite schlank bleibt.
        $keys = [];
        foreach ( $instances as $event ) {
            $keys[] = [
                'event_id'      => (int) $event['event_id'],
                'instance_date' => $this->instance_date( $event ),
            ];
        }
        $item_model = new ItemModel();
        $attendance = ( new AttendanceModel() )->summary_for_instances( $keys, $user_id );
        $item_stats  = $item_model->summary_for_instances( $keys, 'bring' );
        $help_stats  = $item_model->summary_for_instances( $keys, 'help' );
        $photo_stats = ( new PhotoModel() )->counts_for_instances( $keys );

        // Eigene Begleitpersonen je Instanz – für den Zähler am Button.
        $companion_model = new CompanionModel();
        $own_companions  = [];
        foreach ( $keys as $k ) {
            $own_companions[ $k['event_id'] . '|' . $k['instance_date'] ] =
                $companion_model->for_attendance( $k['event_id'], $k['instance_date'], $user_id );
        }

        // Titelbilder pflegt das Orgateam direkt an der Kachel.
        $can_edit_cover = ! $is_public && Capabilities::is_orga();

        $api   = esc_url( rest_url( 'vereins-plugin/v1' ) );
        $nonce = wp_create_nonce( 'wp_rest' );

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
                    $is_past = $index >= $past_starts_at;
                    $color   = esc_attr( $event['type_color'] ?? '#3B82F6' );
                    $type    = esc_html( $event['type_name']  ?? '' );
                    $title   = esc_html( $event['title'] );
                    $loc     = $event['location'] ? esc_html( $event['location'] ) : '';

                    // Mehrtägige Termine – etwa ein Camp von Freitag 16 Uhr
                    // bis Sonntag 17 Uhr – brauchen beide Eckpunkte.
                    $multi_day = DateRange::is_multi_day( $event['start_at'], $event['end_at'] );
                    $badge     = DateRange::badge( $event['start_at'], $event['end_at'] );
                    $day       = $badge['day'];
                    $month     = $badge['month'];
                    $time      = esc_html( DateRange::short( $event['start_at'], $event['end_at'], (bool) $event['is_full_day'] ) );
                    $span      = $multi_day
                                 ? esc_html( DateRange::detail( $event['start_at'], $event['end_at'], (bool) $event['is_full_day'] ) )
                                 : '';

                    $event_id  = (int) $event['event_id'];
                    $inst_date = $this->instance_date( $event );
                    $key       = $event_id . '|' . $inst_date;
                    $rsvp      = $attendance[ $key ] ?? AttendanceModel::empty_summary();
                    $own       = $rsvp['own'];
                    $items     = $item_stats[ $key ] ?? [ 'items' => 0, 'open' => 0 ];
                    $helps     = $help_stats[ $key ] ?? [ 'items' => 0, 'open' => 0 ];

                    // Was der Termintyp erlaubt, entscheidet über die Bereiche.
                    $allows_items  = ! empty( $event['allows_items'] );
                    $needs_helpers = ! empty( $event['needs_helpers'] );
                    // Fotos bleiben angemeldeten Mitgliedern vorbehalten.
                    $allows_photos = ! $is_public && ! empty( $event['allows_photos'] );
                    $photo_count   = $photo_stats[ $key ] ?? 0;
                    $cover         = PhotoService::cover_urls( (int) ( $event['image_attachment_id'] ?? 0 ) );
                    ?>
                    <li class="vp-events__item<?php echo $is_past ? ' is-past' : ''; ?>"
                        data-event-id="<?php echo esc_attr( $event_id ); ?>"
                        data-instance-date="<?php echo esc_attr( $inst_date ); ?>">

                        <?php // Kopfbereich: Bild, sonst Farbfläche des Termintyps ?>
                        <div class="vp-tile__cover<?php echo $cover ? ' has-image' : ''; ?>"
                             style="<?php echo $cover
                                 ? 'background-image:url(' . esc_url( $cover['tile'] ) . ')'
                                 : 'background:' . $color; ?>">
                            <div class="vp-tile__date<?php echo $multi_day ? ' is-span' : ''; ?>">
                                <span class="vp-tile__day"><?php echo esc_html( $day ); ?></span>
                                <span class="vp-tile__month"><?php echo esc_html( $month ); ?></span>
                            </div>

                            <?php if ( $can_edit_cover ) : ?>
                                <div class="vp-tile__coveractions">
                                    <button type="button" class="vp-tile__coverbtn" data-cover-pick
                                            title="<?php esc_attr_e( 'Titelbild wählen', 'vereins-plugin' ); ?>">
                                        <?php echo Icons::svg( 'image' ); ?>
                                    </button>
                                    <?php if ( $cover ) : ?>
                                        <button type="button" class="vp-tile__coverbtn" data-cover-remove
                                                title="<?php esc_attr_e( 'Titelbild entfernen', 'vereins-plugin' ); ?>">
                                            <?php echo Icons::svg( 'trash' ); ?>
                                        </button>
                                    <?php endif; ?>
                                    <a class="vp-tile__coverbtn" target="_blank" rel="noopener"
                                       href="<?php echo esc_url( FlyerPage::url( $event_id, $inst_date ) ); ?>"
                                       title="<?php esc_attr_e( 'Flyer und QR-Code erzeugen', 'vereins-plugin' ); ?>">
                                        <?php echo Icons::svg( 'qr' ); ?>
                                    </a>
                                    <button type="button" class="vp-tile__coverbtn" data-share
                                            data-url="<?php echo esc_url( PublicLink::url( get_permalink() ?: home_url( '/' ), $event_id, $inst_date ) ); ?>"
                                            title="<?php esc_attr_e( 'Link zum Teilen kopieren', 'vereins-plugin' ); ?>">
                                        <?php echo Icons::svg( 'share' ); ?>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="vp-tile__body">
                            <?php if ( $type ) : ?>
                                <span class="vp-events__type" style="color:<?php echo $color; ?>"><?php echo $type; ?></span>
                            <?php endif; ?>
                            <span class="vp-events__title"><?php echo $title; ?></span>
                            <span class="vp-events__meta">
                                <?php echo $time; ?>
                                <?php if ( $loc ) : ?>
                                    &middot; <?php echo $loc; ?>
                                <?php endif; ?>
                            </span>

                            <?php if ( $span ) : ?>
                                <span class="vp-events__span">
                                    <?php echo Icons::svg( 'clock' ); ?>
                                    <?php echo $span; ?>
                                </span>
                            <?php endif; ?>

                        <div class="vp-rsvp">
                            <?php if ( ! $is_past ) : ?>
                            <div class="vp-rsvp__buttons">
                                <button type="button" class="vp-rsvp__btn vp-rsvp__btn--yes<?php echo $own === 'yes' ? ' is-active' : ''; ?>" data-response="yes">
                                    <?php esc_html_e( 'Zusagen', 'vereins-plugin' ); ?>
                                </button>
                                <button type="button" class="vp-rsvp__btn vp-rsvp__btn--no<?php echo $own === 'no' ? ' is-active' : ''; ?>" data-response="no">
                                    <?php esc_html_e( 'Absagen', 'vereins-plugin' ); ?>
                                </button>
                                <?php $own_guests = count( $own_companions[ $key ] ?? [] ); ?>
                                <span class="vp-rsvp__guests"<?php echo $own_guests ? '' : ' hidden'; ?>>
                                    <?php printf( esc_html__( '+%d', 'vereins-plugin' ), $own_guests ); ?>
                                </span>
                                <span class="vp-rsvp__status" aria-live="polite"></span>
                            </div>
                            <?php endif; ?>

                            <div class="vp-rsvp__summary">
                                <button type="button" class="vp-rsvp__toggle"<?php echo $is_public ? ' disabled' : ''; ?> title="<?php esc_attr_e( 'Namen anzeigen', 'vereins-plugin' ); ?>">
                                    <span class="vp-rsvp__count vp-rsvp__count--yes">
                                        <?php
                                        printf( esc_html__( 'Zusagen: %d', 'vereins-plugin' ), (int) $rsvp['yes'] );
                                        // Begleitpersonen sichtbar machen, sobald welche dabei sind.
                                        if ( (int) $rsvp['people'] > (int) $rsvp['yes'] ) {
                                            echo ' · ';
                                            printf( esc_html__( '%d Personen', 'vereins-plugin' ), (int) $rsvp['people'] );
                                        }
                                        ?>
                                    </span>
                                    <span class="vp-rsvp__count vp-rsvp__count--no">
                                        <?php printf( esc_html__( 'Absagen: %d', 'vereins-plugin' ), (int) $rsvp['no'] ); ?>
                                    </span>
                                    <?php if ( $needs_helpers ) : ?>
                                    <span class="vp-rsvp__count vp-rsvp__count--help">
                                        <?php printf( esc_html__( 'Helfer: %d', 'vereins-plugin' ), (int) $rsvp['helpers'] ); ?>
                                    </span>
                                    <?php endif; ?>
                                </button>
                            </div>
                        </div>

                        <?php
                        // Alle Zusatzbereiche als Chip-Reihe – gleiche Formsprache
                        // wie die Zu-/Absage-Pills, nur zurückhaltender.
                        $show_help  = $needs_helpers && ! $is_past;
                        $show_items = $allows_items  && ! $is_past;
                        ?>
                        <?php if ( $show_help || $show_items || $allows_photos ) : ?>
                        <div class="vp-chips">
                            <?php if ( $show_help ) : ?>
                                <button type="button" class="vp-chip vp-bring__open" data-kind="help">
                                    <?php echo Icons::svg( 'user-check' ); ?>
                                    <span class="vp-chip__label"><?php esc_html_e( 'Helferaufgaben', 'vereins-plugin' ); ?></span>
                                    <span class="vp-chip__stat vp-bring__stat"><?php echo esc_html( $this->stat_label( $helps ) ); ?></span>
                                </button>
                            <?php endif; ?>

                            <?php if ( $show_items ) : ?>
                                <button type="button" class="vp-chip vp-bring__open" data-kind="bring">
                                    <?php echo Icons::svg( 'package' ); ?>
                                    <span class="vp-chip__label"><?php esc_html_e( 'Mitbringliste', 'vereins-plugin' ); ?></span>
                                    <span class="vp-chip__stat vp-bring__stat"><?php echo esc_html( $this->stat_label( $items ) ); ?></span>
                                </button>
                            <?php endif; ?>

                            <?php if ( $allows_photos ) : ?>
                                <button type="button" class="vp-chip vp-chip--photos vp-photos__open"
                                        data-zip="<?php echo esc_url( PhotoDownload::url( $event_id, $inst_date ) ); ?>">
                                    <?php echo Icons::svg( 'camera' ); ?>
                                    <span class="vp-chip__label"><?php esc_html_e( 'Fotos', 'vereins-plugin' ); ?></span>
                                    <span class="vp-chip__stat vp-photos__count">
                                        <?php
                                        echo $photo_count > 0
                                            ? esc_html( sprintf( _n( '%d Foto', '%d Fotos', $photo_count, 'vereins-plugin' ), $photo_count ) )
                                            : esc_html__( 'noch keine', 'vereins-plugin' );
                                        ?>
                                    </span>
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        </div><?php // /vp-tile__body ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Namen werden erst bei Bedarf geladen und im Overlay gezeigt,
             damit die Liste bei vielen Rückmeldungen keinen Platz kostet. -->
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

        <!-- Mitbringliste -->
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

                        <!-- Nur für Orgateam/Admin: Bedarf bzw. Aufgaben vorgeben -->
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

        <?php if ( $can_edit_cover ) : ?>
            <input type="file" id="vp-cover-input" accept="image/*" hidden>
        <?php endif; ?>

        <?php if ( $is_public ) : ?>
        <!-- Kennung: wird erst gefragt, wenn tatsächlich etwas gespeichert
             werden soll – die Entscheidung ist dann schon gefallen. -->
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
        <?php endif; ?>

        <!-- Begleitpersonen -->
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
                        <!-- Eine Zeile je Person, gleiche Pill-Buttons wie am Termin -->
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

        <!-- Fotogalerie -->
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
                        <!-- Pflicht-Zustimmung: ohne Häkchen bleibt die Auswahl gesperrt.
                             Wird bei jedem Upload als consent-Feld mitgeschickt und
                             serverseitig je Foto mit Zeitstempel festgehalten. -->
                        <label class="vp-photos__consent" data-photos-consent-label>
                            <input type="checkbox" id="vp-photos-consent" data-photos-consent>
                            <?php esc_html_e( 'Achtung, hiermit bestätigst du, dass das Foto auf der Website und in sozialen Medien verwendet werden kann.', 'vereins-plugin' ); ?>
                        </label>

                        <!-- Upload: multiple + accept öffnet auf dem Handy direkt
                             die Fotos-App mit Mehrfachauswahl -->
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

        <script>
        (function(){
            var root  = document.getElementById('vp-events');
            if (!root) { return; }
            var API   = <?php echo json_encode( $api ); ?>;
            var NONCE = <?php echo json_encode( $nonce ); ?>;
            var KEY    = <?php echo json_encode( $link_key ); ?>;
            var PUBLIC = <?php echo json_encode( $is_public ); ?>;
            // Ob der Gast schon eine Kennung hinterlegt hat.
            var KNOWN  = <?php echo json_encode( ! $is_public || (bool) $user_id ); ?>;

            /** Kopfzeilen für alle Anfragen – im öffentlichen Modus mit Link-Schlüssel. */
            function H(json) {
                var h = { 'X-WP-Nonce': NONCE };
                if (json)  { h['Content-Type'] = 'application/json'; }
                if (KEY)   { h['X-VP-Key'] = KEY; }
                return h;
            }
            var T = {
                yes:      <?php echo json_encode( __( 'Zusagen: %d', 'vereins-plugin' ) ); ?>,
                no:       <?php echo json_encode( __( 'Absagen: %d', 'vereins-plugin' ) ); ?>,
                none:     <?php echo json_encode( __( 'Niemand', 'vereins-plugin' ) ); ?>,
                error:    <?php echo json_encode( __( 'Konnte nicht gespeichert werden.', 'vereins-plugin' ) ); ?>,
                saving:   <?php echo json_encode( __( 'Speichere…', 'vereins-plugin' ) ); ?>,
                yesLabel: <?php echo json_encode( __( 'Zugesagt (%d)', 'vereins-plugin' ) ); ?>,
                noLabel:  <?php echo json_encode( __( 'Abgesagt (%d)', 'vereins-plugin' ) ); ?>,
                helpCount:<?php echo json_encode( __( 'Helfer: %d', 'vereins-plugin' ) ); ?>,
                helpLabel:<?php echo json_encode( __( 'Packen mit an (%d)', 'vereins-plugin' ) ); ?>,
                loadError:<?php echo json_encode( __( 'Namen konnten nicht geladen werden.', 'vereins-plugin' ) ); ?>,
                open:     <?php echo json_encode( __( '%d offen', 'vereins-plugin' ) ); ?>,
                allTaken: <?php echo json_encode( __( 'alles vergeben', 'vereins-plugin' ) ); ?>,
                listEmpty:<?php echo json_encode( __( 'noch leer', 'vereins-plugin' ) ); ?>,
                noItems:  <?php echo json_encode( __( 'Noch nichts eingetragen.', 'vereins-plugin' ) ); ?>,
                noTasks:  <?php echo json_encode( __( 'Noch keine Aufgaben festgelegt.', 'vereins-plugin' ) ); ?>,
                titleBring:<?php echo json_encode( __( 'Mitbringliste', 'vereins-plugin' ) ); ?>,
                titleHelp: <?php echo json_encode( __( 'Helferaufgaben', 'vereins-plugin' ) ); ?>,
                headBring: <?php echo json_encode( __( 'Bedarf vorgeben (Orgateam)', 'vereins-plugin' ) ); ?>,
                headHelp:  <?php echo json_encode( __( 'Aufgabe hinzufügen (Orgateam)', 'vereins-plugin' ) ); ?>,
                phBring:   <?php echo json_encode( __( 'z. B. Kuchen', 'vereins-plugin' ) ); ?>,
                phHelp:    <?php echo json_encode( __( 'z. B. Auf- und Abbau, Catering, Einlass', 'vereins-plugin' ) ); ?>,
                take:     <?php echo json_encode( __( 'Übernehmen', 'vereins-plugin' ) ); ?>,
                takeFor:  <?php echo json_encode( __( 'Für %s übernehmen', 'vereins-plugin' ) ); ?>,
                release:  <?php echo json_encode( __( 'Zurückziehen', 'vereins-plugin' ) ); ?>,
                remove:   <?php echo json_encode( __( 'Löschen', 'vereins-plugin' ) ); ?>,
                openOf:   <?php echo json_encode( __( '%1$d von %2$d offen', 'vereins-plugin' ) ); ?>,
                covered:  <?php echo json_encode( __( 'vollständig', 'vereins-plugin' ) ); ?>,
                noPhotos: <?php echo json_encode( __( 'Noch keine Fotos zu diesem Termin.', 'vereins-plugin' ) ); ?>,
                photoOne: <?php echo json_encode( __( '1 Foto', 'vereins-plugin' ) ); ?>,
                photoMany:<?php echo json_encode( __( '%d Fotos', 'vereins-plugin' ) ); ?>,
                noneYet:  <?php echo json_encode( __( 'noch keine', 'vereins-plugin' ) ); ?>,
                quota:    <?php echo json_encode( __( 'Dein Speicher für diesen Termin: %1$s von %2$s', 'vereins-plugin' ) ); ?>,
                quotaFree:<?php echo json_encode( __( 'Von dir hochgeladen: %s', 'vereins-plugin' ) ); ?>,
                uploading:<?php echo json_encode( __( '%1$d von %2$d hochgeladen', 'vereins-plugin' ) ); ?>,
                uploadClosed:  <?php echo json_encode( __( 'Der Upload-Zeitraum ist am %s abgelaufen. Die Sammlung ist damit vollständig und lässt sich als ZIP herunterladen.', 'vereins-plugin' ) ); ?>,
                uploadOpen:    <?php echo json_encode( __( 'Noch %1$d Tage Zeit zum Hochladen – bis zum %2$s.', 'vereins-plugin' ) ); ?>,
                uploadLastDay: <?php echo json_encode( __( 'Letzter Tag zum Hochladen!', 'vereins-plugin' ) ); ?>,
                people:     <?php echo json_encode( __( '%d Personen', 'vereins-plugin' ) ); ?>,
                forget:     <?php echo json_encode( __( 'Dauerhaft aus meiner Liste entfernen', 'vereins-plugin' ) ); ?>,
                selfComing: <?php echo json_encode( __( 'Ich', 'vereins-plugin' ) ); ?>,
                comingYes:  <?php echo json_encode( __( 'Dabei', 'vereins-plugin' ) ); ?>,
                comingNo:   <?php echo json_encode( __( 'Nicht dabei', 'vereins-plugin' ) ); ?>,
                viaMember:  <?php echo json_encode( __( ' (Begleitung von %s)', 'vereins-plugin' ) ); ?>,
                linkCopied: <?php echo json_encode( __( 'Link kopiert', 'vereins-plugin' ) ); ?>,
                linkCopy:   <?php echo json_encode( __( 'Link zum Teilen:', 'vereins-plugin' ) ); ?>,
                unconfirmed:<?php echo json_encode( __( 'Kontakt noch nicht bestätigt', 'vereins-plugin' ) ); ?>,
                nameNeeded: <?php echo json_encode( __( 'Bitte gib deinen Namen an.', 'vereins-plugin' ) ); ?>,
                dropReady:  <?php echo json_encode( __( 'oder hierher ziehen', 'vereins-plugin' ) ); ?>,
                dropLocked: <?php echo json_encode( __( 'Bitte zuerst der Nutzung zustimmen', 'vereins-plugin' ) ); ?>,
                consentNeeded: <?php echo json_encode( __( 'Bitte bestätige zuerst, dass das Foto auf der Website und in sozialen Medien verwendet werden kann.', 'vereins-plugin' ) ); ?>
            };

            // --- Overlay mit den Namen -----------------------------------
            var modal    = document.getElementById('vp-rsvp-modal');
            var mTitle   = modal.querySelector('.vp-modal__title');
            var mLoading = modal.querySelector('.vp-modal__loading');
            var mContent = modal.querySelector('.vp-modal__content');

            function closeModal() {
                modal.hidden = true;
                document.body.style.overflow = '';
            }

            function fillNames(ul, list, withTask) {
                ul.innerHTML = '';
                if (!list.length) {
                    var empty = document.createElement('li');
                    empty.className = 'vp-modal__empty';
                    empty.textContent = T.none;
                    ul.appendChild(empty);
                    return;
                }
                list.forEach(function(u){
                    var li = document.createElement('li');
                    li.textContent = u.name;

                    // Begleitperson: gehört zu einem Mitglied, kommt aber
                    // unabhängig von dessen eigener Antwort.
                    if (u.via) {
                        var via = document.createElement('span');
                        via.className = 'vp-modal__task';
                        via.textContent = T.viaMember.replace('%s', u.via);
                        li.appendChild(via);
                    }

                    if (withTask && u.help_task) {
                        var task = document.createElement('span');
                        task.className = 'vp-modal__task';
                        task.textContent = ' – ' + u.help_task;
                        li.appendChild(task);
                    }

                    // Über einen Terminlink zugesagt, Kontakt noch offen.
                    if (u.unconfirmed) {
                        var mark = document.createElement('span');
                        mark.className = 'vp-modal__pending';
                        mark.title = T.unconfirmed;
                        mark.innerHTML = <?php echo json_encode( Icons::svg( 'alert' ) ); ?>;
                        li.appendChild(mark);
                    }

                    ul.appendChild(li);
                });
            }

            modal.addEventListener('click', function(e){
                if (e.target.hasAttribute('data-close')) { closeModal(); }
            });
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && !modal.hidden) { closeModal(); }
            });

            root.addEventListener('click', function(e){
                var toggle = e.target.closest('.vp-rsvp__toggle');
                if (!toggle) { return; }

                var item = toggle.closest('.vp-events__item');
                mTitle.textContent = item.querySelector('.vp-events__title').textContent.trim();
                mLoading.textContent = <?php echo json_encode( __( 'Lade…', 'vereins-plugin' ) ); ?>;
                mLoading.hidden  = false;
                mContent.hidden  = true;
                modal.hidden     = false;
                document.body.style.overflow = 'hidden';

                var url = API + '/events/' + item.dataset.eventId + '/attendance'
                        + '?instance_date=' + encodeURIComponent(item.dataset.instanceDate);

                fetch(url, { headers: H() })
                    .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
                    .then(function(res){
                        if (!res.ok) { mLoading.textContent = T.loadError; return; }
                        var d = res.data;
                        // "Zugesagt" listet Personen, nicht Mitglieder – eine
                        // Begleitperson kommt auch dann, wenn das Mitglied absagt.
                        var attending = d.attending || [];

                        modal.querySelector('[data-yes-label]').textContent  = T.yesLabel.replace('%d', attending.length);
                        modal.querySelector('[data-no-label]').textContent   = T.noLabel.replace('%d', d.no_count);
                        modal.querySelector('[data-help-label]').textContent = T.helpLabel.replace('%d', d.helper_count);
                        fillNames(modal.querySelector('[data-yes-names]'), attending);
                        fillNames(modal.querySelector('[data-help-names]'), d.helpers, true);
                        fillNames(modal.querySelector('[data-no-names]'), d.no);
                        mLoading.hidden = true;
                        mContent.hidden = false;
                    })
                    .catch(function(){ mLoading.textContent = T.loadError; });
            });

            // --- Zusage / Absage / Helfen --------------------------------
            // Alles läuft über denselben Endpunkt: die Antwort wird immer
            // zusammen mit dem Helfer-Status gespeichert.
            function updateCounts(item, d) {
                // Zusagen zählt Mitglieder, die Personenzahl schließt
                // Begleitpersonen mit ein.
                var yesText = T.yes.replace('%d', d.yes_count);
                if (d.people_count > d.yes_count) {
                    yesText += ' · ' + T.people.replace('%d', d.people_count);
                }
                item.querySelector('.vp-rsvp__count--yes').textContent = yesText;
                item.querySelector('.vp-rsvp__count--no').textContent  = T.no.replace('%d', d.no_count);

                var help = item.querySelector('.vp-rsvp__count--help');
                if (help) { help.textContent = T.helpCount.replace('%d', d.helper_count); }
            }

            function sendRsvp(item, value) {
                var status = item.querySelector('.vp-rsvp__status');

                item.querySelectorAll('.vp-rsvp__btn').forEach(function(b){ b.disabled = true; });
                status.textContent = T.saving;

                return fetch(API + '/events/' + item.dataset.eventId + '/attendance', {
                    method: 'POST',
                    headers: H(true),
                    body: JSON.stringify({
                        instance_date: item.dataset.instanceDate,
                        response: value
                    })
                })
                .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
                .then(function(res){
                    if (!res.ok) {
                        status.textContent = res.data.message || T.error;
                        return;
                    }
                    status.textContent = '';
                    var d = res.data;

                    item.querySelectorAll('.vp-rsvp__btn').forEach(function(b){
                        b.classList.toggle('is-active', b.dataset.response === d.own_response);
                    });
                    updateCounts(item, d);
                })
                .catch(function(){ status.textContent = T.error; })
                .finally(function(){
                    item.querySelectorAll('.vp-rsvp__btn').forEach(function(b){ b.disabled = false; });
                });
            }

            root.addEventListener('click', function(e){
                var btn = e.target.closest('.vp-rsvp__btn');
                if (!btn) { return; }

                var item = btn.closest('.vp-events__item');

                if (needsIdentity(function(){ btn.click(); })) { return; }

                // Erneuter Klick auf die aktive Antwort zieht alles zurück.
                if (btn.classList.contains('is-active')) {
                    sendRsvp(item, 'none');
                    updatePeopleBadge(item, 0);
                    return;
                }

                // Sonst öffnet sich der Dialog "Wer kommt?" – die angeklickte
                // Antwort gilt sofort, Begleitpersonen lassen sich dort ergänzen.
                openPeople(item, btn.dataset.response);
            });

            /** Helferzahl am Termin nachziehen, nachdem Aufgaben übernommen wurden. */
            function refreshCounts(item) {
                var url = API + '/events/' + item.dataset.eventId + '/attendance'
                        + '?instance_date=' + encodeURIComponent(item.dataset.instanceDate);

                fetch(url, { headers: H() })
                    .then(function(r){ return r.ok ? r.json() : null; })
                    .then(function(d){
                        if (!d) { return; }
                        updateCounts(item, d);
                        item.querySelectorAll('.vp-rsvp__btn').forEach(function(b){
                            b.classList.toggle('is-active', b.dataset.response === d.own_response);
                        });
                    })
                    .catch(function(){});
            }

            // --- Mitbringliste & Helferaufgaben --------------------------
            // Ein Overlay für beide Arten; 'currentKind' steuert Beschriftung
            // und Zielressource.
            var bring        = document.getElementById('vp-bring-modal');
            var bTitle       = bring.querySelector('.vp-modal__title');
            var bLoading     = bring.querySelector('.vp-modal__loading');
            var bContent     = bring.querySelector('.vp-bring__content');
            var bList        = bring.querySelector('[data-bring-list]');
            var bAddBox      = bring.querySelector('[data-bring-addbox]');
            var bRequestBox  = bring.querySelector('[data-bring-request]');
            var bRequestHead = bring.querySelector('[data-request-head]');
            var bRequestLabel= bring.querySelector('[data-request-label]');
            var bError       = bring.querySelector('[data-bring-error]');
            var currentItem  = null;   // <li> des Termins, für den das Overlay offen ist
            var currentKind  = 'bring';
            // Eigene Begleitpersonen – damit sich Helferaufgaben auch für
            // Familienmitglieder übernehmen lassen.
            var helperPeople = [];

            function closeBring() {
                bring.hidden = true;
                if (currentItem && currentKind === 'help') { refreshCounts(currentItem); }
                currentItem  = null;
                document.body.style.overflow = '';
            }

            bring.addEventListener('click', function(e){
                if (e.target.hasAttribute('data-close')) { closeBring(); }
            });
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && !bring.hidden) { closeBring(); }
            });

            function showError(message) {
                bError.textContent = message;
                bError.hidden = !message;
            }

            /** Baut die Liste neu auf und aktualisiert den Zähler im Termin. */
            function renderItems(data) {
                var items  = data.items || [];
                var isHelp = currentKind === 'help';

                // Eigene Einträge gibt es nur bei der Mitbringliste –
                // Helferaufgaben legt ausschließlich das Orgateam fest.
                bAddBox.hidden     = isHelp;
                bRequestBox.hidden = !data.can_manage;
                bRequestHead.textContent = isHelp ? T.headHelp : T.headBring;
                bRequestLabel.placeholder = isHelp ? T.phHelp : T.phBring;

                bList.innerHTML = '';

                if (!items.length) {
                    var empty = document.createElement('li');
                    empty.className = 'vp-modal__empty';
                    empty.textContent = isHelp ? T.noTasks : T.noItems;
                    bList.appendChild(empty);
                }

                var openTotal = 0;

                items.forEach(function(item){
                    openTotal += item.open;

                    var li = document.createElement('li');
                    li.className = 'vp-bring__item';

                    var head = document.createElement('div');
                    head.className = 'vp-bring__itemhead';

                    var label = document.createElement('strong');
                    label.textContent = item.label;
                    head.appendChild(label);

                    var meta = document.createElement('span');
                    meta.className = 'vp-bring__meta';
                    if (item.is_request) {
                        meta.textContent = item.open > 0
                            ? T.openOf.replace('%1$d', item.open).replace('%2$d', item.quantity_needed)
                            : T.covered;
                        meta.classList.add(item.open > 0 ? 'is-open' : 'is-covered');
                    }
                    head.appendChild(meta);
                    li.appendChild(head);

                    if (item.claims.length) {
                        var who = document.createElement('div');
                        who.className = 'vp-bring__who';
                        who.textContent = item.claims.map(function(c){
                            var label = c.name;
                            if (c.via) { label += T.viaMember.replace('%s', c.via); }
                            if (c.quantity > 1) { label += ' (' + c.quantity + ')'; }
                            return label;
                        }).join(', ');
                        li.appendChild(who);
                    }

                    var actions = document.createElement('div');
                    actions.className = 'vp-bring__actions';

                    // Wer aus der Familie hat diesen Posten übernommen?
                    var mine = item.claims.filter(function(c){ return c.own; });

                    mine.forEach(function(c){
                        var label = c.companion_id
                            ? T.release + ' – ' + c.name
                            : T.release;
                        var btn = makeBtn(label, 'release', item.id);
                        btn.dataset.companionId = c.companion_id || 0;
                        actions.appendChild(btn);
                    });

                    // Übernehmen anbieten, solange noch Bedarf offen ist –
                    // für sich selbst und für noch nicht eingeteilte Personen.
                    if (item.is_request && item.open > 0) {
                        var takenIds = mine.map(function(c){ return String(c.companion_id || 0); });

                        if (takenIds.indexOf('0') === -1) {
                            var self = makeBtn(T.take, 'claim', item.id, 'is-primary');
                            self.dataset.companionId = 0;
                            actions.appendChild(self);
                        }

                        (helperPeople || []).forEach(function(p){
                            if (takenIds.indexOf(String(p.id)) !== -1) { return; }
                            var b = makeBtn(T.takeFor.replace('%s', p.name), 'claim', item.id);
                            b.dataset.companionId = p.id;
                            actions.appendChild(b);
                        });
                    }

                    if (data.can_manage || (!item.is_request && item.own_claim)) {
                        actions.appendChild(makeBtn(T.remove, 'delete', item.id, 'is-danger'));
                    }

                    if (actions.children.length) { li.appendChild(actions); }
                    bList.appendChild(li);
                });

                // Zähler am passenden Button des Termins aktualisieren
                if (currentItem) {
                    var trigger = currentItem.querySelector('.vp-bring__open[data-kind="' + currentKind + '"]');
                    if (trigger) {
                        trigger.querySelector('.vp-bring__stat').textContent = openTotal > 0
                            ? T.open.replace('%d', openTotal)
                            : (items.length ? T.allTaken : T.listEmpty);
                    }
                }
            }

            function makeBtn(label, action, itemId, extra) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'vp-bring__btn' + (extra ? ' ' + extra : '');
                b.textContent = label;
                b.dataset.action = action;
                b.dataset.itemId = itemId;
                return b;
            }

            function loadItems() {
                if (!currentItem) { return Promise.resolve(); }
                var url = API + '/events/' + currentItem.dataset.eventId + '/items'
                        + '?instance_date=' + encodeURIComponent(currentItem.dataset.instanceDate)
                        + '&kind=' + encodeURIComponent(currentKind);

                return fetch(url, { headers: H() })
                    .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
                    .then(function(res){
                        if (!res.ok) { bLoading.textContent = T.loadError; return; }
                        renderItems(res.data);
                        bLoading.hidden = true;
                        bContent.hidden = false;
                    })
                    .catch(function(){ bLoading.textContent = T.loadError; });
            }

            root.addEventListener('click', function(e){
                var trigger = e.target.closest('.vp-bring__open');
                if (!trigger) { return; }

                if (needsIdentity(function(){ trigger.click(); })) { return; }

                currentItem = trigger.closest('.vp-events__item');
                currentKind = trigger.dataset.kind === 'help' ? 'help' : 'bring';

                bTitle.textContent   = currentKind === 'help' ? T.titleHelp : T.titleBring;
                bLoading.textContent = <?php echo json_encode( __( 'Lade…', 'vereins-plugin' ) ); ?>;
                bLoading.hidden = false;
                bContent.hidden = true;
                showError('');
                bring.hidden = false;
                document.body.style.overflow = 'hidden';

                // Bei Helferaufgaben zusätzlich die eigenen Personen laden,
                // damit man auch für sie übernehmen kann.
                if (currentKind === 'help') {
                    fetch(API + '/companions', { headers: H() })
                        .then(function(r){ return r.ok ? r.json() : null; })
                        .then(function(d){ helperPeople = (d && d.saved) || []; })
                        .catch(function(){ helperPeople = []; })
                        .finally(loadItems);
                } else {
                    helperPeople = [];
                    loadItems();
                }
            });

            // Übernehmen / Zurückziehen / Löschen
            bring.addEventListener('click', function(e){
                var btn = e.target.closest('.vp-bring__btn');
                if (!btn) { return; }

                var id        = btn.dataset.itemId;
                var companion = parseInt(btn.dataset.companionId || 0, 10);
                var cfg = {
                    claim:   { url: API + '/items/' + id + '/claim', method: 'POST' },
                    release: { url: API + '/items/' + id + '/claim', method: 'DELETE' },
                    'delete':{ url: API + '/items/' + id,            method: 'DELETE' }
                }[btn.dataset.action];

                if (!cfg) { return; }
                showError('');
                btn.disabled = true;

                var url = cfg.url;
                if (cfg.method === 'DELETE' && btn.dataset.action === 'release') {
                    url += '?companion_id=' + companion;
                }

                fetch(url, {
                    method: cfg.method,
                    headers: H(true),
                    body: cfg.method === 'POST'
                        ? JSON.stringify({ quantity: 1, companion_id: companion })
                        : undefined
                })
                .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
                .then(function(res){
                    if (!res.ok) { showError(res.data.message || T.error); btn.disabled = false; return; }
                    renderItems(res.data);
                })
                .catch(function(){ showError(T.error); btn.disabled = false; });
            });

            // Eigenen Eintrag bzw. Bedarf hinzufügen
            function addItem(labelEl, qtyEl, isRequest) {
                var label = labelEl.value.trim();
                if (!label || !currentItem) { return; }
                showError('');

                fetch(API + '/events/' + currentItem.dataset.eventId + '/items', {
                    method: 'POST',
                    headers: H(true),
                    body: JSON.stringify({
                        instance_date:   currentItem.dataset.instanceDate,
                        kind:            currentKind,
                        label:           label,
                        is_request:      !!isRequest,
                        quantity_needed: qtyEl ? Math.max(1, parseInt(qtyEl.value, 10) || 1) : 1
                    })
                })
                .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
                .then(function(res){
                    if (!res.ok) { showError(res.data.message || T.error); return; }
                    labelEl.value = '';
                    if (qtyEl) { qtyEl.value = 1; }
                    renderItems(res.data);
                })
                .catch(function(){ showError(T.error); });
            }

            bring.querySelector('[data-bring-add]').addEventListener('click', function(){
                addItem(bring.querySelector('[data-bring-label]'), null, false);
            });
            bring.querySelector('[data-request-add]').addEventListener('click', function(){
                addItem(bring.querySelector('[data-request-label]'), bring.querySelector('[data-request-qty]'), true);
            });
            bring.addEventListener('keydown', function(e){
                if (e.key !== 'Enter') { return; }
                if (e.target.hasAttribute('data-bring-label'))   { e.preventDefault(); bring.querySelector('[data-bring-add]').click(); }
                if (e.target.hasAttribute('data-request-label')) { e.preventDefault(); bring.querySelector('[data-request-add]').click(); }
            });

            // --- Kennung ohne Login ---------------------------------------
            // Aktionen laufen normal los; fehlt die Kennung, wird die Absicht
            // gemerkt, der Dialog gezeigt und danach fortgesetzt.
            var identModal = document.getElementById('vp-ident-modal');
            var pendingAction = null;

            function needsIdentity(run) {
                if (KNOWN || !identModal) { return false; }
                pendingAction = run;
                identModal.hidden = false;
                document.body.style.overflow = 'hidden';
                var n = document.getElementById('vp-ident-name');
                if (n) { n.focus(); }
                return true;
            }

            if (identModal) {
                var identError = identModal.querySelector('[data-ident-error]');

                identModal.addEventListener('click', function(e){
                    if (e.target.hasAttribute('data-close')) {
                        identModal.hidden = true;
                        pendingAction = null;
                        document.body.style.overflow = '';
                    }
                });

                identModal.querySelector('[data-ident-save]').addEventListener('click', function(){
                    var name  = document.getElementById('vp-ident-name').value.trim();
                    var email = document.getElementById('vp-ident-email').value.trim();

                    identError.hidden = true;

                    // Nur der Name ist nötig. Kontaktdaten sind freiwillig –
                    // sie dienen der Benachrichtigung, nicht dem Zugang.
                    if (!name) {
                        identError.textContent = T.nameNeeded;
                        identError.hidden = false;
                        document.getElementById('vp-ident-name').focus();
                        return;
                    }

                    fetch(API + '/guest/identify', {
                        method: 'POST',
                        headers: H(true),
                        body: JSON.stringify({
                            id:            <?php echo (int) ( $upcoming[0]['event_id'] ?? 0 ); ?>,
                            instance_date: <?php echo json_encode( $upcoming ? $this->instance_date( $upcoming[0] ) : '' ); ?>,
                            vp_key:        KEY,
                            name:          name,
                            email:         email,
                            return_url:    window.location.href.split('#')[0]
                        })
                    })
                    .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
                    .then(function(res){
                        if (!res.ok) {
                            identError.textContent = res.data.message || T.error;
                            identError.hidden = false;
                            return;
                        }

                        KNOWN = true;
                        identModal.hidden = true;
                        document.body.style.overflow = '';

                        var run = pendingAction;
                        pendingAction = null;
                        if (run) { run(); }
                    })
                    .catch(function(){
                        identError.textContent = T.error;
                        identError.hidden = false;
                    });
                });
            }

            // --- Link zum Teilen ------------------------------------------
            // Wer den Link hat, darf ohne Anmeldung zu- und absagen.
            root.addEventListener('click', function(e){
                var share = e.target.closest('[data-share]');
                if (!share) { return; }

                var url  = share.dataset.url;
                var done = function(){
                    var old = share.title;
                    share.title = T.linkCopied;
                    share.classList.add('is-done');
                    setTimeout(function(){ share.title = old; share.classList.remove('is-done'); }, 2000);
                };

                if (navigator.share) {
                    navigator.share({ url: url }).catch(function(){});
                    return;
                }
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(url).then(done).catch(function(){ window.prompt(T.linkCopy, url); });
                    return;
                }
                window.prompt(T.linkCopy, url);
            });

            // --- Titelbild der Kachel (Orgateam) --------------------------
            var coverInput = document.getElementById('vp-cover-input');
            var coverItem  = null;

            if (coverInput) {
                root.addEventListener('click', function(e){
                    var pick = e.target.closest('[data-cover-pick]');
                    if (pick) {
                        coverItem = pick.closest('.vp-events__item');
                        coverInput.click();
                        return;
                    }

                    var drop = e.target.closest('[data-cover-remove]');
                    if (!drop) { return; }

                    var item = drop.closest('.vp-events__item');
                    fetch(API + '/events/' + item.dataset.eventId + '/image', {
                        method: 'DELETE',
                        headers: H()
                    })
                    .then(function(r){ if (r.ok) { window.location.reload(); } })
                    .catch(function(){});
                });

                coverInput.addEventListener('change', function(){
                    if (!coverItem || !coverInput.files.length) { return; }

                    var cover = coverItem.querySelector('.vp-tile__cover');
                    cover.classList.add('is-busy');

                    var fd = new FormData();
                    fd.append('image', coverInput.files[0]);

                    fetch(API + '/events/' + coverItem.dataset.eventId + '/image', {
                        method: 'POST',
                        headers: H(),
                        body: fd
                    })
                    .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
                    .then(function(res){
                        cover.classList.remove('is-busy');
                        if (!res.ok) { alert(res.data.message || T.error); return; }
                        // Neu laden, damit auch der Entfernen-Button erscheint.
                        window.location.reload();
                    })
                    .catch(function(){ cover.classList.remove('is-busy'); })
                    .finally(function(){ coverInput.value = ''; });
                });
            }

            // --- Begleitpersonen ------------------------------------------
            var peModal   = document.getElementById('vp-people-modal');
            var peLoading = peModal.querySelector('.vp-modal__loading');
            var peContent = peModal.querySelector('.vp-people__content');
            var peList    = peModal.querySelector('[data-people-list]');
            var peNew     = peModal.querySelector('[data-people-new]');
            var peError   = peModal.querySelector('[data-people-error]');
            var peItem    = null;
            // saved  = eigene Personenliste, chosen = wer bei diesem Termin dabei ist,
            // self   = eigene Antwort ('yes' | 'no' | null)
            var peState   = { saved: [], chosen: {}, self: null };

            function closePeople() {
                peModal.hidden = true;
                peItem = null;
                document.body.style.overflow = '';
            }

            peModal.addEventListener('click', function(e){
                if (e.target.hasAttribute('data-close')) { closePeople(); }
            });
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && !peModal.hidden) { closePeople(); }
            });

            function peopleError(msg) {
                peError.textContent = msg || '';
                peError.hidden = !msg;
            }

            /**
             * Baut je Person eine Zeile mit denselben Pill-Buttons wie am
             * Termin – oben man selbst, darunter die eigenen Personen.
             */
            function personRow(name, isComing, dataset, removable) {
                var li = document.createElement('li');
                li.className = 'vp-bring__item vp-person';

                var head = document.createElement('div');
                head.className = 'vp-bring__itemhead';

                var label = document.createElement('strong');
                label.textContent = name;
                head.appendChild(label);

                if (removable) {
                    var forget = document.createElement('button');
                    forget.type = 'button';
                    forget.className = 'vp-person__forget';
                    forget.title = T.forget;
                    forget.textContent = '×';
                    forget.dataset.forgetId = dataset.companionId;
                    head.appendChild(forget);
                }

                li.appendChild(head);

                var actions = document.createElement('div');
                actions.className = 'vp-bring__actions';

                [ ['yes', T.comingYes], ['no', T.comingNo] ].forEach(function(pair){
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'vp-rsvp__btn vp-rsvp__btn--' + pair[0]
                                + ((isComing === (pair[0] === 'yes')) ? ' is-active' : '');
                    b.textContent = pair[1];
                    b.dataset.personAnswer = pair[0];
                    if (dataset.companionId) { b.dataset.companionId = dataset.companionId; }
                    if (dataset.self)        { b.dataset.self = '1'; }
                    actions.appendChild(b);
                });

                li.appendChild(actions);
                return li;
            }

            function renderPeople() {
                peList.innerHTML = '';

                // Eigene Teilnahme zuerst – ohne gesetzte Antwort gilt "nicht dabei".
                peList.appendChild(
                    personRow(T.selfComing, peState.self === 'yes', { self: true }, false)
                );

                peState.saved.forEach(function(p){
                    peList.appendChild(
                        personRow(p.name, !!peState.chosen[p.id], { companionId: p.id }, true)
                    );
                });
            }

            function updatePeopleBadge(item, count) {
                var badge = item.querySelector('.vp-rsvp__guests');
                if (!badge) { return; }
                badge.textContent = '+' + count;
                badge.hidden = count < 1;
            }

            function applyPeopleData(d) {
                peState.saved  = d.saved_companions || [];
                peState.chosen = {};
                (d.own_companions || []).forEach(function(c){ peState.chosen[c.companion_id] = true; });
                peState.self   = d.own_response;
                renderPeople();

                // Termin-Zeile mitziehen
                if (peItem) {
                    peItem.querySelectorAll('.vp-rsvp > .vp-rsvp__buttons .vp-rsvp__btn').forEach(function(b){
                        b.classList.toggle('is-active', b.dataset.response === d.own_response);
                    });
                    updateCounts(peItem, d);
                    updatePeopleBadge(peItem, (d.own_companions || []).length);
                }
            }

            /**
             * Speichert eigene Antwort und Begleitpersonen zusammen – der
             * Endpunkt erwartet immer den kompletten Stand.
             */
            function savePeople(newNames) {
                if (!peItem) { return Promise.resolve(); }
                peopleError('');

                var ids = Object.keys(peState.chosen)
                    .filter(function(k){ return peState.chosen[k]; })
                    .map(Number);

                return fetch(API + '/events/' + peItem.dataset.eventId + '/attendance', {
                    method: 'POST',
                    headers: H(true),
                    body: JSON.stringify({
                        instance_date:   peItem.dataset.instanceDate,
                        response:        peState.self === 'yes' ? 'yes' : 'no',
                        companion_ids:   ids,
                        companion_names: newNames || []
                    })
                })
                .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
                .then(function(res){
                    if (!res.ok) { peopleError(res.data.message || T.error); return; }
                    applyPeopleData(res.data);
                })
                .catch(function(){ peopleError(T.error); });
            }

            function openPeople(item, presetResponse) {
                peItem = item;
                peLoading.textContent = <?php echo json_encode( __( 'Lade…', 'vereins-plugin' ) ); ?>;
                peLoading.hidden = false;
                peContent.hidden = true;
                peopleError('');
                peNew.value = '';
                peModal.hidden = false;
                document.body.style.overflow = 'hidden';

                var url = API + '/events/' + item.dataset.eventId + '/attendance'
                        + '?instance_date=' + encodeURIComponent(item.dataset.instanceDate);

                fetch(url, { headers: H() })
                    .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
                    .then(function(res){
                        if (!res.ok) { peLoading.textContent = T.loadError; return; }
                        applyPeopleData(res.data);
                        peLoading.hidden = true;
                        peContent.hidden = false;

                        // Die angeklickte Antwort gilt sofort – der Dialog dient
                        // nur noch dazu, Begleitpersonen zu ergänzen.
                        if (presetResponse && peState.self !== presetResponse) {
                            peState.self = presetResponse;
                            renderPeople();
                            savePeople();
                        }
                    })
                    .catch(function(){ peLoading.textContent = T.loadError; });
            }

            // Antwort je Person umschalten – wird sofort gespeichert.
            peModal.addEventListener('click', function(e){
                var btn = e.target.closest('[data-person-answer]');
                if (!btn) { return; }

                var yes = btn.dataset.personAnswer === 'yes';

                if (btn.dataset.self) {
                    peState.self = yes ? 'yes' : 'no';
                } else {
                    peState.chosen[btn.dataset.companionId] = yes;
                }

                renderPeople();
                savePeople();
            });

            // Person dauerhaft aus der eigenen Liste entfernen
            peModal.addEventListener('click', function(e){
                var btn = e.target.closest('.vp-person__forget');
                if (!btn) { return; }

                var id = btn.dataset.forgetId;
                fetch(API + '/companions/' + id, {
                    method: 'DELETE',
                    headers: H()
                })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    peState.saved = d.saved || [];
                    delete peState.chosen[id];
                    renderPeople();
                    savePeople();
                })
                .catch(function(){ peopleError(T.error); });
            });

            function addPerson() {
                var name = peNew.value.trim();
                if (!name) { return; }
                peNew.value = '';
                // Neue Personen sind direkt dabei – sonst hätte man sie nicht
                // eingetragen.
                savePeople([ name ]).then(function(){
                    var added = peState.saved.filter(function(p){ return p.name === name; })[0];
                    if (added && !peState.chosen[added.id]) {
                        peState.chosen[added.id] = true;
                        renderPeople();
                        savePeople();
                    }
                });
            }

            peModal.querySelector('[data-people-add]').addEventListener('click', addPerson);
            peNew.addEventListener('keydown', function(e){
                if (e.key === 'Enter') { e.preventDefault(); addPerson(); }
            });

            // --- Fotogalerie ---------------------------------------------
            var pModal    = document.getElementById('vp-photos-modal');
            var pLoading  = pModal.querySelector('.vp-modal__loading');
            var pContent  = pModal.querySelector('.vp-photos__content');
            var pGrid     = pModal.querySelector('[data-photos-grid]');
            var pQuota    = pModal.querySelector('[data-photos-quota]');
            var pError    = pModal.querySelector('[data-photos-error]');
            var pInput    = pModal.querySelector('#vp-photos-input');
            var pConsent  = pModal.querySelector('[data-photos-consent]');
            var pDropHint = pModal.querySelector('[data-photos-drophint]');
            var pDrop     = pModal.querySelector('[data-photos-drop]');
            var pZip      = pModal.querySelector('[data-photos-zip]');
            var pProgress = pModal.querySelector('[data-photos-progress]');
            var pBar      = pModal.querySelector('[data-photos-bar]');
            var pProgText = pModal.querySelector('[data-photos-progresstext]');
            var pClosed   = pModal.querySelector('[data-photos-closed]');
            var pItem     = null;   // Termin, dessen Galerie offen ist

            function closePhotos() {
                pModal.hidden = true;
                pItem = null;
                document.body.style.overflow = '';
            }

            pModal.addEventListener('click', function(e){
                if (e.target.hasAttribute('data-close')) { closePhotos(); }
            });
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && !pModal.hidden) { closePhotos(); }
            });

            function photoError(msg) {
                pError.textContent = msg || '';
                pError.hidden = !msg;
            }

            /**
             * Ohne Zustimmung bleibt die Auswahl gesperrt – Drag & Drop lässt
             * sich technisch nicht per disabled-Attribut verhindern, deshalb
             * prüft uploadFiles() die Zustimmung zusätzlich vor dem Versand.
             */
            function updateConsentGate() {
                var ok = pConsent.checked;
                pInput.disabled = !ok;
                pDrop.classList.toggle('is-disabled', !ok);
                pDropHint.textContent = ok ? T.dropReady : T.dropLocked;
            }
            pConsent.addEventListener('change', function(){
                updateConsentGate();
                photoError('');
            });

            function human(bytes) {
                if (bytes < 1024) { return bytes + ' B'; }
                if (bytes < 1048576) { return Math.round(bytes / 1024) + ' KB'; }
                return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB';
            }

            function renderPhotos(d) {
                var photos = d.photos || [];
                pGrid.innerHTML = '';

                if (!photos.length) {
                    var empty = document.createElement('p');
                    empty.className = 'vp-modal__empty';
                    empty.textContent = T.noPhotos;
                    pGrid.appendChild(empty);
                } else {
                    photos.forEach(function(p){
                        var fig = document.createElement('figure');
                        fig.className = 'vp-photo';

                        var link = document.createElement('a');
                        link.href = p.full;
                        link.target = '_blank';
                        link.rel = 'noopener';

                        var img = document.createElement('img');
                        img.src = p.thumb;
                        img.alt = '';
                        img.loading = 'lazy';
                        link.appendChild(img);
                        fig.appendChild(link);

                        var cap = document.createElement('figcaption');
                        cap.textContent = p.uploader;
                        fig.appendChild(cap);

                        if (p.own || d.can_manage) {
                            var del = document.createElement('button');
                            del.type = 'button';
                            del.className = 'vp-photo__del';
                            del.title = T.remove;
                            del.textContent = '×';
                            del.dataset.photoId = p.id;
                            fig.appendChild(del);
                        }

                        pGrid.appendChild(fig);
                    });
                }

                // Upload-Zeitraum: nach Ablauf gibt es nichts mehr hochzuladen,
                // dafür steht die vollständige Sammlung zum Download bereit.
                var up = d.upload || { open: true, unlimited: true };
                pDrop.hidden   = !up.open;
                pQuota.hidden  = !up.open;
                pClosed.hidden = up.open;

                if (!up.open) {
                    pClosed.textContent = T.uploadClosed.replace('%s', up.deadline_text || '');
                } else if (!up.unlimited) {
                    pClosed.hidden = false;
                    pClosed.classList.add('is-hint');
                    pClosed.textContent = up.days_left <= 1
                        ? T.uploadLastDay
                        : T.uploadOpen.replace('%1$d', up.days_left).replace('%2$s', up.deadline_text || '');
                } else {
                    pClosed.classList.remove('is-hint');
                }

                // Kontingent
                var q = d.quota || {};
                pQuota.textContent = q.quota
                    ? T.quota.replace('%1$s', human(q.used)).replace('%2$s', human(q.quota))
                    : T.quotaFree.replace('%s', human(q.used || 0));
                pQuota.classList.toggle('is-full', q.quota > 0 && q.percent >= 90);

                pZip.hidden = photos.length === 0;

                // Zähler am Termin nachziehen
                if (pItem) {
                    var stat = pItem.querySelector('.vp-photos__count');
                    if (stat) {
                        stat.textContent = photos.length
                            ? (photos.length === 1 ? T.photoOne : T.photoMany.replace('%d', photos.length))
                            : T.noneYet;
                    }
                }
            }

            function loadPhotos() {
                if (!pItem) { return; }
                var url = API + '/events/' + pItem.dataset.eventId + '/photos'
                        + '?instance_date=' + encodeURIComponent(pItem.dataset.instanceDate);

                fetch(url, { headers: H() })
                    .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
                    .then(function(res){
                        if (!res.ok) { pLoading.textContent = T.loadError; return; }
                        renderPhotos(res.data);
                        pLoading.hidden = true;
                        pContent.hidden = false;
                    })
                    .catch(function(){ pLoading.textContent = T.loadError; });
            }

            root.addEventListener('click', function(e){
                var trigger = e.target.closest('.vp-photos__open');
                if (!trigger) { return; }

                pItem = trigger.closest('.vp-events__item');
                pZip.href = trigger.dataset.zip;

                pModal.querySelector('.vp-modal__title').textContent =
                    pItem.querySelector('.vp-events__title').textContent.trim();
                pLoading.textContent = <?php echo json_encode( __( 'Lade…', 'vereins-plugin' ) ); ?>;
                pLoading.hidden = false;
                pContent.hidden = true;
                photoError('');
                pConsent.checked = false;
                updateConsentGate();
                pModal.hidden = false;
                document.body.style.overflow = 'hidden';
                loadPhotos();
            });

            // Foto löschen
            pModal.addEventListener('click', function(e){
                var del = e.target.closest('.vp-photo__del');
                if (!del) { return; }

                del.disabled = true;
                photoError('');

                fetch(API + '/photos/' + del.dataset.photoId, {
                    method: 'DELETE',
                    headers: H()
                })
                .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
                .then(function(res){
                    if (!res.ok) { photoError(res.data.message || T.error); del.disabled = false; return; }
                    renderPhotos(res.data);
                })
                .catch(function(){ photoError(T.error); del.disabled = false; });
            });

            /**
             * Upload: die Dateien werden einzeln geschickt, damit eine zu große
             * Datei nicht den ganzen Stapel scheitern lässt und der Fortschritt
             * mitläuft.
             */
            function uploadFiles(files) {
                if (!pItem || !files.length) { return; }

                if (!pConsent.checked) {
                    photoError(T.consentNeeded);
                    return;
                }

                var list  = Array.prototype.slice.call(files);
                var total = list.length;
                var done  = 0;
                var fails = [];

                photoError('');
                pProgress.hidden = false;
                pBar.style.width = '0%';
                pProgText.textContent = T.uploading.replace('%1$d', 0).replace('%2$d', total);

                function next() {
                    if (!list.length) {
                        pProgress.hidden = true;
                        if (fails.length) { photoError(fails.join(' · ')); }
                        loadPhotos();
                        return;
                    }

                    var file = list.shift();
                    var fd   = new FormData();
                    fd.append('instance_date', pItem.dataset.instanceDate);
                    fd.append('consent', pConsent.checked ? '1' : '0');
                    fd.append('photo', file);

                    fetch(API + '/events/' + pItem.dataset.eventId + '/photos', {
                        method: 'POST',
                        headers: H(),
                        body: fd
                    })
                    .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
                    .then(function(res){
                        if (!res.ok) {
                            fails.push(res.data.message || file.name);
                        } else if (res.data.errors && res.data.errors.length) {
                            res.data.errors.forEach(function(err){ fails.push(err.message); });
                        }
                    })
                    .catch(function(){ fails.push(file.name); })
                    .finally(function(){
                        done++;
                        pBar.style.width = Math.round(done / total * 100) + '%';
                        pProgText.textContent = T.uploading.replace('%1$d', done).replace('%2$d', total);
                        next();
                    });
                }

                next();
            }

            pInput.addEventListener('change', function(){
                uploadFiles(pInput.files);
                pInput.value = '';
            });

            // Drag & Drop am Rechner
            ['dragenter', 'dragover'].forEach(function(ev){
                pDrop.addEventListener(ev, function(e){
                    e.preventDefault();
                    pDrop.classList.add('is-over');
                });
            });
            ['dragleave', 'drop'].forEach(function(ev){
                pDrop.addEventListener(ev, function(e){
                    e.preventDefault();
                    pDrop.classList.remove('is-over');
                });
            });
            pDrop.addEventListener('drop', function(e){
                if (e.dataTransfer && e.dataTransfer.files) { uploadFiles(e.dataTransfer.files); }
            });
        })();
        </script>
        <?php
        return ob_get_clean();
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


    public function enqueue_styles(): void {
        // Nur laden wenn Shortcode auf der Seite ist
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'vp_events' ) ) {
            return;
        }

        wp_add_inline_style( 'wp-block-library', $this->inline_css() );
    }

    private function inline_css(): string {
        return Icons::css() . '
        .vp-events { font-family: inherit; }
        /* Kachelraster – passt sich der Breite an, ab ~340px zweispaltig. */
        .vp-events__list { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }
        .vp-events__item { display: flex; flex-direction: column; background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; overflow: hidden; box-shadow: 0 1px 2px rgba(17,24,39,.04); }
        .vp-events__item:hover { box-shadow: 0 4px 14px rgba(17,24,39,.08); }

        .vp-tile__cover { position: relative; height: 132px; background-size: cover; background-position: center; }
        .vp-tile__cover.is-busy { opacity: .5; }
        /* Bei Bildern ein Verlauf nach unten, damit das Datum lesbar bleibt. */
        .vp-tile__cover.has-image::after { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,.35) 0%, rgba(0,0,0,0) 55%); }
        .vp-tile__date { position: absolute; top: 12px; left: 14px; z-index: 1; display: flex; flex-direction: column; align-items: center; line-height: 1; color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,.4); }
        .vp-tile__day { font-size: 1.75rem; font-weight: 800; }
        .vp-tile__month { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; margin-top: 2px; }
        /* Spanne statt einzelner Tag – schmaler setzen, damit sie passt. */
        .vp-tile__date.is-span .vp-tile__day { font-size: 1.2rem; letter-spacing: -.02em; }
        .vp-events__span { display: inline-flex; align-items: center; gap: 5px; margin-top: 4px; font-size: .78rem; font-weight: 600; color: #b45309; }
        .vp-events__span svg { width: 13px; height: 13px; flex: none; }
        .vp-tile__coveractions { position: absolute; top: 10px; right: 10px; z-index: 1; display: flex; gap: 6px; opacity: 0; transition: opacity .15s; }
        .vp-events__item:hover .vp-tile__coveractions,
        .vp-tile__coveractions:focus-within { opacity: 1; }
        .vp-tile__coverbtn { display: inline-flex; text-decoration: none; align-items: center; justify-content: center; width: 30px; height: 30px; border: none; border-radius: 999px; background: rgba(17,24,39,.6); color: #fff; cursor: pointer; padding: 0; }
        .vp-tile__coverbtn:hover { background: rgba(17,24,39,.85); }
        .vp-tile__coverbtn.is-done { background: #16A34A; }
        /* Feste Größe statt em: Button und Link erben unterschiedliche
           Schriftgrößen, sonst fällt ein Symbol größer aus als die anderen. */
        .vp-tile__coverbtn .vp-icon { width: 16px; height: 16px; vertical-align: 0; }
        .vp-ident__field { margin-bottom: 18px; }
        .vp-ident__field:last-of-type { margin-bottom: 0; }
        .vp-ident__field .vp-bring__addrow { margin-top: 6px; }
        .vp-ident__optional { margin-left: 6px; padding: 1px 8px; border-radius: 999px; background: #f3f4f6; color: #6b7280; font-size: .62rem; font-weight: 600; letter-spacing: 0; text-transform: none; }
        .vp-ident__actions { margin-top: 18px; padding-top: 14px; border-top: 1px solid #f3f4f6; display: flex; justify-content: flex-end; }
        .vp-modal__pending { display: inline-flex; vertical-align: -2px; margin-left: 6px; color: #D97706; }
        .vp-modal__pending svg { width: 14px; height: 14px; }

        .vp-tile__body { display: flex; flex-direction: column; gap: 4px; padding: 14px 16px 16px; }
        .vp-events__type { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
        .vp-events__title{ font-size: 1.05rem; font-weight: 700; color: #111827; line-height: 1.3; }
        .vp-events__meta { font-size: 0.8rem; color: #6b7280; }
        .vp-notice       { color: #6b7280; font-style: italic; }
        .vp-events__heading { font-size: 1rem; font-weight: 700; color: #374151; margin: 28px 0 2px; }
        .vp-events__hint { font-size: .8rem; color: #9ca3af; margin: 0 0 10px; }
        /* Vergangenes tritt optisch zurück – dort geht es nur noch um Fotos. */
        .vp-events__item.is-past { box-shadow: none; }
        .vp-events__item.is-past .vp-tile__cover { filter: saturate(.6); }
        .vp-events__item.is-past .vp-events__title { color: #6b7280; }

        .vp-rsvp { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-top: 10px; padding-top: 12px; border-top: 1px solid #f3f4f6; }
        .vp-rsvp__buttons { display: flex; align-items: center; gap: 8px; }
        .vp-rsvp__btn { border: 1px solid #d1d5db; background: #fff; color: #374151; padding: 5px 14px; border-radius: 999px; font-size: .85rem; font-weight: 600; cursor: pointer; font-family: inherit; }
        .vp-rsvp__btn:hover:not(:disabled) { border-color: #9ca3af; }
        .vp-rsvp__btn:disabled { opacity: .6; cursor: default; }
        .vp-rsvp__btn--yes.is-active { background: #059669; border-color: #059669; color: #fff; }
        .vp-rsvp__btn--no.is-active  { background: #dc2626; border-color: #dc2626; color: #fff; }
        .vp-rsvp__status { font-size: .8rem; color: #6b7280; }
        /* Begleitpersonen: nutzt bewusst die vorhandenen Bausteine
           (vp-bring__item, vp-rsvp__btn), damit der Dialog aussieht wie die
           übrigen Popover. */
        .vp-rsvp__guests { font-size: .78rem; font-weight: 700; color: #059669; }
        .vp-rsvp__guests[hidden] { display: none; }
        .vp-person .vp-bring__itemhead { align-items: center; }
        .vp-person .vp-bring__actions { margin-top: 8px; }
        .vp-person__forget { background: none; border: none; color: #9ca3af; font-size: 1.1rem; line-height: 1; cursor: pointer; padding: 0 2px; }
        .vp-person__forget:hover { color: #dc2626; }
        .vp-people__note { font-size: .78rem; color: #6b7280; margin: 6px 0 0; }
        .vp-rsvp__toggle { display: flex; align-items: center; gap: 10px; background: none; border: none; cursor: pointer; font-size: .8rem; color: #4b5563; font-family: inherit; padding: 4px 0; }
        .vp-rsvp__count--yes  { color: #059669; font-weight: 600; }
        .vp-rsvp__count--no   { color: #dc2626; font-weight: 600; }
        .vp-rsvp__count--help { color: #2563eb; font-weight: 600; }
        .vp-rsvp__toggle:hover .vp-rsvp__count { text-decoration: underline; }

        /* Chips für Helferaufgaben, Mitbringliste und Fotos – dieselbe
           Pillform wie die Zu-/Absage-Buttons, nur ruhiger, damit die
           eigentliche Entscheidung oben führend bleibt. */
        .vp-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
        .vp-chip { display: inline-flex; align-items: center; gap: 7px; background: #fff; border: 1px solid #d1d5db; border-radius: 999px; padding: 5px 6px 5px 12px; cursor: pointer; font-family: inherit; font-size: .82rem; color: #374151; line-height: 1.4; }
        .vp-chip:hover { border-color: #9ca3af; background: #f9fafb; }
        .vp-chip .vp-icon { width: 15px; height: 15px; opacity: .7; }
        .vp-chip:hover .vp-icon { opacity: 1; }
        .vp-chip__label { font-weight: 600; }
        .vp-chip__stat { background: #f3f4f6; color: #6b7280; border-radius: 999px; padding: 2px 9px; font-size: .74rem; white-space: nowrap; }
        .vp-chip:hover .vp-chip__stat { background: #e5e7eb; }
        /* Fotos heben sich leicht ab – dort liegt nach dem Termin die Musik. */
        .vp-chip--photos { border-color: #bfdbfe; background: #eff6ff; color: #1e40af; }
        .vp-chip--photos:hover { border-color: #60a5fa; background: #dbeafe; }
        .vp-chip--photos .vp-chip__stat { background: #dbeafe; color: #1d4ed8; }
        .vp-chip--photos:hover .vp-chip__stat { background: #bfdbfe; }

        .vp-bring__list { list-style: none; margin: 0 0 18px; padding: 0; }
        .vp-bring__item { padding: 10px 0; border-bottom: 1px solid #f3f4f6; }
        .vp-bring__item:last-child { border-bottom: none; }
        .vp-bring__itemhead { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
        .vp-bring__meta { font-size: .78rem; white-space: nowrap; }
        .vp-bring__meta.is-open    { color: #b45309; font-weight: 600; }
        .vp-bring__meta.is-covered { color: #059669; }
        .vp-bring__who { font-size: .82rem; color: #6b7280; margin-top: 2px; }
        .vp-bring__actions { display: flex; gap: 6px; margin-top: 6px; }
        .vp-bring__btn { border: 1px solid #d1d5db; background: #fff; color: #374151; padding: 3px 10px; border-radius: 999px; font-size: .78rem; cursor: pointer; font-family: inherit; }
        .vp-bring__btn:hover:not(:disabled) { border-color: #9ca3af; }
        .vp-bring__btn:disabled { opacity: .5; }
        .vp-bring__btn.is-primary { background: #2563eb; border-color: #2563eb; color: #fff; }
        .vp-bring__btn.is-danger  { color: #dc2626; border-color: #fecaca; }
        .vp-bring__add, .vp-bring__request { padding-top: 14px; border-top: 1px solid #e5e7eb; margin-top: 4px; }
        .vp-bring__addrow { display: flex; gap: 6px; margin-top: 6px; }
        .vp-bring__addrow input[type=text] { flex: 1; min-width: 0; }
        .vp-bring__addrow input[type=number] { width: 64px; }
        /* Eingabe und Button sitzen nebeneinander – gleiche Rundung, damit es
           als ein Element wirkt. */
        .vp-bring__addrow input { padding: 6px 14px; border: 1px solid #d1d5db; border-radius: 999px; font-size: .85rem; font-family: inherit; }
        .vp-bring__addrow input:focus { outline: 2px solid #93c5fd; outline-offset: 1px; border-color: transparent; }
        .vp-bring__addbtn { display: inline-flex; align-items: center; gap: 7px; background: #3b82f6; color: #fff; border: 1px solid #3b82f6; padding: 6px 16px; border-radius: 999px; font-size: .85rem; font-weight: 600; cursor: pointer; font-family: inherit; white-space: nowrap; text-decoration: none; }
        .vp-bring__addbtn:hover { background: #2563eb; border-color: #2563eb; }
        .vp-bring__error { color: #dc2626; font-size: .85rem; margin: 10px 0 0; }
        .vp-modal__label--help { color: #2563eb; }
        .vp-modal__task { color: #6b7280; }

        .vp-modal[hidden] { display: none; }
        .vp-modal { position: fixed; inset: 0; z-index: 100000; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .vp-modal__backdrop { position: absolute; inset: 0; background: rgba(17,24,39,.5); }
        .vp-modal__box { position: relative; background: #fff; border-radius: 10px; box-shadow: 0 20px 40px rgba(0,0,0,.2); width: 100%; max-width: 420px; max-height: 80vh; display: flex; flex-direction: column; }
        .vp-modal__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 20px; border-bottom: 1px solid #e5e7eb; }
        .vp-modal__title { margin: 0; font-size: 1rem; font-weight: 700; color: #111827; }
        .vp-modal__close { background: none; border: none; font-size: 1.6rem; line-height: 1; cursor: pointer; color: #6b7280; padding: 0 4px; }
        .vp-modal__close:hover { color: #111827; }
        .vp-modal__body { padding: 16px 20px; overflow-y: auto; }
        .vp-modal__loading { color: #6b7280; font-style: italic; margin: 0; }
        .vp-modal__group + .vp-modal__group { margin-top: 16px; }
        .vp-modal__label { display: block; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
        .vp-modal__label--yes { color: #059669; }
        .vp-modal__label--no  { color: #dc2626; }
        .vp-modal__names { list-style: none; margin: 0; padding: 0; font-size: .9rem; color: #374151; }
        .vp-modal__names li { padding: 3px 0; border-bottom: 1px solid #f3f4f6; }
        .vp-modal__names li:last-child { border-bottom: none; }
        .vp-modal__empty { color: #9ca3af; font-style: italic; }

        /* Fotogalerie */
        .vp-modal__box--wide { max-width: 720px; }
        .vp-photos__consent { display: flex; align-items: flex-start; gap: 8px; font-size: .82rem; color: #374151; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; margin-bottom: 12px; cursor: pointer; }
        .vp-photos__consent input { margin-top: 2px; flex-shrink: 0; }
        .vp-photos__drop { border: 2px dashed #d1d5db; border-radius: 16px; padding: 20px; text-align: center; transition: border-color .15s, background .15s, opacity .15s; }
        .vp-photos__drop.is-over { border-color: #2563eb; background: #eff6ff; }
        .vp-photos__drop.is-disabled { opacity: .55; }
        .vp-photos__drop.is-disabled .vp-photos__droplabel { cursor: not-allowed; }
        .vp-photos__drop.is-disabled .vp-photos__droplabel strong { background: #9ca3af; }
        .vp-photos__droplabel { display: flex; flex-direction: column; align-items: center; gap: 6px; cursor: pointer; }
        /* Der Aufruf zum Auswählen ist selbst eine Pille – wie alles Klickbare hier. */
        .vp-photos__droplabel strong { display: inline-flex; align-items: center; gap: 7px; background: #3b82f6; color: #fff; border-radius: 999px; padding: 7px 18px; font-size: .85rem; }
        .vp-photos__drop:hover .vp-photos__droplabel strong { background: #2563eb; }
        .vp-photos__droplabel span { font-size: .8rem; color: #6b7280; }
        .vp-photos__progress { display: flex; align-items: center; gap: 10px; margin-top: 12px; }
        .vp-photos__bar { flex: 1; height: 6px; background: #e5e7eb; border-radius: 999px; overflow: hidden; }
        .vp-photos__bar span { display: block; height: 100%; width: 0; background: #2563eb; transition: width .2s; }
        .vp-photos__progresstext { font-size: .78rem; color: #6b7280; white-space: nowrap; }
        .vp-photos__closed { font-size: .85rem; color: #92400e; background: #fef3c7; border-radius: 8px; padding: 10px 12px; margin: 0 0 12px; }
        .vp-photos__closed.is-hint { color: #1e40af; background: #eff6ff; margin: 12px 0 0; }
        .vp-photos__quota { font-size: .8rem; color: #6b7280; margin: 12px 0 4px; }
        .vp-photos__quota.is-full { color: #b45309; font-weight: 600; }
        .vp-photos__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; margin-top: 10px; }
        .vp-photo { position: relative; margin: 0; }
        .vp-photo img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; display: block; }
        .vp-photo figcaption { font-size: .7rem; color: #6b7280; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .vp-photo__del { position: absolute; top: 4px; right: 4px; width: 22px; height: 22px; border: none; border-radius: 50%; background: rgba(17,24,39,.65); color: #fff; font-size: 1rem; line-height: 1; cursor: pointer; }
        .vp-photo__del:hover { background: #dc2626; }
        .vp-photos__actions { margin-top: 16px; margin-bottom: 0; }
        .vp-photos__actions a { display: inline-block; text-decoration: none; }
        ';
    }
}
