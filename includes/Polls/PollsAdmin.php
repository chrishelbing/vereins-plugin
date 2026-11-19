<?php

namespace VereinsPlugin\Polls;

use VereinsPlugin\Admin\AdminStyles;
use VereinsPlugin\Admin\Settings;
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
        $umfragen = $this->model->all();
        ?>
        <h1><?php esc_html_e( 'Umfragen', 'vereins-plugin' ); ?></h1>
        <p class="description" style="max-width:70em;">
            <?php esc_html_e( 'Terminumfragen, Abstimmungen und Nominierungen, Ideensammlungen und Wettkampfmeldungen. Teilnehmen kann jede Person auch ohne Anmeldung – Gäste nennen einmal ihren Namen. Auf der Website erscheinen die Umfragen über den Shortcode [vp_umfragen]. Mitglieder und Anmeldung teilt das Modul mit den Terminen (Verein → Mitglieder).', 'vereins-plugin' ); ?>
        </p>
        <?php $this->render_notices(); ?>
        <?php $this->render_new_buttons(); ?>

        <table class="vp-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Titel', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Art', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Teilnahmen', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Ende', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Shortcode', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Aktionen', 'vereins-plugin' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( ! $umfragen ) : ?>
                <tr><td colspan="7"><?php esc_html_e( 'Noch keine Umfrage angelegt.', 'vereins-plugin' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $umfragen as $p ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $p['title'] ); ?></strong></td>
                    <td><?php echo esc_html( self::kind_label( $p['kind'] ) ); ?></td>
                    <td><?php $this->status_badge( $p ); ?></td>
                    <td><?php echo (int) $this->model->voter_count( (int) $p['id'] ); ?></td>
                    <td><?php echo $p['closes_at'] ? esc_html( mysql2date( 'd.m.Y H:i', $p['closes_at'] ) ) : '<span class="description">—</span>'; ?></td>
                    <td><code style="user-select:all;">[vp_umfragen id="<?php echo (int) $p['id']; ?>"]</code></td>
                    <td class="vp-actions">
                        <a class="button button-small" href="<?php echo esc_url( $this->url( [ 'ergebnis' => $p['id'] ] ) ); ?>"><?php echo $p['kind'] === 'entry' ? esc_html__( 'Meldeliste', 'vereins-plugin' ) : ( $p['kind'] === 'text' ? esc_html__( 'Vorschläge', 'vereins-plugin' ) : esc_html__( 'Ergebnis', 'vereins-plugin' ) ); ?></a>
                        <a class="button button-small" href="<?php echo esc_url( $this->url( [ 'edit' => $p['id'] ] ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'vereins-plugin' ); ?></a>
                        <?php
                        if ( $p['status'] !== 'open' ) {
                            $this->status_button( (int) $p['id'], 'open', __( 'Öffnen', 'vereins-plugin' ) );
                        }
                        if ( $p['status'] === 'open' ) {
                            $this->status_button( (int) $p['id'], 'closed', __( 'Beenden', 'vereins-plugin' ) );
                        }
                        ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Umfrage samt allen Antworten löschen?', 'vereins-plugin' ) ); ?>');">
                            <input type="hidden" name="action" value="vp_delete_poll">
                            <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                            <?php wp_nonce_field( 'vp_delete_poll_' . (int) $p['id'] ); ?>
                            <button class="button button-small button-link-delete"><?php esc_html_e( 'Löschen', 'vereins-plugin' ); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
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

    private function status_button( int $id, string $status, string $label ): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
            <input type="hidden" name="action" value="vp_poll_status">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
            <?php wp_nonce_field( 'vp_poll_status_' . $id ); ?>
            <button class="button button-small"><?php echo esc_html( $label ); ?></button>
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
            'event_date' => '', 'classes' => null, 'purge_days' => 30, 'max_texts' => 3,
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

    /** „Neue Umfrage“ ohne Art: erst die Art wählen. */
    private function render_kind_picker(): void {
        ?>
        <h1><?php esc_html_e( 'Neue Umfrage', 'vereins-plugin' ); ?></h1>
        <p><a href="<?php echo esc_url( $this->url() ); ?>">&larr; <?php esc_html_e( 'Zur Übersicht', 'vereins-plugin' ); ?></a></p>
        <p><?php esc_html_e( 'Welche Art? Die Art lässt sich später nicht mehr ändern.', 'vereins-plugin' ); ?></p>
        <?php $this->render_new_buttons(); ?>
        <?php
    }

    /** Je Art ein Knopf „Neu“ mit kurzer Erklärung. */
    private function render_new_buttons(): void {
        echo '<div style="display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 20px;">';
        foreach ( self::NEW_KINDS as $art => $kind ) {
            printf(
                '<a class="button" style="height:auto;padding:8px 14px;line-height:1.4;max-width:240px;white-space:normal;text-align:left;" href="%s"><strong>%s</strong><br><span class="description">%s</span></a>',
                esc_url( $this->url( [ 'neu' => 1, 'art' => $art ] ) ),
                esc_html( '+ ' . self::kind_label( $kind ) ),
                esc_html( self::kind_hint( $kind ) )
            );
        }
        echo '</div>';
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

    private function render_entries( array $poll ): void {
        $service = new EntryService( $this->model );
        $liste   = $service->list_for_orga( $poll );
        $melder  = $this->model->voter_count( (int) $poll['id'] );
        $jahr    = EntryService::year( $poll );
        $klassen = array_column( PollModel::classes( $poll ), null, 'key' );
        $faellig = EntryCleanup::due_date( $poll );
        $sicht   = sanitize_key( $_GET['sicht'] ?? '' ) === 'melder' ? 'melder' : 'klasse';
        $csv     = wp_nonce_url( admin_url( 'admin-post.php?action=vp_poll_entries_csv&id=' . (int) $poll['id'] ), 'vp_poll_entries_csv_' . (int) $poll['id'] );
        ?>
        <h1><?php echo esc_html( $poll['title'] ); ?></h1>
        <p><a href="<?php echo esc_url( $this->url() ); ?>">&larr; <?php esc_html_e( 'Zur Übersicht', 'vereins-plugin' ); ?></a></p>
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

        <?php if ( $liste ) : ?>
            <p>
                <a class="button button-primary" href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'Meldeliste herunterladen (CSV)', 'vereins-plugin' ); ?></a>
                <?php if ( $sicht === 'klasse' ) : ?>
                    <a class="button" href="<?php echo esc_url( $this->url( [ 'ergebnis' => $poll['id'], 'sicht' => 'melder' ] ) ); ?>"><?php esc_html_e( 'Nach Melder ordnen', 'vereins-plugin' ); ?></a>
                <?php else : ?>
                    <a class="button" href="<?php echo esc_url( $this->url( [ 'ergebnis' => $poll['id'] ] ) ); ?>"><?php esc_html_e( 'Nach Klassen ordnen', 'vereins-plugin' ); ?></a>
                <?php endif; ?>
            </p>
        <?php else : ?>
            <p><?php esc_html_e( 'Noch keine Meldungen.', 'vereins-plugin' ); ?></p>
            <?php return; ?>
        <?php endif; ?>

        <?php
        if ( $sicht === 'melder' ) {
            $gruppen = [];
            foreach ( $liste as $z ) {
                $gruppen[ $z['person_id'] ][] = $z;
            }
            foreach ( $gruppen as $zeilen ) {
                $erster = $zeilen[0];
                echo '<h2 style="margin-bottom:4px;">' . esc_html( $erster['reporter'] )
                    . ( $erster['reporter_guest'] ? ' <span class="description">(' . esc_html__( 'Gast', 'vereins-plugin' ) . ')</span>' : '' ) . '</h2>';
                echo '<p style="margin-top:0;">' . ( $erster['contact'] !== '' ? esc_html( $erster['contact'] ) : '<span class="description">' . esc_html__( 'kein Kontakt hinterlegt', 'vereins-plugin' ) . '</span>' ) . '</p>';
                $this->entry_table( $zeilen, false );
            }
            return;
        }

        $gruppen = [];
        foreach ( $liste as $z ) {
            $gruppen[ $z['class_key'] . ' ' . $z['sex'] ][] = $z;
        }
        foreach ( $gruppen as $zeilen ) {
            $k = $klassen[ $zeilen[0]['class_key'] ] ?? null;
            $titel = $zeilen[0]['class_key'] . ' ' . ( $zeilen[0]['sex'] === 'w' ? __( 'weiblich', 'vereins-plugin' ) : __( 'männlich', 'vereins-plugin' ) );
            echo '<h2 style="margin-bottom:6px;">' . esc_html( $titel )
                . ( $k ? ' <span class="description" style="font-weight:normal;">' . esc_html( sprintf( __( 'Jahrgänge %s', 'vereins-plugin' ), ClassTemplates::years_label( $k, $jahr ) ) ) . '</span>' : '' )
                . ' <span class="description" style="font-weight:normal;">· ' . esc_html( sprintf( _n( '%d Starter', '%d Starter', count( $zeilen ), 'vereins-plugin' ), count( $zeilen ) ) ) . '</span></h2>';
            $this->entry_table( $zeilen, true );
        }
    }

    private function entry_table( array $zeilen, bool $mit_melder ): void {
        ?>
        <table class="vp-table">
            <thead><tr>
                <th><?php esc_html_e( 'Name', 'vereins-plugin' ); ?></th>
                <th><?php esc_html_e( 'Jahrgang', 'vereins-plugin' ); ?></th>
                <?php if ( ! $mit_melder ) : ?><th><?php esc_html_e( 'Klasse', 'vereins-plugin' ); ?></th><?php endif; ?>
                <th><?php esc_html_e( 'Gewicht', 'vereins-plugin' ); ?></th>
                <?php if ( $mit_melder ) : ?><th><?php esc_html_e( 'Gemeldet von', 'vereins-plugin' ); ?></th><?php endif; ?>
                <th><?php esc_html_e( 'Hinweis', 'vereins-plugin' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $zeilen as $z ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $z['last_name'] ); ?></strong>, <?php echo esc_html( $z['first_name'] ); ?></td>
                    <td><?php echo (int) $z['birth_year']; ?></td>
                    <?php if ( ! $mit_melder ) : ?><td><?php echo esc_html( $z['class_key'] . ' ' . $z['sex'] ); ?></td><?php endif; ?>
                    <td><?php echo $z['weight'] !== '' ? esc_html( ClassTemplates::weight_label( $z['weight'] ) ) : '<span class="description">' . esc_html__( 'gewichtsnah', 'vereins-plugin' ) . '</span>'; ?></td>
                    <?php if ( $mit_melder ) : ?>
                        <td><?php echo esc_html( $z['reporter'] ); ?><?php if ( $z['contact'] !== '' ) : ?><br><span class="description"><?php echo esc_html( $z['contact'] ); ?></span><?php endif; ?></td>
                    <?php endif; ?>
                    <td><?php echo esc_html( $z['note'] ); ?></td>
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
}
