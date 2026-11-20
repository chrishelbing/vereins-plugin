<?php

namespace VereinsPlugin\Polls;

use VereinsPlugin\Admin\AdminStyles;
use VereinsPlugin\Admin\Settings;
use VereinsPlugin\Members\MemberService;
use VereinsPlugin\Members\Person;
use VereinsPlugin\Support\Capabilities;

/**
 * Backend des Moduls Umfragen: eigener Menüpunkt „Umfragen“.
 *
 * Liste, Anlegen/Bearbeiten und Auswertung auf einer Seite (vp-polls),
 * unterschieden über ?edit=, ?neu=1 und ?ergebnis=.
 */
class PollsAdmin {

    private const SLUG = 'vp-polls';

    private PollModel   $model;
    private PollService $service;

    public function __construct() {
        $this->model   = new PollModel();
        $this->service = new PollService( $this->model );
    }

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_post_vp_save_poll',   [ $this, 'handle_save' ] );
        add_action( 'admin_post_vp_poll_status', [ $this, 'handle_status' ] );
        add_action( 'admin_post_vp_delete_poll', [ $this, 'handle_delete' ] );
        add_action( 'admin_post_vp_poll_entries_csv', [ $this, 'handle_csv' ] );
        add_action( 'admin_post_vp_poll_entries_xlsx', [ $this, 'handle_xlsx' ] );
        add_action( 'admin_post_vp_poll_entries_mark', [ $this, 'handle_mark' ] );
        add_action( 'wp_ajax_vp_poll_entries_tick', [ $this, 'ajax_tick' ] );
        add_action( 'admin_post_vp_poll_entries_state', [ $this, 'handle_state' ] );
        add_action( 'admin_post_vp_poll_entries_reset', [ $this, 'handle_reset' ] );
        add_action( 'admin_post_vp_poll_entry_edit', [ $this, 'handle_entry_edit' ] );
        add_action( 'admin_post_vp_delete_poll_text', [ $this, 'handle_delete_text' ] );
        add_action( 'admin_post_vp_poll_to_choice',   [ $this, 'handle_to_choice' ] );
    }

    public function add_menu(): void {
        add_menu_page(
            __( 'Umfragen', 'vereins-plugin' ),
            __( 'Umfragen', 'vereins-plugin' ),
            'manage_options',
            self::SLUG,
            [ $this, 'render' ],
            'dashicons-chart-bar',
            28
        );
    }

    private function url( array $args = [] ): string {
        return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
    }

    public function render(): void {
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        AdminStyles::print_inline();
        echo '<div class="wrap vp-admin-wrap">';

        $edit     = (int) ( $_GET['edit'] ?? 0 );
        $ergebnis = (int) ( $_GET['ergebnis'] ?? 0 );

        if ( $ergebnis && ( $poll = $this->model->find( $ergebnis ) ) ) {
            $this->render_results( $poll );
        } elseif ( $edit || ! empty( $_GET['neu'] ) ) {
            $this->render_form( $edit ? $this->model->find( $edit ) : null );
        } else {
            $this->render_list();
        }

        echo '</div>';
    }

    // ------------------------------------------------------------------
    // Liste
    // ------------------------------------------------------------------

    private function render_list(): void {
        $alle    = $this->model->all();
        $filter  = sanitize_key( $_GET['status'] ?? '' );
        $filter  = in_array( $filter, PollModel::STATES, true ) ? $filter : '';
        $zahl    = array_count_values( array_column( $alle, 'status' ) );
        $umfragen = $filter ? array_values( array_filter( $alle, static fn( $p ) => $p['status'] === $filter ) ) : $alle;
        $tab = function ( string $status, string $label, int $anzahl ) use ( $filter ): string {
            $url = $this->url( $status ? [ 'status' => $status ] : [] );
            return sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $filter === $status ? ' class="current" aria-current="page"' : '', esc_html( $label ), $anzahl );
        };
        ?>
        <h1><?php esc_html_e( 'Umfragen', 'vereins-plugin' ); ?></h1>
        <?php $this->render_notices(); ?>
        <style>
        /* Kopf wie bei „Termine“ (dort aus AdminModal) */
        .vp-admin-wrap .vp-head { margin: 16px 0 14px; }
        .vp-admin-wrap .vp-head__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .vp-admin-wrap .vp-head__meta { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px 20px; margin-top: 12px; }
        .vp-admin-wrap .vp-head__meta .subsubsub { float: none; margin: 0; padding: 0; }
        .vp-admin-wrap .vp-head__meta .subsubsub li a { padding: 0 2px; }
        .vp-poll-list td { vertical-align: top; }
        .vp-poll-list .row-title { font-size: 14px; text-decoration: none; }
        .vp-poll-list .row-actions { padding-top: 4px; color: #a7aaad; }
        .vp-poll-list .row-actions .button-link { font-size: 13px; vertical-align: baseline; text-decoration: none; }
        .vp-poll-list .row-actions .button-link:hover { text-decoration: underline; }
        .vp-poll-list .vp-kind-icon { color: #646970; margin-right: 4px; font-size: 18px; width: 18px; height: 18px; vertical-align: -3px; }
        </style>

        <div class="vp-head">
            <div class="vp-head__actions">
                <a class="button button-primary" href="<?php echo esc_url( $this->url( [ 'neu' => 1 ] ) ); ?>"><?php esc_html_e( 'Neue Umfrage', 'vereins-plugin' ); ?></a>
            </div>
            <?php if ( $alle ) : ?>
            <div class="vp-head__meta">
                <ul class="subsubsub vp-tabs">
                    <li><?php echo $tab( '', __( 'Alle', 'vereins-plugin' ), count( $alle ) ); ?> |</li>
                    <li><?php echo $tab( 'open', __( 'Offen', 'vereins-plugin' ), (int) ( $zahl['open'] ?? 0 ) ); ?> |</li>
                    <li><?php echo $tab( 'draft', __( 'Entwürfe', 'vereins-plugin' ), (int) ( $zahl['draft'] ?? 0 ) ); ?> |</li>
                    <li><?php echo $tab( 'closed', __( 'Beendet', 'vereins-plugin' ), (int) ( $zahl['closed'] ?? 0 ) ); ?></li>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <?php if ( ! $alle ) : ?>
            <div class="vp-poll-empty">
                <h2><?php esc_html_e( 'Noch keine Umfrage', 'vereins-plugin' ); ?></h2>
                <p><?php esc_html_e( 'Termin finden, abstimmen, Ideen sammeln oder für ein Turnier melden – womit möchtest du anfangen?', 'vereins-plugin' ); ?></p>
                <?php $this->render_kind_cards(); ?>
            </div>
            <?php return; ?>
        <?php endif; ?>

        <table class="vp-table vp-poll-list">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Titel', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Art', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Teilnahmen', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Ende', 'vereins-plugin' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( ! $umfragen ) : ?>
                <tr><td colspan="5" class="description"><?php esc_html_e( 'Keine Umfragen in dieser Ansicht.', 'vereins-plugin' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $umfragen as $p ) :
                $id = (int) $p['id'];
                ?>
                <tr>
                    <td>
                        <strong><a class="row-title" href="<?php echo esc_url( $this->url( [ 'edit' => $id ] ) ); ?>"><?php echo esc_html( $p['title'] ); ?></a></strong>
                        <div class="row-actions visible">
                            <span><a href="<?php echo esc_url( $this->url( [ 'ergebnis' => $id ] ) ); ?>"><?php echo $p['kind'] === 'entry' ? esc_html__( 'Meldeliste', 'vereins-plugin' ) : ( $p['kind'] === 'text' ? esc_html__( 'Vorschläge', 'vereins-plugin' ) : esc_html__( 'Ergebnis', 'vereins-plugin' ) ); ?></a> | </span>
                            <span><a href="<?php echo esc_url( $this->url( [ 'edit' => $id ] ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'vereins-plugin' ); ?></a> | </span>
                            <span><?php
                            if ( $p['status'] === 'open' ) {
                                $this->status_button( $id, 'closed', __( 'Beenden', 'vereins-plugin' ), 'button-link' );
                            } else {
                                $this->status_button( $id, 'open', $p['status'] === 'draft' ? __( 'Veröffentlichen', 'vereins-plugin' ) : __( 'Wieder öffnen', 'vereins-plugin' ), 'button-link' );
                            }
                            ?> | </span>
                            <span><button type="button" class="button-link vp-copy-code" data-code="<?php echo esc_attr( '[vp_umfragen id="' . $id . '"]' ); ?>" title="<?php esc_attr_e( 'Code für genau diese Umfrage kopieren – zum Einfügen auf einer eigenen Seite', 'vereins-plugin' ); ?>"><?php esc_html_e( 'Einbinden', 'vereins-plugin' ); ?></button> | </span>
                            <span class="trash">
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Umfrage samt allen Antworten löschen?', 'vereins-plugin' ) ); ?>');">
                                    <input type="hidden" name="action" value="vp_delete_poll">
                                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                                    <?php wp_nonce_field( 'vp_delete_poll_' . $id ); ?>
                                    <button class="button-link button-link-delete"><?php esc_html_e( 'Löschen', 'vereins-plugin' ); ?></button>
                                </form>
                            </span>
                        </div>
                    </td>
                    <td><span class="dashicons <?php echo esc_attr( self::kind_icon( $p['kind'] ) ); ?> vp-kind-icon" aria-hidden="true"></span><?php echo esc_html( self::kind_label( $p['kind'] ) ); ?></td>
                    <td><?php $this->status_badge( $p ); ?></td>
                    <td><?php echo (int) $this->model->voter_count( $id ); ?></td>
                    <td><?php echo $p['closes_at'] ? esc_html( mysql2date( 'd.m.Y H:i', $p['closes_at'] ) ) : '<span class="description">—</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="description" style="max-width:60em;">
            <?php esc_html_e( 'Auf der Website erscheinen alle offenen und beendeten Umfragen dort, wo [vp_umfragen] steht. Mit „Einbinden“ kopierst du den Code für eine einzelne Umfrage, etwa für eine eigene Seite.', 'vereins-plugin' ); ?>
        </p>
        <script>
        document.querySelectorAll('.vp-copy-code').forEach(function(b){
            b.addEventListener('click', function(){
                var text = b.getAttribute('data-code'), alt = b.textContent;
                var fertig = function(){ b.textContent = <?php echo wp_json_encode( __( 'Kopiert ✓', 'vereins-plugin' ) ); ?>; setTimeout(function(){ b.textContent = alt; }, 1600); };
                if (navigator.clipboard) { navigator.clipboard.writeText(text).then(fertig, function(){ window.prompt('', text); }); }
                else { window.prompt('', text); }
            });
        });
        </script>
        <?php
    }

    private function status_badge( array $p ): void {
        if ( $p['status'] === 'draft' ) {
            $klasse = 'pending';
            $text   = __( 'Entwurf', 'vereins-plugin' );
        } elseif ( $this->service->is_open( $p ) ) {
            $klasse = 'active';
            $text   = __( 'Offen', 'vereins-plugin' );
        } else {
            $klasse = 'inactive';
            $text   = __( 'Beendet', 'vereins-plugin' );
        }
        echo '<span class="vp-badge vp-badge--' . esc_attr( $klasse ) . '">' . esc_html( $text ) . '</span>';
    }

    private function status_button( int $id, string $status, string $label, string $class = 'button button-small' ): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
            <input type="hidden" name="action" value="vp_poll_status">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
            <?php wp_nonce_field( 'vp_poll_status_' . $id ); ?>
            <button class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    public static function kind_label( string $kind ): string {
        return match ( $kind ) {
            'date'  => __( 'Terminumfrage', 'vereins-plugin' ),
            'entry' => __( 'Wettkampfmeldung', 'vereins-plugin' ),
            'text'  => __( 'Ideensammlung', 'vereins-plugin' ),
            default => __( 'Abstimmung', 'vereins-plugin' ),
        };
    }

    // ------------------------------------------------------------------
    // Formular
    // ------------------------------------------------------------------

    /** Arten, wie sie beim Anlegen angeboten werden – Kennung in der Adresse => Art. */
    public const NEW_KINDS = [ 'termin' => 'date', 'abstimmung' => 'choice', 'ideen' => 'text', 'meldung' => 'entry' ];

    /** Kurzbeschreibung je Art (Liste und Formular). */
    public static function kind_hint( string $kind ): string {
        return match ( $kind ) {
            'date'  => __( 'Je Terminvorschlag: passt / vielleicht / passt nicht.', 'vereins-plugin' ),
            'text'  => __( 'Vorschläge als Freitext, z. B. Slogans, Namen oder Wünsche.', 'vereins-plugin' ),
            'entry' => __( 'Starter mit Alters- und Gewichtsklasse für ein Turnier.', 'vereins-plugin' ),
            default => __( 'Auswahl aus Antworten – auch für Nominierungen.', 'vereins-plugin' ),
        };
    }

    /** Die vier Stufen „Ergebnis sichtbar“. */
    public static function results_choices( string $kind ): array {
        $was = $kind === 'text' ? __( 'Vorschläge', 'vereins-plugin' ) : __( 'Ergebnis', 'vereins-plugin' );
        return [
            'always'      => [ __( 'Sofort sichtbar', 'vereins-plugin' ),     sprintf( __( 'Alle sehen %s mit Namen, schon während es läuft.', 'vereins-plugin' ), $was ) ],
            'after_close' => [ __( 'Nach Abschluss sichtbar', 'vereins-plugin' ), sprintf( __( 'Alle sehen %s mit Namen, sobald es beendet ist.', 'vereins-plugin' ), $was ) ],
            'orga'        => [ __( 'Nur Orgateam', 'vereins-plugin' ),        sprintf( __( 'Nur das Orgateam sieht %s mit Namen.', 'vereins-plugin' ), $was ) ],
            'nobody'      => [ __( 'Niemand (geheim)', 'vereins-plugin' ),    __( 'Keine Namen, für niemanden. Das Orgateam sieht nur die Zahlen bzw. die Vorschläge ohne Namen.', 'vereins-plugin' ) ],
        ];
    }

    private function render_form( ?array $poll ): void {
        $art  = sanitize_key( $_GET['art'] ?? '' );
        $kind = $poll ? $poll['kind'] : ( self::NEW_KINDS[ $art ] ?? '' );
        if ( ! $kind ) {
            $this->render_kind_picker();
            return;
        }
        $p = $poll ?? [
            'id' => 0, 'kind' => $kind, 'title' => '', 'description' => '', 'status' => 'draft',
            'closes_at' => '', 'multiple' => 0, 'allow_maybe' => 1,
            'results' => $kind === 'choice' ? 'after_close' : 'always', 'login_required' => $kind === 'text' ? 1 : 0,
            'event_date' => '', 'classes' => null, 'purge_days' => 30, 'max_texts' => 3, 'dokume' => 0,
        ];
        $p['results'] = $p['results'] === 'after_vote' ? 'always' : $p['results'];

        $vorlagen = ClassTemplates::all();
        $klassen  = $poll ? PollModel::classes( $poll ) : [];
        if ( ! $klassen ) {
            $klassen = reset( $vorlagen )['classes'] ?? [];
        }
        $optionen    = $poll ? $this->model->options( (int) $poll['id'] ) : [];
        $hat_stimmen = $poll && $this->model->voter_count( (int) $poll['id'] ) > 0;
        $dt = static fn( $v ) => $v ? str_replace( ' ', 'T', substr( (string) $v, 0, 16 ) ) : '';
        $ende = match ( $kind ) {
            'entry' => __( 'Meldeschluss', 'vereins-plugin' ),
            'text'  => __( 'Sammlung endet', 'vereins-plugin' ),
            default => __( 'Abstimmung endet', 'vereins-plugin' ),
        };
        ?>
        <h1><?php echo esc_html( ( $poll ? __( 'Bearbeiten:', 'vereins-plugin' ) : __( 'Neu:', 'vereins-plugin' ) ) . ' ' . self::kind_label( $kind ) ); ?></h1>
        <p><a href="<?php echo esc_url( $this->url() ); ?>">&larr; <?php esc_html_e( 'Zur Übersicht', 'vereins-plugin' ); ?></a></p>
        <?php $this->render_notices(); ?>
        <p class="description"><?php echo esc_html( self::kind_hint( $kind ) ); ?></p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vp-form-card vp-form-card--wide" id="vp-poll-form">
            <input type="hidden" name="action" value="vp_save_poll">
            <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
            <input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>">
            <?php wp_nonce_field( 'vp_save_poll' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="vp-poll-title"><?php esc_html_e( 'Titel', 'vereins-plugin' ); ?></label></th>
                    <td><input type="text" class="regular-text" id="vp-poll-title" name="title" required value="<?php echo esc_attr( $p['title'] ); ?>" placeholder="<?php echo esc_attr( match ( $kind ) {
                        'date'  => __( 'z. B. Wann machen wir die Weihnachtsfeier?', 'vereins-plugin' ),
                        'text'  => __( 'z. B. Slogan für das Vereinsshirt', 'vereins-plugin' ),
                        'entry' => __( 'z. B. Bezirkseinzelmeisterschaft U11/U13', 'vereins-plugin' ),
                        default => __( 'z. B. Nachwuchspreis 2026', 'vereins-plugin' ),
                    } ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="vp-poll-desc"><?php esc_html_e( 'Beschreibung', 'vereins-plugin' ); ?></label></th>
                    <td><textarea id="vp-poll-desc" name="description" rows="3" class="large-text"><?php echo esc_textarea( $p['description'] ); ?></textarea></td>
                </tr>

                <?php if ( $kind === 'entry' ) : ?>
                <tr>
                    <th><label for="vp-poll-event-date"><?php esc_html_e( 'Wettkampftag', 'vereins-plugin' ); ?> <span style="color:#b32d2e">*</span></label></th>
                    <td>
                        <input type="date" id="vp-poll-event-date" name="event_date" required value="<?php echo esc_attr( (string) $p['event_date'] ); ?>">
                        <p class="description"><?php esc_html_e( 'Pflichtangabe. Sein Jahr bestimmt die Jahrgänge (Alter = Wettkampfjahr − Geburtsjahr). Ohne Wettkampftag lassen sich keine Klassen speichern, und die Meldung bleibt ein unsichtbarer Entwurf.', 'vereins-plugin' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Startende Klassen', 'vereins-plugin' ); ?> <span style="color:#b32d2e">*</span></th>
                    <td>
                        <p>
                            <label for="vp-poll-template"><?php esc_html_e( 'Vorlage', 'vereins-plugin' ); ?></label>
                            <select id="vp-poll-template">
                                <?php foreach ( $vorlagen as $slug => $t ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>" data-classes="<?php echo esc_attr( wp_json_encode( $t['classes'] ) ); ?>"><?php echo esc_html( $t['name'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="button" id="vp-poll-template-load"><?php esc_html_e( 'Klassen aus Vorlage übernehmen', 'vereins-plugin' ); ?></button>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . ClassesAdmin::SLUG ) ); ?>"><?php esc_html_e( 'Vorlagen verwalten', 'vereins-plugin' ); ?></a>
                        </p>
                        <p class="description" style="max-width:60em;"><?php esc_html_e( 'Mindestens eine Klasse muss angehakt sein. Klassen, die nicht ausgeschrieben sind, abhaken; Jahrgänge und Gewichtsklassen so eintragen, wie sie in der Ausschreibung stehen – das gilt nur für diese Meldung.', 'vereins-plugin' ); ?></p>
                        <?php ClassesAdmin::editor( 'classes', $klassen, 'year' ); ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Meldeweg', 'vereins-plugin' ); ?></th>
                    <td>
                        <label><input type="checkbox" name="dokume" value="1" <?php checked( ! empty( $p['dokume'] ) ); ?>> <?php esc_html_e( 'Gemeldet wird über DokuMe – Judopass abfragen', 'vereins-plugin' ); ?></label>
                        <p class="description"><?php esc_html_e( 'In DokuMe lassen sich nur Judoka mit digitalem Judopass melden. Mit Haken fragt das Formular bei jedem Starter danach, und die Meldeliste zeigt, wer keinen hat.', 'vereins-plugin' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vp-poll-purge"><?php esc_html_e( 'Meldungen löschen', 'vereins-plugin' ); ?></label></th>
                    <td>
                        <input type="number" id="vp-poll-purge" name="purge_days" min="0" max="365" style="width:6em" value="<?php echo (int) $p['purge_days']; ?>">
                        <?php esc_html_e( 'Tage nach dem Wettkampf', 'vereins-plugin' ); ?>
                        <p class="description"><?php esc_html_e( 'Danach werden Namen, Jahrgänge und Gewichtsklassen der Starter gelöscht; die Umfrage selbst bleibt stehen. 0 = nicht automatisch löschen.', 'vereins-plugin' ); ?></p>
                    </td>
                </tr>
                <?php endif; ?>

                <?php if ( $kind === 'text' ) : ?>
                <tr>
                    <th><label for="vp-poll-max-texts"><?php esc_html_e( 'Vorschläge je Person', 'vereins-plugin' ); ?></label></th>
                    <td>
                        <input type="number" id="vp-poll-max-texts" name="max_texts" min="1" max="10" style="width:5em" value="<?php echo (int) $p['max_texts']; ?>">
                        <p class="description"><?php printf( esc_html__( 'Jeder Vorschlag bis %d Zeichen. Unpassende Vorschläge kannst du in der Auswertung löschen; dort lassen sich die Vorschläge auch als Abstimmung übernehmen.', 'vereins-plugin' ), PollService::TEXT_MAX ); ?></p>
                    </td>
                </tr>
                <?php endif; ?>

                <?php if ( in_array( $kind, [ 'date', 'choice' ], true ) ) : ?>
                <tr>
                    <th><?php echo $kind === 'date' ? esc_html__( 'Terminvorschläge', 'vereins-plugin' ) : esc_html__( 'Antworten', 'vereins-plugin' ); ?></th>
                    <td>
                        <p class="description"><?php echo $kind === 'date'
                            ? esc_html__( 'Je Zeile ein Termin: Beginn, optional Ende (Uhrzeit) und ein Zusatz.', 'vereins-plugin' )
                            : esc_html__( 'Je Zeile eine Antwort – bei einer Nominierung je Zeile eine Person.', 'vereins-plugin' ); ?></p>
                        <div id="vp-poll-options">
                            <?php
                            $zeilen = $optionen ?: [];
                            $leer   = max( 2, 4 - count( $zeilen ) );
                            for ( $i = 0; $i < $leer; $i++ ) {
                                $zeilen[] = [ 'id' => 0, 'label' => '', 'starts_at' => '', 'ends_at' => '' ];
                            }
                            foreach ( $zeilen as $i => $o ) :
                                ?>
                                <div class="vp-poll-option" style="display:flex;gap:6px;margin-bottom:6px;align-items:center;flex-wrap:wrap;">
                                    <input type="hidden" name="options[<?php echo $i; ?>][id]" value="<?php echo (int) $o['id']; ?>">
                                    <?php if ( $kind === 'date' ) : ?>
                                        <input type="datetime-local" name="options[<?php echo $i; ?>][starts_at]" value="<?php echo esc_attr( $dt( $o['starts_at'] ) ); ?>" aria-label="<?php esc_attr_e( 'Beginn', 'vereins-plugin' ); ?>">
                                        <input type="time" name="options[<?php echo $i; ?>][ends_at]" value="<?php echo esc_attr( $o['ends_at'] ? substr( (string) $o['ends_at'], 11, 5 ) : '' ); ?>" aria-label="<?php esc_attr_e( 'Ende (Uhrzeit, optional)', 'vereins-plugin' ); ?>">
                                    <?php endif; ?>
                                    <input type="text" class="regular-text" name="options[<?php echo $i; ?>][label]" value="<?php echo esc_attr( $o['label'] ); ?>" placeholder="<?php echo $kind === 'date' ? esc_attr__( 'Zusatz (optional)', 'vereins-plugin' ) : esc_attr__( 'Antwort', 'vereins-plugin' ); ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button" id="vp-poll-add"><?php esc_html_e( 'Weitere Zeile', 'vereins-plugin' ); ?></button>
                        <?php if ( $hat_stimmen ) : ?>
                            <p class="description"><?php esc_html_e( 'Wird eine Zeile geleert, entfallen auch die Antworten darauf.', 'vereins-plugin' ); ?></p>
                        <?php endif; ?>
                        <p>
                            <?php if ( $kind === 'choice' ) : ?>
                                <label><input type="checkbox" name="multiple" value="1" <?php checked( ! empty( $p['multiple'] ) ); ?>> <?php esc_html_e( 'Mehrere Antworten erlaubt', 'vereins-plugin' ); ?></label>
                            <?php else : ?>
                                <label><input type="checkbox" name="allow_maybe" value="1" <?php checked( ! empty( $p['allow_maybe'] ) ); ?>> <?php esc_html_e( '„Vielleicht“ erlaubt', 'vereins-plugin' ); ?></label>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>

                <?php if ( $kind !== 'entry' ) : ?>
                <tr>
                    <th><?php echo $kind === 'text' ? esc_html__( 'Vorschläge sichtbar', 'vereins-plugin' ) : esc_html__( 'Ergebnis sichtbar', 'vereins-plugin' ); ?></th>
                    <td>
                        <fieldset>
                        <?php foreach ( self::results_choices( $kind ) as $wert => [ $label, $hilfe ] ) : ?>
                            <label style="display:block;margin-bottom:6px;">
                                <input type="radio" name="results" value="<?php echo esc_attr( $wert ); ?>" <?php checked( $p['results'], $wert ); ?>>
                                <strong><?php echo esc_html( $label ); ?></strong> – <span class="description"><?php echo esc_html( $hilfe ); ?></span>
                            </label>
                        <?php endforeach; ?>
                        </fieldset>
                    </td>
                </tr>
                <?php else : ?>
                <tr>
                    <th><?php esc_html_e( 'Sichtbarkeit', 'vereins-plugin' ); ?></th>
                    <td><p class="description" style="margin-top:0;"><?php esc_html_e( 'Die Meldungen sieht nur das Orgateam (hier im Backend). Jeder Melder sieht auf der Website nur seine eigenen Starter. Gäste müssen für Rückfragen eine E-Mail-Adresse oder Telefonnummer angeben.', 'vereins-plugin' ); ?></p></td>
                </tr>
                <?php endif; ?>

                <tr>
                    <th><?php esc_html_e( 'Teilnahme', 'vereins-plugin' ); ?></th>
                    <td>
                        <label><input type="checkbox" name="login_required" value="1" <?php checked( ! empty( $p['login_required'] ) ); ?>> <?php esc_html_e( 'Nur angemeldete Mitglieder', 'vereins-plugin' ); ?></label>
                        <p class="description"><?php esc_html_e( 'Ohne Haken kann jeder mit dem Link teilnehmen und gibt nur seinen Namen an – dann kann sich auch jemand mehrfach unter anderen Namen eintragen.', 'vereins-plugin' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="vp-poll-close"><?php echo esc_html( $ende ); ?></label></th>
                    <td><input type="datetime-local" id="vp-poll-close" name="closes_at" value="<?php echo esc_attr( $dt( $p['closes_at'] ) ); ?>"> <span class="description"><?php esc_html_e( 'optional – danach ist keine Teilnahme mehr möglich', 'vereins-plugin' ); ?></span></td>
                </tr>
                <tr>
                    <th><label for="vp-poll-status"><?php esc_html_e( 'Status', 'vereins-plugin' ); ?></label></th>
                    <td>
                        <select id="vp-poll-status" name="status">
                            <option value="draft" <?php selected( $p['status'], 'draft' ); ?>><?php esc_html_e( 'Entwurf – noch nicht sichtbar', 'vereins-plugin' ); ?></option>
                            <option value="open" <?php selected( $p['status'], 'open' ); ?>><?php esc_html_e( 'Offen – auf der Website sichtbar', 'vereins-plugin' ); ?></option>
                            <option value="closed" <?php selected( $p['status'], 'closed' ); ?>><?php esc_html_e( 'Beendet', 'vereins-plugin' ); ?></option>
                        </select>
                        <?php if ( $kind === 'entry' ) : ?>
                            <p class="description"><?php esc_html_e( '„Offen“ geht erst mit Wettkampftag und mindestens einer Klasse – sonst wird als Entwurf gespeichert.', 'vereins-plugin' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <p><button class="button button-primary"><?php esc_html_e( 'Speichern', 'vereins-plugin' ); ?></button></p>
        </form>
        <script>
        (function(){
            var laden = document.getElementById('vp-poll-template-load');
            if (laden) {
                laden.addEventListener('click', function(){
                    var o = document.getElementById('vp-poll-template').selectedOptions[0];
                    if (o && window.vpPollClassesLoad) { window.vpPollClassesLoad(JSON.parse(o.getAttribute('data-classes'))); }
                });
            }
            var plus = document.getElementById('vp-poll-add');
            if (plus) {
                plus.addEventListener('click', function(){
                    var box = document.getElementById('vp-poll-options');
                    var zeilen = box.querySelectorAll('.vp-poll-option');
                    var neu = zeilen[zeilen.length - 1].cloneNode(true);
                    var i = zeilen.length;
                    neu.querySelectorAll('input').forEach(function(e){
                        e.name = e.name.replace(/options\[\d+\]/, 'options[' + i + ']');
                        e.value = e.type === 'hidden' ? '0' : '';
                    });
                    box.appendChild(neu);
                });
            }
        })();
        </script>
        <?php
    }

    /** Symbol je Art (Dashicons). */
    public static function kind_icon( string $kind ): string {
        return match ( $kind ) {
            'date'  => 'dashicons-calendar-alt',
            'text'  => 'dashicons-lightbulb',
            'entry' => 'dashicons-awards',
            default => 'dashicons-yes-alt',
        };
    }

    /** „Neue Umfrage“: erst die Art wählen – als Kacheln. */
    private function render_kind_picker(): void {
        ?>
        <h1><?php esc_html_e( 'Neue Umfrage', 'vereins-plugin' ); ?></h1>
        <p><a href="<?php echo esc_url( $this->url() ); ?>">&larr; <?php esc_html_e( 'Zur Übersicht', 'vereins-plugin' ); ?></a></p>
        <p style="font-size:14px;"><?php esc_html_e( 'Was möchtest du machen?', 'vereins-plugin' ); ?></p>
        <?php $this->render_kind_cards(); ?>
        <?php
    }

    /** Vier Kacheln, je Art eine – Überschrift als Ziel, kein Fachbegriff. */
    private function render_kind_cards(): void {
        $kacheln = [
            'termin'     => [ 'date',   __( 'Termin finden', 'vereins-plugin' ),       __( 'Ihr schlagt mehrere Termine vor, alle sagen, wann es passt – wie bei Doodle.', 'vereins-plugin' ) ],
            'abstimmung' => [ 'choice', __( 'Abstimmen', 'vereins-plugin' ),           __( 'Antworten zur Auswahl – auch für Nominierungen wie den Nachwuchspreis.', 'vereins-plugin' ) ],
            'ideen'      => [ 'text',   __( 'Ideen sammeln', 'vereins-plugin' ),       __( 'Mitglieder schreiben eigene Vorschläge, etwa Slogans fürs Vereinsshirt.', 'vereins-plugin' ) ],
            'meldung'    => [ 'entry',  __( 'Zum Wettkampf melden', 'vereins-plugin' ), __( 'Wer startet beim Turnier? Eltern melden ihre Kinder mit Alters- und Gewichtsklasse.', 'vereins-plugin' ) ],
        ];
        ?>
        <style>
        .vp-kinds { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; max-width: 1100px; margin: 8px 0 24px; }
        .vp-kind { display: flex; flex-direction: column; gap: 8px; padding: 20px; background: #fff; border: 1px solid #dcdcde; border-radius: 10px; text-decoration: none; color: #1d2327; transition: border-color .15s, box-shadow .15s; }
        .vp-kind:hover, .vp-kind:focus { border-color: #2271b1; box-shadow: 0 4px 14px rgba(0,0,0,.07); color: #1d2327; }
        .vp-kind__icon { width: 40px; height: 40px; border-radius: 50%; background: #f0f6fc; color: #2271b1; display: flex; align-items: center; justify-content: center; }
        .vp-kind__icon .dashicons { font-size: 22px; width: 22px; height: 22px; }
        .vp-kind__title { font-size: 15px; font-weight: 600; margin: 4px 0 0; }
        .vp-kind__text { margin: 0; color: #50575e; line-height: 1.5; }
        .vp-kind__type { margin-top: auto; padding-top: 6px; font-size: 12px; color: #8c8f94; }
        .vp-poll-empty { max-width: 1100px; padding: 8px 0; }
        .vp-poll-empty h2 { margin-bottom: 4px; }
        </style>
        <div class="vp-kinds">
            <?php foreach ( $kacheln as $art => [ $kind, $titel, $text ] ) : ?>
                <a class="vp-kind" href="<?php echo esc_url( $this->url( [ 'neu' => 1, 'art' => $art ] ) ); ?>">
                    <span class="vp-kind__icon"><span class="dashicons <?php echo esc_attr( self::kind_icon( $kind ) ); ?>" aria-hidden="true"></span></span>
                    <span class="vp-kind__title"><?php echo esc_html( $titel ); ?></span>
                    <span class="vp-kind__text"><?php echo esc_html( $text ); ?></span>
                    <span class="vp-kind__type"><?php echo esc_html( self::kind_label( $kind ) ); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function handle_save(): void {
        check_admin_referer( 'vp_save_poll' );
        $this->require_admin();

        $id   = (int) ( $_POST['id'] ?? 0 );
        $alt  = $id ? $this->model->find( $id ) : null;
        // Die Art steht seit dem Anlegen fest – ein geänderter Formularwert zählt nicht.
        $kind = $alt ? $alt['kind'] : ( in_array( $_POST['kind'] ?? '', PollModel::KINDS, true ) ? $_POST['kind'] : 'choice' );
        $status = sanitize_key( $_POST['status'] ?? 'draft' );
        $tag  = sanitize_text_field( wp_unslash( (string) ( $_POST['event_date'] ?? '' ) ) );
        $tag  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tag ) ? $tag : '';
        $dt   = static function ( $v ): ?string {
            $v = sanitize_text_field( wp_unslash( (string) $v ) );
            return $v !== '' ? str_replace( 'T', ' ', substr( $v, 0, 16 ) ) . ':00' : null;
        };

        $poll_id = $this->model->save( array_merge( [
            'id'             => $id,
            'kind'           => $kind,
            'title'          => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
            'description'    => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'status'         => $status,
            'closes_at'      => $dt( $_POST['closes_at'] ?? '' ),
            'multiple'       => ! empty( $_POST['multiple'] ),
            'allow_maybe'    => ! empty( $_POST['allow_maybe'] ),
            'results'        => sanitize_key( $_POST['results'] ?? 'always' ),
            'login_required' => ! empty( $_POST['login_required'] ),
            'max_texts'      => (int) ( $_POST['max_texts'] ?? 3 ),
            'created_by'     => get_current_user_id(),
        ], $kind === 'entry' ? [
            'results'    => 'orga',
            'event_date' => $tag ?: null,
            'classes'    => ClassesAdmin::parse_rows( (array) ( $_POST['classes'] ?? [] ), 'year', $tag ? (int) substr( $tag, 0, 4 ) : (int) gmdate( 'Y' ) ),
            'purge_days' => (int) ( $_POST['purge_days'] ?? 30 ),
            'dokume'     => ! empty( $_POST['dokume'] ),
        ] : [] ) );

        if ( in_array( $kind, [ 'date', 'choice' ], true ) ) {
            $this->model->save_options( $poll_id, self::parse_options( (array) ( $_POST['options'] ?? [] ), $kind ) );
        }

        // Eine Wettkampfmeldung ohne Wettkampftag oder Klasse kann niemand
        // ausfüllen – sie bleibt Entwurf, und das Formular sagt warum.
        if ( $kind === 'entry' && ( ! $tag || ! PollModel::classes( (array) $this->model->find( $poll_id ) ) ) ) {
            if ( $status === 'open' ) {
                $this->model->set_status( $poll_id, 'draft' );
            }
            wp_safe_redirect( $this->url( [ 'edit' => $poll_id, 'msg' => 'entry_incomplete' ] ) );
            exit;
        }
        wp_safe_redirect( $this->url( [ 'msg' => 'saved' ] ) );
        exit;
    }

    /**
     * Formularzeilen in Antwortmöglichkeiten übersetzen. Leere Zeilen fallen
     * weg – bei Terminumfragen zählt der Beginn, sonst der Text.
     */
    public static function parse_options( array $rows, string $kind ): array {
        $out = [];
        foreach ( $rows as $row ) {
            $label = sanitize_text_field( wp_unslash( (string) ( $row['label'] ?? '' ) ) );
            $start = sanitize_text_field( wp_unslash( (string) ( $row['starts_at'] ?? '' ) ) );
            $ende  = sanitize_text_field( wp_unslash( (string) ( $row['ends_at'] ?? '' ) ) );

            if ( $kind === 'date' ) {
                if ( $start === '' ) {
                    continue;
                }
                $start = str_replace( 'T', ' ', substr( $start, 0, 16 ) ) . ':00';
                $ende  = preg_match( '/^\d{2}:\d{2}$/', $ende ) ? substr( $start, 0, 10 ) . ' ' . $ende . ':00' : null;
                $out[] = [ 'id' => (int) ( $row['id'] ?? 0 ), 'label' => $label, 'starts_at' => $start, 'ends_at' => $ende ];
            } else {
                if ( $label === '' ) {
                    continue;
                }
                $out[] = [ 'id' => (int) ( $row['id'] ?? 0 ), 'label' => $label, 'starts_at' => null, 'ends_at' => null ];
            }
        }
        if ( $kind === 'date' ) {
            usort( $out, static fn( $a, $b ) => strcmp( $a['starts_at'], $b['starts_at'] ) );
        }
        return $out;
    }

    public function handle_status(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_poll_status_' . $id );
        $this->require_admin();
        $this->model->set_status( $id, sanitize_key( $_POST['status'] ?? '' ) );
        wp_safe_redirect( $this->url( [ 'msg' => 'status' ] ) );
        exit;
    }

    public function handle_delete(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_delete_poll_' . $id );
        $this->require_admin();
        $this->model->delete( $id );
        wp_safe_redirect( $this->url( [ 'msg' => 'deleted' ] ) );
        exit;
    }

    private function require_admin(): void {
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ), 403 );
        }
    }

    private function render_notices(): void {
        $texte = [
            'saved'   => __( 'Umfrage gespeichert.', 'vereins-plugin' ),
            'status'  => __( 'Status geändert.', 'vereins-plugin' ),
            'deleted' => __( 'Umfrage gelöscht.', 'vereins-plugin' ),
            'copied'  => __( 'Abstimmung als Entwurf angelegt – prüfen, dann auf „Offen“ stellen.', 'vereins-plugin' ),
        ];
        $msg = sanitize_key( $_GET['msg'] ?? '' );
        if ( $msg === 'entry_incomplete' ) {
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Als Entwurf gespeichert.', 'vereins-plugin' ) . '</strong> ' . esc_html__( 'Es fehlt noch der Wettkampftag oder mindestens eine angehakte Klasse. Ohne beides kann niemand melden, und die Meldung erscheint nicht auf der Website.', 'vereins-plugin' ) . '</p></div>';
        }
        if ( isset( $texte[ $msg ] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $texte[ $msg ] ) . '</p></div>';
        }
    }

    // ------------------------------------------------------------------
    // Auswertung
    // ------------------------------------------------------------------

    public static function option_text( array $o ): string {
        if ( ! empty( $o['starts_at'] ) ) {
            $text = date_i18n( 'D, d.m.Y H:i', strtotime( (string) $o['starts_at'] ) );
            if ( ! empty( $o['ends_at'] ) ) {
                $text .= '–' . substr( (string) $o['ends_at'], 11, 5 );
            }
            return $o['label'] !== '' ? $text . ' · ' . $o['label'] : $text;
        }
        return (string) $o['label'];
    }

    private function render_results( array $poll ): void {
        if ( $poll['kind'] === 'entry' ) {
            $this->render_entries( $poll );
            return;
        }
        if ( $poll['kind'] === 'text' ) {
            $this->render_texts( $poll );
            return;
        }
        $optionen = $this->model->options( (int) $poll['id'] );
        $erg      = $this->service->results( $poll );
        $anzahl   = $this->model->voter_count( (int) $poll['id'] );
        ?>
        <h1><?php echo esc_html( $poll['title'] ); ?></h1>
        <p><a href="<?php echo esc_url( $this->url() ); ?>">&larr; <?php esc_html_e( 'Zur Übersicht', 'vereins-plugin' ); ?></a></p>
        <p>
            <?php echo esc_html( self::kind_label( $poll['kind'] ) ); ?> ·
            <?php $this->status_badge( $poll ); ?> ·
            <?php echo esc_html( sprintf( _n( '%d Teilnahme', '%d Teilnahmen', $anzahl, 'vereins-plugin' ), $anzahl ) ); ?>
            <?php if ( ! empty( $poll['anonymous'] ) ) : ?> · <?php esc_html_e( 'geheim – ohne Namen', 'vereins-plugin' ); ?><?php endif; ?>
        </p>

        <?php if ( $poll['kind'] === 'date' && empty( $poll['anonymous'] ) && $anzahl ) : ?>
            <?php $this->render_matrix( $poll, $optionen ); ?>
        <?php endif; ?>

        <table class="vp-table">
            <thead>
                <tr>
                    <th><?php echo $poll['kind'] === 'date' ? esc_html__( 'Vorschlag', 'vereins-plugin' ) : esc_html__( 'Antwort', 'vereins-plugin' ); ?></th>
                    <th><?php echo $poll['kind'] === 'date' ? esc_html__( 'Passt', 'vereins-plugin' ) : esc_html__( 'Stimmen', 'vereins-plugin' ); ?></th>
                    <?php if ( $poll['kind'] === 'date' && ! empty( $poll['allow_maybe'] ) ) : ?><th><?php esc_html_e( 'Vielleicht', 'vereins-plugin' ); ?></th><?php endif; ?>
                    <?php if ( empty( $poll['anonymous'] ) ) : ?><th><?php esc_html_e( 'Wer', 'vereins-plugin' ); ?></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php
            $max = max( [ 0 ] + array_map( static fn( $e ) => $e['yes'], $erg ) );
            foreach ( $optionen as $o ) :
                $e = $erg[ (int) $o['id'] ];
                ?>
                <tr>
                    <td><?php echo esc_html( self::option_text( $o ) ); ?><?php echo ( $max && $e['yes'] === $max ) ? ' <span class="vp-badge vp-badge--active">' . esc_html__( 'vorn', 'vereins-plugin' ) . '</span>' : ''; ?></td>
                    <td><strong><?php echo (int) $e['yes']; ?></strong></td>
                    <?php if ( $poll['kind'] === 'date' && ! empty( $poll['allow_maybe'] ) ) : ?><td><?php echo (int) $e['maybe']; ?></td><?php endif; ?>
                    <?php if ( empty( $poll['anonymous'] ) ) : ?>
                        <td><?php echo esc_html( implode( ', ', $e['names']['yes'] ) ); ?>
                            <?php if ( $e['names']['maybe'] ) : ?><br><span class="description"><?php echo esc_html( sprintf( __( 'vielleicht: %s', 'vereins-plugin' ), implode( ', ', $e['names']['maybe'] ) ) ); ?></span><?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /** Terminumfrage: Tabelle Person × Vorschlag, wie man sie von Doodle kennt. */
    private function render_matrix( array $poll, array $optionen ): void {
        $stimmen = $this->model->votes( (int) $poll['id'] );
        $je      = [];
        foreach ( $stimmen as $v ) {
            $je[ (int) $v['person_id'] ][ (int) $v['option_id'] ] = $v['value'];
        }
        $namen  = Person::names( array_keys( $je ) );
        $zeichen = [ 'yes' => '✓', 'maybe' => '(✓)', 'no' => '–' ];
        ?>
        <h2><?php esc_html_e( 'Wer kann wann?', 'vereins-plugin' ); ?></h2>
        <div style="overflow-x:auto;margin-bottom:20px;">
        <table class="vp-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Person', 'vereins-plugin' ); ?></th>
                    <?php foreach ( $optionen as $o ) : ?><th style="white-space:nowrap;"><?php echo esc_html( self::option_text( $o ) ); ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $je as $person => $werte ) : ?>
                <tr>
                    <td><?php echo esc_html( $namen[ $person ] ?? '?' ); ?><?php echo Person::is_guest( $person ) ? ' <span class="description">(' . esc_html__( 'Gast', 'vereins-plugin' ) . ')</span>' : ''; ?></td>
                    <?php foreach ( $optionen as $o ) : $w = $werte[ (int) $o['id'] ] ?? 'no'; ?>
                        <td style="text-align:center;<?php echo $w === 'yes' ? 'background:#ecfdf5;' : ( $w === 'maybe' ? 'background:#fffbeb;' : '' ); ?>"><?php echo esc_html( $zeichen[ $w ] ); ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Wettkampfmeldung: Meldeliste
    // ------------------------------------------------------------------

    /**
     * Meldeliste fürs Orgateam.
     *
     * Jeder Starter hat einen eigenen Status. Oben der Hauptablauf der ersten
     * Meldung (sammeln → melden → gemeldet → bestätigt), der nicht mehr
     * zurückspringt. Was danach kommt – Nachmeldung, Ummeldung (Gewicht
     * geändert), Abmeldung (gestrichen) –, läuft in einem eigenen Bereich mit
     * Mini-Ablauf a) beim Veranstalter erledigen → b) Melder informieren →
     * c) Bestätigung. Unten alle übrigen Starter nach Status, darin nach Klasse.
     */
    private function render_entries( array $poll ): void {
        $service = new EntryService( $this->model );
        $liste   = $service->list_for_orga( $poll );
        $ab      = $service->list_for_orga( $poll, true );
        $melder  = $this->model->voter_count( (int) $poll['id'] );
        $faellig = EntryCleanup::due_date( $poll );
        ?>
        <h1><?php echo esc_html( $poll['title'] ); ?></h1>
        <p><a href="<?php echo esc_url( $this->url() ); ?>">&larr; <?php esc_html_e( 'Zur Übersicht', 'vereins-plugin' ); ?></a></p>
        <?php $this->entry_notice(); ?>
        <p>
            <?php esc_html_e( 'Wettkampfmeldung', 'vereins-plugin' ); ?> ·
            <?php $this->status_badge( $poll ); ?> ·
            <?php echo $poll['event_date'] ? esc_html( sprintf( __( 'Wettkampf am %s', 'vereins-plugin' ), mysql2date( 'd.m.Y', $poll['event_date'] ) ) ) . ' · ' : ''; ?>
            <?php echo esc_html( sprintf( _n( '%d Starter', '%d Starter', count( $liste ), 'vereins-plugin' ), count( $liste ) ) ); ?> ·
            <?php echo esc_html( sprintf( _n( '%d Melder', '%d Melder', $melder, 'vereins-plugin' ), $melder ) ); ?>
        </p>
        <?php if ( ! empty( $poll['purged_at'] ) ) : ?>
            <div class="notice notice-info inline"><p><?php echo esc_html( sprintf( __( 'Die Meldungen wurden am %s gelöscht (Aufbewahrungsfrist abgelaufen).', 'vereins-plugin' ), mysql2date( 'd.m.Y', $poll['purged_at'] ) ) ); ?></p></div>
        <?php elseif ( $faellig ) : ?>
            <p class="description"><?php echo esc_html( sprintf( __( 'Die Meldungen werden am %s automatisch gelöscht.', 'vereins-plugin' ), mysql2date( 'd.m.Y', $faellig ) ) ); ?></p>
        <?php endif; ?>
        <?php
        if ( ! $liste && ! $ab ) {
            echo '<p>' . esc_html__( 'Noch keine Meldungen.', 'vereins-plugin' ) . '</p>';
            return;
        }

        // Jeden Starter einordnen: Hauptmeldung oder Änderung danach
        $haupt_am = (string) ( $poll['submitted_at'] ?? '' );
        $haupt = $aend = [];
        foreach ( $liste as $z ) {
            $spaet = $haupt_am !== '' && ( empty( $z['submitted_at'] ) || $z['submitted_at'] > $haupt_am );
            if ( $spaet ) {
                $z['art'] = $z['prev_weight'] !== null ? 'changed' : 'late';
                $aend[] = $z;
            } else {
                $haupt[] = $z;
            }
        }
        foreach ( $ab as &$z ) {
            $z['art'] = 'withdrawn';
        }
        unset( $z );
        $aend_offen  = array_values( array_filter( $aend, static fn( $z ) => empty( $z['confirmed_at'] ) ) );
        $aend_fertig = array_values( array_filter( $aend, static fn( $z ) => ! empty( $z['confirmed_at'] ) ) );

        $this->render_entry_styles();
        $this->render_main_flow( $poll, $haupt );
        if ( $aend_offen || $ab ) {
            $this->render_changes( $poll, $aend_offen, $ab );
        }
        if ( $haupt_am !== '' ) {
            $this->render_by_status( $poll, $haupt, $aend_fertig );
        }
        if ( $haupt_am !== '' || $ab || array_filter( $liste, static fn( $z ) => ! empty( $z['submitted_at'] ) ) ) {
            $this->render_reset( $poll );
        }
        $this->render_edit_dialog( $poll );
    }

    /**
     * Ein Dialog für alle Starter: bearbeiten, Status korrigieren, entfernen.
     * Die Zeilen tragen ihre Daten in data-edit; das Script füllt den Dialog.
     */
    private function render_edit_dialog( array $poll ): void {
        $id      = (int) $poll['id'];
        $klassen = array_map( static fn( $c ) => [ 'key' => $c['key'], 'sex' => $c['sex'], 'near' => ! empty( $c['near'] ), 'm' => array_values( (array) $c['m'] ), 'w' => array_values( (array) $c['w'] ) ], PollModel::classes( $poll ) );
        $status  = [
            'open'      => __( 'nicht gemeldet', 'vereins-plugin' ),
            'submitted' => __( 'gemeldet – Melder noch nicht informiert', 'vereins-plugin' ),
            'notified'  => __( 'gemeldet und Melder informiert', 'vereins-plugin' ),
            'confirmed' => __( 'bestätigt', 'vereins-plugin' ),
        ];
        ?>
        <dialog class="vp-informdlg" id="vp-edit-entry">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="vp_poll_entry_edit">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <input type="hidden" name="entry" value="">
                <input type="hidden" name="old_status" value="">
                <?php wp_nonce_field( 'vp_poll_entry_edit_' . $id ); ?>
                <h2 style="margin-top:0;"><?php esc_html_e( 'Starter bearbeiten', 'vereins-plugin' ); ?></h2>
                <p class="description" data-edit-reporter></p>
                <table class="form-table" role="presentation" style="margin-top:0;">
                    <tr><th><label for="vp-e-first"><?php esc_html_e( 'Vorname', 'vereins-plugin' ); ?></label></th><td><input id="vp-e-first" name="first_name" class="regular-text" required></td></tr>
                    <tr><th><label for="vp-e-last"><?php esc_html_e( 'Nachname', 'vereins-plugin' ); ?></label></th><td><input id="vp-e-last" name="last_name" class="regular-text" required></td></tr>
                    <tr><th><label for="vp-e-year"><?php esc_html_e( 'Jahrgang', 'vereins-plugin' ); ?></label></th><td><input id="vp-e-year" name="birth_year" type="number" min="1920" max="2100" style="width:7em;" required>
                        <select name="sex" aria-label="<?php esc_attr_e( 'Geschlecht', 'vereins-plugin' ); ?>"><option value="m"><?php esc_html_e( 'männlich', 'vereins-plugin' ); ?></option><option value="w"><?php esc_html_e( 'weiblich', 'vereins-plugin' ); ?></option></select></td></tr>
                    <tr><th><label for="vp-e-class"><?php esc_html_e( 'Klasse / Gewicht', 'vereins-plugin' ); ?></label></th><td><select id="vp-e-class" name="class_key"></select> <select name="weight" aria-label="<?php esc_attr_e( 'Gewicht', 'vereins-plugin' ); ?>"></select></td></tr>
                    <?php if ( ! empty( $poll['dokume'] ) ) : ?>
                        <tr><th><label for="vp-e-pass"><?php esc_html_e( 'Digitaler Judopass', 'vereins-plugin' ); ?></label></th><td><select id="vp-e-pass" name="judopass"><option value="1"><?php esc_html_e( 'vorhanden', 'vereins-plugin' ); ?></option><option value="0"><?php esc_html_e( 'nicht vorhanden', 'vereins-plugin' ); ?></option></select></td></tr>
                    <?php endif; ?>
                    <tr><th><label for="vp-e-note"><?php esc_html_e( 'Hinweis', 'vereins-plugin' ); ?></label></th><td><input id="vp-e-note" name="note" class="regular-text"></td></tr>
                    <tr><th><label for="vp-e-status"><?php esc_html_e( 'Status', 'vereins-plugin' ); ?></label></th><td><select id="vp-e-status" name="status">
                        <?php foreach ( $status as $k => $t ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $t ); ?></option><?php endforeach; ?>
                    </select><p class="description"><?php esc_html_e( 'Zum Korrigieren – es gehen keine E-Mails raus.', 'vereins-plugin' ); ?></p></td></tr>
                </table>
                <p><label><input type="checkbox" name="correct" value="1">
                    <?php esc_html_e( 'Nur Korrektur (z. B. Tippfehler) – beim Veranstalter ändert sich nichts, der Status bleibt.', 'vereins-plugin' ); ?></label><br>
                    <span class="description"><?php esc_html_e( 'Ohne Haken läuft eine Änderung an einem schon gemeldeten Starter als Ummeldung (Gewicht) bzw. als Ab- und Nachmeldung (Name, Jahrgang, Klasse).', 'vereins-plugin' ); ?></span></p>
                <div class="vp-markbar__actions">
                    <button class="button button-primary" name="op" value="save"><?php esc_html_e( 'Speichern', 'vereins-plugin' ); ?></button>
                    <button class="button button-link-delete" name="op" value="remove" formnovalidate
                        onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Starter entfernen? War er schon gemeldet, läuft das als Abmeldung beim Veranstalter.', 'vereins-plugin' ) ) ); ?>);"><?php esc_html_e( 'Starter entfernen', 'vereins-plugin' ); ?></button>
                    <button type="button" class="button-link" data-edit-close><?php esc_html_e( 'Abbrechen', 'vereins-plugin' ); ?></button>
                </div>
            </form>
        </dialog>
        <script>
        (function () {
            var dlg = document.getElementById('vp-edit-entry');
            if (!dlg) { return; }
            var K = <?php echo wp_json_encode( array_values( $klassen ) ); ?>;
            var T = <?php echo wp_json_encode( [ 'near' => __( 'gewichtsnah', 'vereins-plugin' ), 'by' => __( 'Gemeldet von %s', 'vereins-plugin' ) ] ); ?>;
            var f = dlg.querySelector('form');
            var el = function (n) { return f.elements[n]; };
            function klassen(sel) {
                var sex = el('sex').value, c = el('class_key');
                c.textContent = '';
                K.forEach(function (k) { if (k.sex === 'mw' || k.sex === sex) { c.add(new Option(k.key, k.key)); } });
                if (sel) { c.value = sel; }
            }
            function gewichte(sel) {
                var k = K.filter(function (x) { return x.key === el('class_key').value; })[0], g = el('weight');
                g.textContent = '';
                if (!k || k.near) { g.add(new Option(T.near, '')); g.value = ''; return; }
                (k[el('sex').value] || []).forEach(function (w) { g.add(new Option((w.charAt(0) === '+' ? '+' : '−') + w.slice(1) + ' kg', w)); });
                if (sel !== undefined) { g.value = sel; }
            }
            el('sex').addEventListener('change', function () { klassen(el('class_key').value); gewichte(); });
            el('class_key').addEventListener('change', function () { gewichte(); });
            document.addEventListener('click', function (ev) {
                var b = ev.target.closest('[data-edit]');
                if (!b) { return; }
                var d = JSON.parse(b.dataset.edit);
                ['entry', 'first_name', 'last_name', 'birth_year', 'sex', 'note', 'status'].forEach(function (n) { el(n).value = d[n]; });
                el('old_status').value = d.status;
                if (el('judopass') && d.judopass !== null) { el('judopass').value = String(d.judopass); }
                el('correct').checked = false;
                klassen(d.class_key);
                gewichte(d.weight);
                dlg.querySelector('[data-edit-reporter]').textContent = T.by.replace('%s', d.reporter);
                dlg.showModal();
            });
            dlg.querySelector('[data-edit-close]').addEventListener('click', function () { dlg.close(); });
        })();
        </script>
        <?php
    }


    /** „Meldestatus zurücksetzen“ – eingeklappt, mit Rückfrage. */
    private function render_reset( array $poll ): void {
        $id = (int) $poll['id'];
        ?>
        <details class="vp-fold" style="max-width:1100px;margin:0 0 20px;">
            <summary class="description"><?php esc_html_e( 'Weitere Aktionen', 'vereins-plugin' ); ?></summary>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;"
                  onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Meldestatus wirklich zurücksetzen? Alle Haken, „gemeldet“, „informiert“ und „bestätigt“ gehen verloren, offene Abmeldungen verschwinden. Die Starter bleiben. Es gehen keine E-Mails raus.', 'vereins-plugin' ) ) ); ?>);">
                <input type="hidden" name="action" value="vp_poll_entries_reset">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <?php wp_nonce_field( 'vp_poll_entries_reset_' . $id ); ?>
                <button class="button button-link-delete"><?php esc_html_e( 'Meldestatus zurücksetzen', 'vereins-plugin' ); ?></button>
                <span class="description"><?php esc_html_e( 'Alles wieder auf „Meldungen sammeln“: keine Haken, nichts gemeldet, informiert oder bestätigt, offene Abmeldungen weg. Die Starter bleiben, es gehen keine E-Mails raus. Gedacht für Tests oder einen Neuanfang.', 'vereins-plugin' ); ?></span>
            </form>
        </details>
        <?php
    }

    private function render_entry_styles(): void {
        ?>
        <style>
        .vp-flow { display: flex; flex-wrap: wrap; margin: 16px 0 12px; max-width: 1100px; list-style: none; padding: 0; }
        .vp-flow__step { position: relative; flex: 1 1 180px; padding: 10px 14px 10px 30px; background: #f0f0f1; color: #50575e; margin: 0 6px 0 0;
            clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 50%, calc(100% - 14px) 100%, 0 100%, 14px 50%); }
        .vp-flow__step:first-child { padding-left: 14px; clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 50%, calc(100% - 14px) 100%, 0 100%); }
        .vp-flow__step strong { display: block; font-size: 14px; }
        .vp-flow__step span { font-size: 12px; }
        .vp-flow__step.is-done { background: #d1e7dd; color: #0a3622; }
        .vp-flow__step.is-current { background: #2271b1; color: #fff; }
        .vp-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 4px 20px 16px; max-width: 1100px; margin: 0 0 20px; }
        .vp-card--main { border-left: 4px solid #2271b1; }
        .vp-card--changes { border-left: 4px solid #dba617; }
        .vp-card h2 { font-size: 16px; margin: 14px 0 8px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .vp-card h3 { font-size: 13px; margin: 14px 0 6px; }
        .vp-tag { display: inline-block; font-size: 12px; font-weight: 600; padding: 2px 10px; border-radius: 999px; background: #f0f0f1; color: #50575e; }
        .vp-tag--late { background: #e5f0fa; color: #135e96; }
        .vp-tag--changed { background: #fcf0d1; color: #6b4a00; }
        .vp-tag--withdrawn { background: #fbe9ea; color: #8a2424; }
        .vp-tag--waiting { background: #f0f0f1; color: #50575e; }
        .vp-tag--confirmed { background: #d1e7dd; color: #0a3622; }
        .vp-mini { display: inline-flex; gap: 4px; align-items: center; white-space: nowrap; }
        .vp-mini span { font-size: 11px; padding: 1px 7px; border-radius: 999px; background: #f0f0f1; color: #8c8f94; }
        .vp-mini span.is-done { background: #d1e7dd; color: #0a3622; }
        .vp-mini span.is-next { background: #fcf0d1; color: #6b4a00; font-weight: 600; }
        .vp-legend { font-size: 12px; color: #50575e; margin: 0 0 6px; }
        .vp-group { border-top: 1px solid #f0f0f1; margin-top: 10px; }
        .vp-strike { text-decoration: line-through; color: #8c8f94; }
        .vp-markbar { position: sticky; bottom: 0; z-index: 5; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 10px 18px 14px; box-shadow: 0 -2px 10px rgba(0,0,0,.06); margin-top: 14px; }
        .vp-markbar p { margin: 6px 0; }
        .vp-markbar__actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 10px; }
        .vp-informdlg { border: 0; border-radius: 8px; padding: 22px 26px; max-width: 680px; width: calc(100% - 40px); box-shadow: 0 10px 40px rgba(0,0,0,.25); }
        .vp-informdlg::backdrop { background: rgba(0,0,0,.4); }
        .vp-mailwarn { color: #8a4b00; }
        .vp-effects { margin: 6px 0 12px; list-style: none; padding: 0; }
        .vp-effects li { margin: 0 0 6px; padding-left: 22px; position: relative; }
        .vp-effects li::before { content: "→"; position: absolute; left: 0; color: #2271b1; font-weight: 600; }
        .vp-card pre, .vp-informdlg pre { white-space: pre-wrap; background: #f6f7f7; border: 1px solid #dcdcde; padding: 10px; font: 13px/1.5 -apple-system, BlinkMacSystemFont, sans-serif; max-width: 640px; }
        details.vp-fold > summary { cursor: pointer; padding: 6px 0; }
        </style>
        <?php
    }

    /** Hauptablauf der ersten Meldung – springt nicht mehr zurück. */
    private function render_main_flow( array $poll, array $haupt ): void {
        $id       = (int) $poll['id'];
        $gemeldet = ! empty( $poll['submitted_at'] );
        $warten   = array_values( array_filter( $haupt, static fn( $z ) => ! empty( $z['submitted_at'] ) && empty( $z['confirmed_at'] ) ) );
        $fertig   = $gemeldet && ! $warten;
        $sammeln  = $this->service->is_open( $poll );
        $stufe    = $fertig ? 4 : ( $gemeldet ? 3 : ( $sammeln ? 1 : 2 ) );
        $csv      = wp_nonce_url( admin_url( 'admin-post.php?action=vp_poll_entries_csv&id=' . $id ), 'vp_poll_entries_csv_' . $id );
        $xlsx     = wp_nonce_url( admin_url( 'admin-post.php?action=vp_poll_entries_xlsx&id=' . $id ), 'vp_poll_entries_xlsx_' . $id );
        $ohne_pass = ! empty( $poll['dokume'] ) ? count( array_filter( $haupt, static fn( $z ) => isset( $z['judopass'] ) && ! (int) $z['judopass'] ) ) : 0;
        $stufen = [
            1 => [ __( 'Meldungen sammeln', 'vereins-plugin' ), $sammeln && ! empty( $poll['closes_at'] ) ? sprintf( __( 'bis %s', 'vereins-plugin' ), mysql2date( 'd.m. H:i', $poll['closes_at'] ) ) : sprintf( _n( '%d Starter', '%d Starter', count( $haupt ), 'vereins-plugin' ), count( $haupt ) ) ],
            2 => [ __( 'Beim Veranstalter melden', 'vereins-plugin' ), __( 'Excel schicken oder DokuMe', 'vereins-plugin' ) ],
            3 => [ __( 'Gemeldet', 'vereins-plugin' ), $gemeldet ? sprintf( __( 'am %s · Melder informiert', 'vereins-plugin' ), mysql2date( 'd.m.', $poll['submitted_at'] ) ) : __( 'Melder informiert', 'vereins-plugin' ) ],
            4 => [ __( 'Bestätigt', 'vereins-plugin' ), $fertig && ! empty( $poll['confirmed_at'] ) ? mysql2date( 'd.m.', $poll['confirmed_at'] ) : __( 'vom Veranstalter', 'vereins-plugin' ) ],
        ];
        ?>
        <div class="vp-card vp-card--main">
            <h2><?php esc_html_e( 'Hauptmeldung', 'vereins-plugin' ); ?></h2>
            <ol class="vp-flow" aria-label="<?php esc_attr_e( 'Ablauf der Hauptmeldung', 'vereins-plugin' ); ?>">
                <?php foreach ( $stufen as $nr => [ $titel, $info ] ) : $erledigt = $nr < $stufe || ( $nr === 4 && $fertig ); ?>
                    <li class="vp-flow__step<?php echo $erledigt ? ' is-done' : ( $nr === $stufe ? ' is-current' : '' ); ?>"<?php echo $nr === $stufe && ! $erledigt ? ' aria-current="step"' : ''; ?>>
                        <strong><?php echo ( $erledigt ? '✓ ' : $nr . '. ' ) . esc_html( $titel ); ?></strong>
                        <span><?php echo esc_html( $info ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>

            <?php if ( ! $gemeldet ) : ?>
                <h3><?php esc_html_e( 'Nächster Schritt: beim Veranstalter melden', 'vereins-plugin' ); ?></h3>
                <?php if ( $sammeln ) : ?>
                    <p class="description"><?php esc_html_e( 'Die Meldung ist noch offen – meist meldest du nach dem Meldeschluss. Wer danach dazukommt, läuft als Nachmeldung.', 'vereins-plugin' ); ?></p>
                <?php endif; ?>
                <ol>
                    <li><?php esc_html_e( 'Meldeliste holen:', 'vereins-plugin' ); ?>
                        <a class="button button-primary" href="<?php echo esc_url( $xlsx ); ?>"><?php esc_html_e( 'BJV-Meldeliste (Excel)', 'vereins-plugin' ); ?></a>
                        <a class="button" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Alles als CSV', 'vereins-plugin' ); ?></a></li>
                    <li><?php esc_html_e( 'Excel (Kampfrichter ergänzen) an den Veranstalter schicken – oder die Starter in DokuMe eintragen.', 'vereins-plugin' ); ?></li>
                    <li><?php esc_html_e( 'Unten abhaken, wen du gemeldet hast. Jeder Haken wird sofort gespeichert – ohne Mail, ohne Statusänderung.', 'vereins-plugin' ); ?></li>
                    <li><?php esc_html_e( '„Weiter: Status ‚Gemeldet‘ setzen“ – dort siehst du die Empfänger und entscheidest, ob E-Mails rausgehen.', 'vereins-plugin' ); ?></li>
                </ol>
                <?php if ( $ohne_pass ) : ?>
                    <p><strong style="color:#b32d2e;"><?php echo esc_html( sprintf( _n( '%d Starter hat keinen digitalen Judopass – in DokuMe nicht meldbar.', '%d Starter haben keinen digitalen Judopass – in DokuMe nicht meldbar.', $ohne_pass, 'vereins-plugin' ), $ohne_pass ) ); ?></strong></p>
                <?php endif; ?>
                <?php $this->tick_form( $poll, 'main', $haupt, [] ); ?>
            <?php else : ?>
                <?php if ( $warten ) : ?>
                    <h3><?php echo esc_html( sprintf( _n( 'Nächster Schritt: Bestätigung vom Veranstalter – %d Starter wartet', 'Nächster Schritt: Bestätigung vom Veranstalter – %d Starter warten', count( $warten ), 'vereins-plugin' ), count( $warten ) ) ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Kommt die Bestätigung (Mail, Teilnehmerliste oder Status in DokuMe), hier vermerken. Die Starter stehen unten unter „Wartet auf Bestätigung“.', 'vereins-plugin' ); ?></p>
                    <?php $this->confirm_form( $poll, $warten, __( 'Hauptmeldung als bestätigt vermerken', 'vereins-plugin' ) ); ?>
                <?php else : ?>
                    <p><strong>✓ <?php esc_html_e( 'Hauptmeldung gemeldet und bestätigt.', 'vereins-plugin' ); ?></strong></p>
                <?php endif; ?>
                <p><?php esc_html_e( 'Meldeliste (aktueller Stand):', 'vereins-plugin' ); ?>
                    <a class="button button-small" href="<?php echo esc_url( $xlsx ); ?>"><?php esc_html_e( 'BJV-Meldeliste (Excel)', 'vereins-plugin' ); ?></a>
                    <a class="button button-small" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Alles als CSV', 'vereins-plugin' ); ?></a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Nachmeldungen, Ummeldungen, Abmeldungen – jeweils mit Mini-Ablauf. */
    private function render_changes( array $poll, array $aend, array $ab ): void {
        $offen = count( $aend ) + count( $ab );
        $warten = array_values( array_filter( $aend, static fn( $z ) => ! empty( $z['submitted_at'] ) && ! empty( $z['notified_at'] ) && empty( $z['confirmed_at'] ) ) );
        ?>
        <div class="vp-card vp-card--changes">
            <h2><?php esc_html_e( 'Änderungen nach der Meldung', 'vereins-plugin' ); ?> <span class="vp-tag vp-tag--changed"><?php echo esc_html( sprintf( _n( '%d offen', '%d offen', $offen, 'vereins-plugin' ), $offen ) ); ?></span></h2>
            <p class="vp-legend"><?php esc_html_e( 'Jede Änderung läuft für sich:', 'vereins-plugin' ); ?>
                <span class="vp-mini"><span>a</span></span> <?php esc_html_e( 'beim Veranstalter erledigen (abhaken)', 'vereins-plugin' ); ?> →
                <span class="vp-mini"><span>b</span></span> <?php esc_html_e( 'Melder informieren', 'vereins-plugin' ); ?> →
                <span class="vp-mini"><span>c</span></span> <?php esc_html_e( 'Bestätigung vom Veranstalter (nicht bei Abmeldungen)', 'vereins-plugin' ); ?></p>
            <?php $this->tick_form( $poll, 'changes', $aend, $ab ); ?>
            <?php if ( $warten ) : ?>
                <h3><?php echo esc_html( sprintf( _n( 'c) Bestätigung: %d Änderung wartet', 'c) Bestätigung: %d Änderungen warten', count( $warten ), 'vereins-plugin' ), count( $warten ) ) ); ?></h3>
                <?php $this->confirm_form( $poll, $warten, __( 'Änderungen als bestätigt vermerken', 'vereins-plugin' ) ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Alle übrigen Starter nach Status, darin nach Klasse. */
    private function render_by_status( array $poll, array $haupt, array $aend_fertig ): void {
        $warten = array_values( array_filter( $haupt, static fn( $z ) => ! empty( $z['submitted_at'] ) && empty( $z['confirmed_at'] ) ) );
        $best   = array_merge( array_values( array_filter( $haupt, static fn( $z ) => ! empty( $z['confirmed_at'] ) ) ), $aend_fertig );
        $nicht  = array_values( array_filter( $haupt, static fn( $z ) => empty( $z['submitted_at'] ) ) );
        $best   = ( new EntryService( $this->model ) )->sort_for_orga( $poll, $best );
        ?>
        <div class="vp-card">
            <h2><?php esc_html_e( 'Starter nach Status', 'vereins-plugin' ); ?></h2>
            <?php if ( $warten ) : ?>
                <div class="vp-group">
                    <h2><span class="vp-tag vp-tag--waiting"><?php echo esc_html( sprintf( __( 'Gemeldet, wartet auf Bestätigung · %d', 'vereins-plugin' ), count( $warten ) ) ); ?></span></h2>
                    <?php $this->class_tables( $poll, $warten, 'list' ); ?>
                </div>
            <?php endif; ?>
            <?php if ( $best ) : ?>
                <div class="vp-group">
                    <details class="vp-fold">
                        <summary><span class="vp-tag vp-tag--confirmed"><?php echo esc_html( sprintf( __( 'Bestätigt · %d', 'vereins-plugin' ), count( $best ) ) ); ?></span>
                            <span class="description"><?php esc_html_e( 'nichts zu tun – aufklappen', 'vereins-plugin' ); ?></span></summary>
                        <?php $this->class_tables( $poll, $best, 'list' ); ?>
                    </details>
                </div>
            <?php endif; ?>
            <?php if ( ! $warten && ! $best && ! $nicht ) : ?>
                <p class="description"><?php esc_html_e( 'Alle Starter stehen oben bei den Änderungen.', 'vereins-plugin' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Abhaken (Schritt „melden“ bzw. a) mit Leiste und Abschluss-Dialog.
     * $modus 'main': Hauptmeldung; 'changes': Änderungen nach der Meldung.
     */
    private function tick_form( array $poll, string $modus, array $zeilen, array $ab ): void {
        $id = (int) $poll['id'];
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-tickform="<?php echo esc_attr( $modus ); ?>">
            <input type="hidden" name="action" value="vp_poll_entries_mark">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <input type="hidden" name="scope" value="<?php echo esc_attr( $modus ); ?>">
            <?php wp_nonce_field( 'vp_poll_entries_mark_' . $id ); ?>
            <?php
            if ( $modus === 'main' ) {
                echo '<h3>' . esc_html__( 'Abhakliste: Wen hast du schon gemeldet?', 'vereins-plugin' ) . '</h3>';
                echo '<p class="description" style="margin-top:0;">' . esc_html__( 'Das Plugin meldet nicht selbst. Du hakst ab, wen du beim Veranstalter gemeldet hast – einzeln oder mit dem Haken im Tabellenkopf die ganze Klasse.', 'vereins-plugin' ) . '</p>';
                $this->class_tables( $poll, $zeilen, 'tick' );
            } else {
                $gruppen = [
                    'late'      => [ __( 'Nachmeldung', 'vereins-plugin' ), __( 'nach der Meldung dazugekommen', 'vereins-plugin' ), array_values( array_filter( $zeilen, static fn( $z ) => $z['art'] === 'late' ) ) ],
                    'changed'   => [ __( 'Ummeldung', 'vereins-plugin' ), __( 'Gewicht nach der Meldung geändert', 'vereins-plugin' ), array_values( array_filter( $zeilen, static fn( $z ) => $z['art'] === 'changed' ) ) ],
                    'withdrawn' => [ __( 'Abmeldung', 'vereins-plugin' ), __( 'vom Melder gestrichen – beim Veranstalter abmelden', 'vereins-plugin' ), $ab ],
                ];
                foreach ( $gruppen as $art => [ $titel, $info, $reihe ] ) {
                    if ( ! $reihe ) {
                        continue;
                    }
                    echo '<div class="vp-group"><h2><span class="vp-tag vp-tag--' . esc_attr( $art ) . '">' . esc_html( $titel . ' · ' . count( $reihe ) ) . '</span> <span class="description" style="font-weight:normal;font-size:13px;">' . esc_html( $info ) . '</span></h2>';
                    $this->class_tables( $poll, $reihe, 'tick' );
                    echo '</div>';
                }
            }
            $this->tick_bar( $poll, $modus );
            ?>
        </form>
        <?php
    }

    /** Tabellen je Klasse; $modus 'tick' mit Haken, 'list' ohne. */
    private function class_tables( array $poll, array $zeilen, string $modus ): void {
        $klassen = array_column( PollModel::classes( $poll ), null, 'key' );
        $jahr    = EntryService::year( $poll );
        $gruppen = [];
        foreach ( $zeilen as $z ) {
            $gruppen[ $z['class_key'] . ' ' . $z['sex'] ][] = $z;
        }
        foreach ( $gruppen as $reihe ) {
            $k     = $klassen[ $reihe[0]['class_key'] ] ?? null;
            $titel = $reihe[0]['class_key'] . ' ' . ( $reihe[0]['sex'] === 'w' ? __( 'weiblich', 'vereins-plugin' ) : __( 'männlich', 'vereins-plugin' ) );
            echo '<h3>' . esc_html( $titel )
                . ( $k ? ' <span class="description" style="font-weight:normal;">' . esc_html( sprintf( __( 'Jahrgänge %s', 'vereins-plugin' ), ClassTemplates::years_label( $k, $jahr ) ) ) . '</span>' : '' )
                . ' <span class="description" style="font-weight:normal;">· ' . esc_html( sprintf( _n( '%d Starter', '%d Starter', count( $reihe ), 'vereins-plugin' ), count( $reihe ) ) ) . '</span></h3>';
            $this->entry_table( $poll, $reihe, $modus );
        }
    }

    /** Rückmeldung nach Abschluss oder Bestätigen. */
    private function entry_notice(): void {
        $msg   = sanitize_key( $_GET['msg'] ?? '' );
        $texte = [
            'submitted' => __( 'Gespeichert, Status „Gemeldet“.', 'vereins-plugin' ),
            'changes'   => __( 'Änderungen abgeschlossen.', 'vereins-plugin' ),
            'confirmed' => __( 'Bestätigung vermerkt.', 'vereins-plugin' ),
            'reset'     => __( 'Meldestatus zurückgesetzt.', 'vereins-plugin' ),
            'edited'    => __( 'Starter gespeichert.', 'vereins-plugin' ),
            'removed'   => __( 'Starter entfernt.', 'vereins-plugin' ),
        ];
        if ( $msg === 'edit_error' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( rawurldecode( (string) ( $_GET['vp_err'] ?? '' ) ) ) ) ) . '</p></div>';
            return;
        }
        if ( ! isset( $texte[ $msg ] ) ) {
            return;
        }
        $text = $texte[ $msg ];
        if ( isset( $_GET['vp_sent'] ) ) {
            $n     = (int) $_GET['vp_sent'];
            $text .= ' ' . sprintf( _n( '%d E-Mail verschickt.', '%d E-Mails verschickt.', $n, 'vereins-plugin' ), $n );
            $ohne  = sanitize_text_field( wp_unslash( rawurldecode( (string) ( $_GET['vp_skip'] ?? '' ) ) ) );
            if ( $ohne !== '' ) {
                $text .= ' ' . sprintf( __( 'Ohne E-Mail-Adresse, bitte selbst Bescheid geben: %s', 'vereins-plugin' ), $ohne );
            }
        }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
    }

    /** Name und Mail-Fähigkeit aller Melder dieser Meldung – für die Leiste. */
    private function melder_map( array $poll ): array {
        $ids = array_unique( array_map( static fn( $e ) => (int) $e['person_id'], array_merge( $this->model->entries( (int) $poll['id'] ), $this->model->withdrawn_entries( (int) $poll['id'] ) ) ) );
        $map = [];
        foreach ( $ids as $pid ) {
            $p    = Person::find( $pid );
            $mail = (string) ( $p['email'] ?? '' );
            $map[ $pid ] = [ 'name' => (string) ( $p['name'] ?? __( 'Unbekannt', 'vereins-plugin' ) ), 'mail' => $p && $mail !== '' && ! MemberService::is_placeholder_email( $mail ) && is_email( $mail ) ];
        }
        return $map;
    }

    /**
     * Leiste unter der Abhakliste und Dialog zum Abschließen. Die Leiste
     * zeigt live, wer noch nicht informiert ist; der Dialog nennt die
     * Empfänger, und erst dort wird entschieden, ob E-Mails rausgehen.
     */
    private function tick_bar( array $poll, string $modus ): void {
        $haupt    = $modus === 'main';
        $absender = (string) wp_get_current_user()->display_name;
        $beispiele = [];
        foreach ( [ 'sent', 'withdrawn' ] as $art ) {
            if ( $haupt && $art === 'withdrawn' ) {
                continue;
            }
            foreach ( EntryMail::plan( $poll, $art, [], $absender, false ) as $m ) {
                if ( $m['email'] !== '' ) {
                    $beispiele[ $art ] = $m;
                    break;
                }
            }
        }
        $texte = [
            'count'      => __( '%1$d von %2$d abgehakt.', 'vereins-plugin' ),
            'pending'    => __( 'Noch nicht informiert: %s', 'vereins-plugin' ),
            'noneNew'    => __( 'Alle Melder der abgehakten Starter sind informiert.', 'vereins-plugin' ),
            'noneTicked' => __( 'Noch nichts abgehakt.', 'vereins-plugin' ),
            'withdrawn'  => __( 'abgemeldet: %s', 'vereins-plugin' ),
            'without'    => __( 'Ohne E-Mail-Adresse, bitte selbst Bescheid geben: %s', 'vereins-plugin' ),
            'send'       => $haupt ? __( 'Status setzen und %d E-Mails senden', 'vereins-plugin' ) : __( '%d E-Mails senden', 'vereins-plugin' ),
            'send1'      => $haupt ? __( 'Status setzen und 1 E-Mail senden', 'vereins-plugin' ) : __( '1 E-Mail senden', 'vereins-plugin' ),
            'saving'     => __( 'Haken wird gespeichert …', 'vereins-plugin' ),
            'saved'      => __( 'Haken gespeichert', 'vereins-plugin' ),
            'saveError'  => __( 'Haken konnte nicht gespeichert werden – Seite neu laden.', 'vereins-plugin' ),
        ];
        ?>
        <div class="vp-markbar" data-markbar>
            <p><strong data-count></strong> <span class="description" data-save-state aria-live="polite"></span></p>
            <p data-pending></p>
            <div class="vp-markbar__actions">
                <button type="button" class="button button-primary button-large" data-open><?php echo esc_html( $haupt ? __( 'Weiter: Status „Gemeldet“ setzen', 'vereins-plugin' ) : __( 'Weiter: b) Melder informieren', 'vereins-plugin' ) ); ?></button>
                <span class="description"><?php esc_html_e( 'Im nächsten Schritt siehst du die Empfänger und entscheidest, ob E-Mails rausgehen.', 'vereins-plugin' ); ?></span>
            </div>
        </div>
        <dialog class="vp-informdlg" data-dialog>
            <h2 style="margin-top:0;"><?php echo esc_html( $haupt ? __( 'Status „Gemeldet“ setzen', 'vereins-plugin' ) : __( 'Änderungen abschließen: Melder informieren', 'vereins-plugin' ) ); ?></h2>
            <p><?php echo esc_html( $haupt
                ? __( 'Die Hauptmeldung steht dann auf „Gemeldet“ – auch auf der Website. Sollen die Melder eine E-Mail bekommen?', 'vereins-plugin' )
                : __( 'Die abgehakten Änderungen sind beim Veranstalter erledigt. Sollen die Melder eine E-Mail bekommen? Erledigte Abmeldungen verschwinden danach aus der Liste.', 'vereins-plugin' ) ); ?></p>
            <div data-dlg-sent>
                <p><strong><?php esc_html_e( 'E-Mail „Meldung abgeschickt“ an:', 'vereins-plugin' ); ?></strong></p>
                <ul data-dlg-sent-names style="list-style:disc;margin-left:20px;"></ul>
            </div>
            <div data-dlg-withdrawn>
                <p><strong><?php esc_html_e( 'E-Mail „Abmeldung weitergegeben“ an:', 'vereins-plugin' ); ?></strong></p>
                <ul data-dlg-withdrawn-names style="list-style:disc;margin-left:20px;"></ul>
            </div>
            <p class="vp-mailwarn" data-dlg-without></p>
            <?php foreach ( $beispiele as $art => $b ) : ?>
                <details>
                    <summary><?php echo esc_html( $art === 'sent' ? __( 'Mail „Meldung abgeschickt“ ansehen (Beispiel)', 'vereins-plugin' ) : __( 'Mail „Abmeldung weitergegeben“ ansehen (Beispiel)', 'vereins-plugin' ) ); ?></summary>
                    <pre><strong><?php echo esc_html( $b['subject'] ); ?></strong>

<?php echo esc_html( $b['body'] ); ?></pre>
                </details>
            <?php endforeach; ?>
            <?php if ( $beispiele && current_user_can( 'manage_options' ) ) : ?>
                <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=vp-settings#wettkampf' ) ); ?>"><?php esc_html_e( 'Vorlagen in den Einstellungen ändern', 'vereins-plugin' ); ?></a></p>
            <?php endif; ?>
            <div class="vp-markbar__actions">
                <button class="button button-primary button-large" name="inform" value="mail" data-dlg-send></button>
                <button class="button" name="inform" value="nomail"><?php echo esc_html( $haupt ? __( 'Status setzen, keine E-Mail', 'vereins-plugin' ) : __( 'Ohne E-Mail abschließen', 'vereins-plugin' ) ); ?></button>
                <button type="button" class="button-link" data-dlg-close><?php esc_html_e( 'Abbrechen', 'vereins-plugin' ); ?></button>
            </div>
        </dialog>
        <script>
        (function () {
            var form = document.currentScript.closest('form');
            if (!form) { return; }
            var bar = form.querySelector('[data-markbar]');
            var dlg = form.querySelector('[data-dialog]');
            var M = <?php echo wp_json_encode( (object) $this->melder_map( $poll ) ); ?>;
            var T = <?php echo wp_json_encode( $texte ); ?>;
            var haupt = <?php echo $haupt ? 'true' : 'false'; ?>;
            var q = function (s) { return bar.querySelector(s); };
            var dq = function (s) { return dlg.querySelector(s); };
            var fmt = function (t) { var a = [].slice.call(arguments, 1), i = 0; return t.replace(/%(\d\$)?[sd]/g, function () { return a[i++]; }); };
            var boxen = function () { return form.querySelectorAll('input[data-tick]'); };
            function speichern(liste) {
                var fd = new FormData();
                fd.append('action', 'vp_poll_entries_tick');
                fd.append('id', form.querySelector('[name="id"]').value);
                fd.append('_wpnonce', form.querySelector('[name="_wpnonce"]').value);
                liste.forEach(function (b) { fd.append((b.dataset.kind === 'withdrawn' ? 'w' : '') + (b.checked ? 'on[]' : 'off[]'), b.value); });
                var st = q('[data-save-state]');
                st.textContent = T.saving;
                fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) { st.textContent = j && j.success ? '✓ ' + T.saved : T.saveError; })
                    .catch(function () { st.textContent = T.saveError; });
            }
            function liste(ul, namen) {
                ul.textContent = '';
                namen.forEach(function (n) { var li = document.createElement('li'); li.textContent = n; ul.appendChild(li); });
            }
            function rechnen() {
                var alle = boxen(), an = 0, sent = {}, ab = {};
                alle.forEach(function (b) {
                    if (!b.checked) { return; }
                    an++;
                    if (b.dataset.kind === 'withdrawn') { (ab[b.dataset.person] = ab[b.dataset.person] || []).push(b.dataset.first); }
                    else if (b.dataset.notified === '0') { (sent[b.dataset.person] = sent[b.dataset.person] || []).push(b.dataset.first); }
                });
                var mitS = [], mitA = [], ohne = [], offen = [];
                var pids = Object.keys(sent).concat(Object.keys(ab).filter(function (p) { return !sent[p]; }));
                pids.forEach(function (pid) {
                    var m = M[pid] || { name: '?', mail: false };
                    var teile = [];
                    if (sent[pid]) { teile.push(sent[pid].join(', ')); }
                    if (ab[pid]) { teile.push(fmt(T.withdrawn, ab[pid].join(', '))); }
                    var label = m.name + ' (' + teile.join('; ') + ')';
                    offen.push(label);
                    if (!m.mail) { ohne.push(label); return; }
                    if (sent[pid]) { mitS.push(m.name + ' (' + sent[pid].join(', ') + ')'); }
                    if (ab[pid]) { mitA.push(m.name + ' (' + ab[pid].join(', ') + ')'); }
                });
                q('[data-count]').textContent = fmt(T.count, an, alle.length);
                q('[data-pending]').textContent = !an ? T.noneTicked : (offen.length ? fmt(T.pending, offen.join(' · ')) : T.noneNew);
                var k = q('[data-open]');
                k.disabled = !an;
                k.hidden = !haupt && !offen.length;
                dq('[data-dlg-sent]').hidden = !mitS.length;
                liste(dq('[data-dlg-sent-names]'), mitS);
                dq('[data-dlg-withdrawn]').hidden = !mitA.length;
                liste(dq('[data-dlg-withdrawn-names]'), mitA);
                dq('[data-dlg-without]').textContent = ohne.length ? fmt(T.without, ohne.join(' · ')) : '';
                var n = mitS.length + mitA.length, send = dq('[data-dlg-send]');
                send.hidden = !n;
                send.textContent = n === 1 ? T.send1 : fmt(T.send, n);
            }
            form.querySelectorAll('.vp-check-all').forEach(function (alle) {
                var reihe = function () { return alle.closest('table').querySelectorAll('tbody input[data-tick]'); };
                var sync = function () { var b = reihe(), an = 0; b.forEach(function (x) { if (x.checked) { an++; } }); alle.checked = an === b.length; alle.indeterminate = an > 0 && an < b.length; };
                alle.addEventListener('change', function () { var b = reihe(); b.forEach(function (x) { x.checked = alle.checked; }); rechnen(); speichern(Array.prototype.slice.call(b)); });
                reihe().forEach(function (x) { x.addEventListener('change', function () { sync(); rechnen(); speichern([x]); }); });
                sync();
            });
            q('[data-open]').addEventListener('click', function () { rechnen(); dlg.showModal(); });
            dq('[data-dlg-close]').addEventListener('click', function () { dlg.close(); });
            rechnen();
        })();
        </script>
        <?php
    }

    /** Bestätigung vermerken – für die genannten Starter, mit Mail-Option. */
    private function confirm_form( array $poll, array $zeilen, string $label ): void {
        $id     = (int) $poll['id'];
        $melder = array_values( array_unique( array_map( static fn( $z ) => (int) $z['person_id'], $zeilen ) ) );
        $plan   = EntryMail::plan( $poll, 'confirmed', $melder, (string) wp_get_current_user()->display_name );
        $mit    = array_filter( $plan, static fn( $m ) => $m['email'] !== '' );
        $ohne   = array_filter( $plan, static fn( $m ) => $m['email'] === '' );
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="vp_poll_entries_state">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <?php foreach ( $zeilen as $z ) : ?><input type="hidden" name="ids[]" value="<?php echo (int) $z['id']; ?>"><?php endforeach; ?>
            <?php wp_nonce_field( 'vp_poll_entries_state_' . $id ); ?>
            <ul class="vp-effects">
                <li><?php echo esc_html( sprintf( _n( '%d Starter wird als bestätigt vermerkt – die Melder sehen auf der Website „bestätigt“.', '%d Starter werden als bestätigt vermerkt – die Melder sehen auf der Website „bestätigt“.', count( $zeilen ), 'vereins-plugin' ), count( $zeilen ) ) ); ?></li>
                <li>
                    <?php if ( $mit ) : ?>
                        <label><input type="checkbox" name="mail" value="1" checked>
                        <strong><?php echo esc_html( sprintf( _n( 'E-Mail „Meldung bestätigt“ an %d Melder:', 'E-Mail „Meldung bestätigt“ an %d Melder:', count( $mit ), 'vereins-plugin' ), count( $mit ) ) ); ?></strong></label>
                        <?php echo esc_html( implode( ' · ', array_map( static fn( $m ) => $m['name'] . ' (' . implode( ', ', $m['starters'] ) . ')', $mit ) ) ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Keine E-Mail – kein Melder mit E-Mail-Adresse.', 'vereins-plugin' ); ?>
                    <?php endif; ?>
                    <?php if ( $ohne ) : ?>
                        <br><span class="vp-mailwarn"><?php echo esc_html( sprintf( __( 'Ohne E-Mail-Adresse, bitte selbst Bescheid geben: %s', 'vereins-plugin' ), implode( ', ', array_column( $ohne, 'name' ) ) ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $mit ) : $erste = reset( $mit ); ?>
                        <details style="margin-top:6px;">
                            <summary><?php echo esc_html( sprintf( __( 'Mail ansehen (Beispiel für %s)', 'vereins-plugin' ), $erste['name'] ) ); ?></summary>
                            <pre><strong><?php echo esc_html( $erste['subject'] ); ?></strong>

<?php echo esc_html( $erste['body'] ); ?></pre>
                        </details>
                    <?php endif; ?>
                </li>
            </ul>
            <button class="button button-primary"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    /** Eine Klasse: Starter mit Melder, Status und – beim Abhaken – Haken. */
    private function entry_table( array $poll, array $zeilen, string $modus ): void {
        $tick   = $modus === 'tick';
        $dokume = ! empty( $poll['dokume'] );
        $gewicht = static fn( $w ) => $w !== '' ? esc_html( ClassTemplates::weight_label( $w ) ) : '<span class="description">' . esc_html__( 'gewichtsnah', 'vereins-plugin' ) . '</span>';
        ?>
        <table class="vp-table">
            <thead><tr>
                <?php if ( $tick ) : ?>
                    <th style="width:1%;white-space:nowrap;"><label><input type="checkbox" class="vp-check-all" aria-label="<?php esc_attr_e( 'alle in dieser Klasse abhaken', 'vereins-plugin' ); ?>"> <?php esc_html_e( 'Erledigt', 'vereins-plugin' ); ?></label></th>
                <?php endif; ?>
                <th><?php esc_html_e( 'Name', 'vereins-plugin' ); ?></th>
                <th><?php esc_html_e( 'Jahrgang', 'vereins-plugin' ); ?></th>
                <th><?php esc_html_e( 'Gewicht', 'vereins-plugin' ); ?></th>
                <th><?php esc_html_e( 'Gemeldet von', 'vereins-plugin' ); ?></th>
                <?php if ( $dokume ) : ?><th><?php esc_html_e( 'Judopass', 'vereins-plugin' ); ?></th><?php endif; ?>
                <th><?php esc_html_e( 'Hinweis', 'vereins-plugin' ); ?></th>
                <?php if ( $tick && ! empty( $zeilen[0]['art'] ) ) : ?><th><?php esc_html_e( 'Ablauf', 'vereins-plugin' ); ?></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ( $zeilen as $z ) :
                $ab   = ( $z['art'] ?? '' ) === 'withdrawn';
                $a    = $ab ? ! empty( $z['withdraw_done_at'] ) : ! empty( $z['submitted_at'] );
                $b    = ! $ab && ! empty( $z['notified_at'] ) && $a;
                $c    = ! $ab && ! empty( $z['confirmed_at'] );
                ?>
                <tr>
                    <?php if ( $tick ) : ?>
                        <td style="text-align:center;"><input type="checkbox" data-tick value="<?php echo (int) $z['id']; ?>" data-kind="<?php echo $ab ? 'withdrawn' : 'entry'; ?>" data-person="<?php echo (int) $z['person_id']; ?>" data-first="<?php echo esc_attr( $z['first_name'] ); ?>" data-notified="<?php echo $b ? '1' : '0'; ?>" <?php checked( $a ); ?> <?php disabled( $b ); ?> aria-label="<?php echo esc_attr( $ab ? __( 'beim Veranstalter abgemeldet', 'vereins-plugin' ) : __( 'beim Veranstalter gemeldet', 'vereins-plugin' ) ); ?>"></td>
                    <?php endif; ?>
                    <td><strong class="<?php echo $ab ? 'vp-strike' : ''; ?>"><?php echo esc_html( $z['last_name'] ); ?>, <?php echo esc_html( $z['first_name'] ); ?></strong>
                        <?php if ( ! $ab ) : ?>
                            <br><button type="button" class="button-link" style="font-size:12px;" data-edit="<?php echo esc_attr( wp_json_encode( [
                                'entry' => (int) $z['id'], 'first_name' => $z['first_name'], 'last_name' => $z['last_name'], 'birth_year' => (int) $z['birth_year'],
                                'sex' => $z['sex'], 'class_key' => $z['class_key'], 'weight' => $z['weight'], 'note' => $z['note'],
                                'judopass' => isset( $z['judopass'] ) ? (int) $z['judopass'] : null, 'status' => PollModel::entry_status( $z ), 'reporter' => $z['reporter'],
                            ] ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'vereins-plugin' ); ?></button>
                        <?php endif; ?></td>
                    <td><?php echo (int) $z['birth_year']; ?></td>
                    <td><?php if ( ( $z['art'] ?? '' ) === 'changed' || ( ! $tick && $z['prev_weight'] !== null ) ) : ?>
                            <span class="vp-strike"><?php echo $gewicht( (string) $z['prev_weight'] ); // phpcs:ignore ?></span> → <strong><?php echo $gewicht( $z['weight'] ); // phpcs:ignore ?></strong>
                        <?php else : ?><?php echo $gewicht( $z['weight'] ); // phpcs:ignore ?><?php endif; ?></td>
                    <td><?php echo $z['is_self'] ? '<em>' . esc_html__( 'selbst', 'vereins-plugin' ) . '</em>' : esc_html( $z['reporter'] ); ?><?php if ( $z['contact'] !== '' ) : ?><br><span class="description"><?php echo esc_html( $z['contact'] ); ?></span><?php endif; ?></td>
                    <?php if ( $dokume ) : ?>
                        <td><?php echo ! isset( $z['judopass'] ) ? '–' : ( (int) $z['judopass'] ? esc_html__( 'ja', 'vereins-plugin' ) : '<strong style="color:#b32d2e;">' . esc_html__( 'nein', 'vereins-plugin' ) . '</strong>' ); ?></td>
                    <?php endif; ?>
                    <td><?php echo esc_html( $z['note'] ); ?></td>
                    <?php if ( $tick && ! empty( $z['art'] ) ) :
                        $schritte = $ab ? [ 'a' => $a, 'b' => false ] : [ 'a' => $a, 'b' => $b, 'c' => $c ];
                        $naechst  = array_search( false, $schritte, true );
                        ?>
                        <td><span class="vp-mini" title="<?php esc_attr_e( 'a erledigt · b informiert · c bestätigt', 'vereins-plugin' ); ?>">
                            <?php foreach ( $schritte as $nr => $ok ) : ?><span class="<?php echo $ok ? 'is-done' : ( $nr === $naechst ? 'is-next' : '' ); ?>"><?php echo esc_html( $nr ); ?></span><?php endforeach; ?>
                        </span></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function handle_csv(): void {
        $id = (int) ( $_GET['id'] ?? 0 );
        check_admin_referer( 'vp_poll_entries_csv_' . $id );
        $this->require_admin();
        $poll = $this->model->find( $id );
        if ( ! $poll || $poll['kind'] !== 'entry' ) {
            wp_die( esc_html__( 'Umfrage nicht gefunden.', 'vereins-plugin' ), 404 );
        }
        $name = sanitize_file_name( 'meldeliste-' . remove_accents( $poll['title'] ) . '.csv' );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        echo ( new EntryService( $this->model ) )->csv( $poll, ( (string) Settings::get( 'club_name', '' ) ?: get_bloginfo( 'name' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    // ------------------------------------------------------------------
    // Ideensammlung: Vorschläge
    // ------------------------------------------------------------------

    private function render_texts( array $poll ): void {
        $liste  = $this->service->text_results( $poll );
        $anzahl = $this->model->voter_count( (int) $poll['id'] );
        $geheim = ! empty( $poll['anonymous'] );
        $msg    = sanitize_key( $_GET['msg'] ?? '' );
        ?>
        <h1><?php echo esc_html( $poll['title'] ); ?></h1>
        <p><a href="<?php echo esc_url( $this->url() ); ?>">&larr; <?php esc_html_e( 'Zur Übersicht', 'vereins-plugin' ); ?></a></p>
        <?php if ( $msg === 'text_deleted' ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Vorschlag gelöscht.', 'vereins-plugin' ); ?></p></div>
        <?php endif; ?>
        <p>
            <?php esc_html_e( 'Ideensammlung', 'vereins-plugin' ); ?> ·
            <?php $this->status_badge( $poll ); ?> ·
            <?php echo esc_html( sprintf( _n( '%d Vorschlag', '%d Vorschläge', count( $liste ), 'vereins-plugin' ), count( $liste ) ) ); ?> ·
            <?php echo esc_html( sprintf( _n( '%d Person', '%d Personen', $anzahl, 'vereins-plugin' ), $anzahl ) ); ?>
            <?php if ( $geheim ) : ?> · <?php esc_html_e( 'geheim – ohne Namen', 'vereins-plugin' ); ?><?php endif; ?>
        </p>
        <?php if ( ! $liste ) : ?>
            <p><?php esc_html_e( 'Noch keine Vorschläge.', 'vereins-plugin' ); ?></p>
            <?php return; ?>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
            <input type="hidden" name="action" value="vp_poll_to_choice">
            <input type="hidden" name="id" value="<?php echo (int) $poll['id']; ?>">
            <?php wp_nonce_field( 'vp_poll_to_choice_' . (int) $poll['id'] ); ?>
            <button class="button button-primary"><?php esc_html_e( 'Als Abstimmung übernehmen', 'vereins-plugin' ); ?></button>
            <span class="description"><?php esc_html_e( 'Legt eine neue Abstimmung als Entwurf an – mit allen Vorschlägen als Antworten. Dort kannst du kürzen und zusammenfassen, bevor sie startet.', 'vereins-plugin' ); ?></span>
        </form>

        <table class="vp-table">
            <thead><tr>
                <th><?php esc_html_e( 'Vorschlag', 'vereins-plugin' ); ?></th>
                <?php if ( ! $geheim ) : ?><th><?php esc_html_e( 'Von', 'vereins-plugin' ); ?></th><?php endif; ?>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $liste as $t ) : ?>
                <tr>
                    <td><?php echo esc_html( $t['text'] ); ?></td>
                    <?php if ( ! $geheim ) : ?><td><?php echo esc_html( (string) $t['name'] ); ?></td><?php endif; ?>
                    <td style="width:1%;white-space:nowrap;">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Diesen Vorschlag löschen?', 'vereins-plugin' ) ); ?>');">
                            <input type="hidden" name="action" value="vp_delete_poll_text">
                            <input type="hidden" name="id" value="<?php echo (int) $poll['id']; ?>">
                            <input type="hidden" name="text_id" value="<?php echo (int) $t['id']; ?>">
                            <?php wp_nonce_field( 'vp_delete_poll_text_' . (int) $t['id'] ); ?>
                            <button class="button button-small"><?php esc_html_e( 'Löschen', 'vereins-plugin' ); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function handle_delete_text(): void {
        $text_id = (int) ( $_POST['text_id'] ?? 0 );
        $poll_id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_delete_poll_text_' . $text_id );
        $this->require_admin();
        $this->model->delete_text( $text_id );
        wp_safe_redirect( $this->url( [ 'ergebnis' => $poll_id, 'msg' => 'text_deleted' ] ) );
        exit;
    }

    /**
     * Aus einer Ideensammlung eine Abstimmung machen: neue Umfrage als
     * Entwurf, jeder (verschiedene) Vorschlag eine Antwort.
     */
    public static function choice_from_texts( PollModel $model, PollService $service, array $poll ): int {
        $antworten = [];
        foreach ( $service->text_results( $poll ) as $t ) {
            $antworten[ mb_strtolower( $t['text'] ) ] ??= [ 'label' => $t['text'] ];
        }
        $neu = $model->save( [
            'kind'           => 'choice',
            'title'          => sprintf( __( 'Abstimmung: %s', 'vereins-plugin' ), $poll['title'] ),
            'description'    => (string) $poll['description'],
            'status'         => 'draft',
            'results'        => 'after_close',
            'login_required' => ! empty( $poll['login_required'] ),
            'created_by'     => get_current_user_id(),
        ] );
        $model->save_options( $neu, array_values( $antworten ) );
        return $neu;
    }

    public function handle_to_choice(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_poll_to_choice_' . $id );
        $this->require_admin();
        $poll = $this->model->find( $id );
        if ( ! $poll || $poll['kind'] !== 'text' ) {
            wp_die( esc_html__( 'Umfrage nicht gefunden.', 'vereins-plugin' ), 404 );
        }
        $neu = self::choice_from_texts( $this->model, $this->service, $poll );
        wp_safe_redirect( $this->url( [ 'edit' => $neu, 'msg' => 'copied' ] ) );
        exit;
    }

    // ------------------------------------------------------------------
    // Wettkampfmeldung: Excel, Abhakliste, abgeschickt/bestätigt
    // ------------------------------------------------------------------

    private function entry_poll_or_die( int $id ): array {
        $this->require_admin();
        $poll = $this->model->find( $id );
        if ( ! $poll || $poll['kind'] !== 'entry' ) {
            wp_die( esc_html__( 'Umfrage nicht gefunden.', 'vereins-plugin' ), 404 );
        }
        return $poll;
    }

    public function handle_xlsx(): void {
        $id = (int) ( $_GET['id'] ?? 0 );
        check_admin_referer( 'vp_poll_entries_xlsx_' . $id );
        $poll = $this->entry_poll_or_die( $id );
        $ich  = wp_get_current_user();
        $mail = (string) $ich->user_email;
        $inhalt = BjvExport::build( [
            'name'           => $poll['title'],
            'date'           => $poll['event_date'] ? mysql2date( 'd.m.Y', $poll['event_date'] ) : '',
            'club'           => (string) Settings::get( 'club_name', '' ) ?: get_bloginfo( 'name' ),
            'contact_person' => (string) $ich->display_name,
            'contact_mail'   => MemberService::is_placeholder_email( $mail ) ? (string) get_option( 'admin_email' ) : $mail,
        ], ( new EntryService( $this->model ) )->list_for_orga( $poll ) );
        if ( $inhalt === null ) {
            wp_die( esc_html__( 'Excel-Dateien kann dieser Server nicht erzeugen (PHP-Erweiterung „zip“ fehlt). Bitte die CSV-Datei nehmen.', 'vereins-plugin' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'Meldeliste-' . remove_accents( $poll['title'] ) . '.xlsx' ) . '"' );
        header( 'Content-Length: ' . strlen( $inhalt ) );
        echo $inhalt; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /** Einzelne Haken sofort speichern (Abhakliste, per Script). */
    public function ajax_tick(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! check_ajax_referer( 'vp_poll_entries_mark_' . $id, '_wpnonce', false ) || ! Capabilities::is_admin_user() ) {
            wp_send_json_error( null, 403 );
        }
        $poll = $this->model->find( $id );
        if ( ! $poll || $poll['kind'] !== 'entry' ) {
            wp_send_json_error( null, 404 );
        }
        $ids = static fn( string $k ) => array_map( 'intval', (array) ( $_POST[ $k ] ?? [] ) );
        $this->model->mark_entries_submitted( $id, $ids( 'on' ), true );
        $this->model->mark_entries_submitted( $id, $ids( 'off' ), false );
        $this->model->mark_withdrawn_done( $id, $ids( 'won' ), true );
        $this->model->mark_withdrawn_done( $id, $ids( 'woff' ), false );
        wp_send_json_success();
    }

    /**
     * Abschließen: Hauptmeldung („Status ‚Gemeldet‘ setzen“) bzw. Änderungen
     * („Melder informieren“). Die Haken sind schon gespeichert (Ajax).
     */
    public function handle_mark(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_poll_entries_mark_' . $id );
        $poll  = $this->entry_poll_or_die( $id );
        $mail  = ( $_POST['inform'] ?? '' ) === 'mail';
        $haupt = ( $_POST['scope'] ?? '' ) === 'main';
        $ich   = (string) wp_get_current_user()->display_name;
        $args  = [ 'ergebnis' => $id, 'msg' => $haupt ? 'submitted' : 'changes' ];

        if ( $haupt && empty( $poll['submitted_at'] ) ) {
            $this->model->mark_poll( $id, 'submitted_at' );
        }
        $gesendet = 0;
        $ohne     = [];
        $melder   = $this->model->melder_to_notify( $id );
        if ( $mail && $melder ) {
            $r = EntryMail::send( $poll, 'sent', $melder, $ich );
            $gesendet += $r['sent'];
            $ohne      = array_merge( $ohne, $r['skipped'] );
        }
        $this->model->mark_notified( $id, $melder );
        if ( ! $haupt ) {
            $ab = $this->model->melder_withdrawn_done( $id );
            if ( $mail && $ab ) {
                $r = EntryMail::send( $poll, 'withdrawn', $ab, $ich );
                $gesendet += $r['sent'];
                $ohne      = array_merge( $ohne, $r['skipped'] );
            }
            $this->model->finish_withdrawn( $id, $ab );
        }
        if ( $mail ) {
            $args['vp_sent'] = $gesendet;
            $args['vp_skip'] = rawurlencode( implode( ', ', array_unique( $ohne ) ) );
        }
        wp_safe_redirect( $this->url( $args ) );
        exit;
    }

    /** „Meldung ist abgeschickt“ / „… bestätigt“ – mit Mail an die Melder. */
    /** Orgateam: Starter bearbeiten, Status korrigieren oder entfernen. */
    public function handle_entry_edit(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_poll_entry_edit_' . $id );
        $poll = $this->entry_poll_or_die( $id );
        $eid  = (int) ( $_POST['entry'] ?? 0 );
        $ziel = null;
        foreach ( $this->model->entries( $id ) as $e ) {
            if ( (int) $e['id'] === $eid ) {
                $ziel = $e;
            }
        }
        if ( ! $ziel ) {
            wp_die( esc_html__( 'Starter nicht gefunden.', 'vereins-plugin' ), 404 );
        }
        $pid    = (int) $ziel['person_id'];
        $zeilen = $this->model->entries( $id, $pid );
        $roh    = static fn( $e ) => array_intersect_key( $e, array_flip( [ 'first_name', 'last_name', 'birth_year', 'sex', 'class_key', 'weight', 'note', 'judopass' ] ) );

        // Entfernen: wie wenn der Melder ihn streicht – schon Gemeldetes wird Abmeldung.
        if ( ( $_POST['op'] ?? '' ) === 'remove' ) {
            $rest = array_values( array_map( $roh, array_filter( $zeilen, static fn( $e ) => (int) $e['id'] !== $eid ) ) );
            $this->model->replace_entries( $id, $pid, $rest );
            wp_safe_redirect( $this->url( [ 'ergebnis' => $id, 'msg' => 'removed' ] ) );
            exit;
        }

        $neu = [
            'first_name' => wp_unslash( (string) ( $_POST['first_name'] ?? '' ) ),
            'last_name'  => wp_unslash( (string) ( $_POST['last_name'] ?? '' ) ),
            'birth_year' => (int) ( $_POST['birth_year'] ?? 0 ),
            'sex'        => (string) ( $_POST['sex'] ?? '' ),
            'class_key'  => wp_unslash( (string) ( $_POST['class_key'] ?? '' ) ),
            'weight'     => (string) ( $_POST['weight'] ?? '' ),
            'note'       => wp_unslash( (string) ( $_POST['note'] ?? '' ) ),
            'judopass'   => isset( $_POST['judopass'] ) ? (string) $_POST['judopass'] : ( $ziel['judopass'] ?? null ),
        ];
        $alle = [];
        $pos  = 0;
        foreach ( array_values( $zeilen ) as $i => $e ) {
            $alle[] = (int) $e['id'] === $eid ? $neu : $roh( $e );
            if ( (int) $e['id'] === $eid ) {
                $pos = $i;
            }
        }
        $sauber = ( new EntryService( $this->model ) )->validate_rows( $poll, $pid, $alle );
        if ( is_wp_error( $sauber ) ) {
            wp_safe_redirect( $this->url( [ 'ergebnis' => $id, 'msg' => 'edit_error', 'vp_err' => rawurlencode( $sauber->get_error_message() ) ] ) );
            exit;
        }
        $neu = $sauber[ $pos ];

        if ( ! empty( $_POST['correct'] ) ) {
            $this->model->correct_entry( $eid, $neu );
            $neue_id = $eid;
        } else {
            $this->model->replace_entries( $id, $pid, $sauber );
            $neue_id = 0;
            foreach ( $this->model->entries( $id, $pid ) as $e ) {
                if ( PollModel::entry_key( $e ) === PollModel::entry_key( $neu ) ) {
                    $neue_id = (int) $e['id'];
                }
            }
        }
        $status = sanitize_key( $_POST['status'] ?? '' );
        if ( $neue_id && $status !== '' && $status !== sanitize_key( $_POST['old_status'] ?? '' ) ) {
            $this->model->set_entry_status( $id, $neue_id, $status );
        }
        wp_safe_redirect( $this->url( [ 'ergebnis' => $id, 'msg' => 'edited' ] ) );
        exit;
    }

    public function handle_reset(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_poll_entries_reset_' . $id );
        $this->entry_poll_or_die( $id );
        $this->model->reset_entry_status( $id );
        wp_safe_redirect( $this->url( [ 'ergebnis' => $id, 'msg' => 'reset' ] ) );
        exit;
    }

    public function handle_state(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_poll_entries_state_' . $id );
        $poll   = $this->entry_poll_or_die( $id );
        $ids    = array_map( 'intval', (array) ( $_POST['ids'] ?? [] ) );
        $melder = $this->model->confirm_entries( $id, $ids );
        if ( empty( $poll['confirmed_at'] ) ) {
            $this->model->mark_poll( $id, 'confirmed_at' );
        }
        $mail = ! empty( $_POST['mail'] ) && $melder ? EntryMail::send( $poll, 'confirmed', $melder, (string) wp_get_current_user()->display_name ) : null;
        $args = [ 'ergebnis' => $id, 'msg' => 'confirmed' ];
        if ( $mail ) {
            $args['vp_sent'] = (int) $mail['sent'];
            $args['vp_skip'] = rawurlencode( implode( ', ', $mail['skipped'] ) );
        }
        wp_safe_redirect( $this->url( $args ) );
        exit;
    }
}
