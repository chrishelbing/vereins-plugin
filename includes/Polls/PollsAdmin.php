<?php

namespace VereinsPlugin\Polls;

use VereinsPlugin\Admin\AdminStyles;
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
            <?php esc_html_e( 'Terminumfragen, Abstimmungen und Nominierungen. Teilnehmen kann jede Person auch ohne Anmeldung – Gäste nennen einmal ihren Namen. Auf der Website erscheinen die Umfragen über den Shortcode [vp_umfragen]. Mitglieder und Anmeldung teilt das Modul mit den Terminen (Verein → Mitglieder).', 'vereins-plugin' ); ?>
        </p>
        <?php $this->render_notices(); ?>
        <p><a class="button button-primary" href="<?php echo esc_url( $this->url( [ 'neu' => 1 ] ) ); ?>"><?php esc_html_e( 'Neue Umfrage', 'vereins-plugin' ); ?></a></p>

        <table class="vp-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Titel', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Art', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Teilnahmen', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Ende', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Aktionen', 'vereins-plugin' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( ! $umfragen ) : ?>
                <tr><td colspan="6"><?php esc_html_e( 'Noch keine Umfrage angelegt.', 'vereins-plugin' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $umfragen as $p ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $p['title'] ); ?></strong></td>
                    <td><?php echo esc_html( self::kind_label( $p['kind'] ) ); ?></td>
                    <td><?php $this->status_badge( $p ); ?></td>
                    <td><?php echo (int) $this->model->voter_count( (int) $p['id'] ); ?></td>
                    <td><?php echo $p['closes_at'] ? esc_html( mysql2date( 'd.m.Y H:i', $p['closes_at'] ) ) : '<span class="description">—</span>'; ?></td>
                    <td class="vp-actions">
                        <a class="button button-small" href="<?php echo esc_url( $this->url( [ 'ergebnis' => $p['id'] ] ) ); ?>"><?php esc_html_e( 'Ergebnis', 'vereins-plugin' ); ?></a>
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
        return $kind === 'date' ? __( 'Terminumfrage', 'vereins-plugin' ) : __( 'Abstimmung', 'vereins-plugin' );
    }

    // ------------------------------------------------------------------
    // Formular
    // ------------------------------------------------------------------

    private function render_form( ?array $poll ): void {
        $p = $poll ?? [
            'id' => 0, 'kind' => 'choice', 'title' => '', 'description' => '', 'status' => 'draft',
            'closes_at' => '', 'multiple' => 0, 'allow_maybe' => 1, 'anonymous' => 0,
            'results' => 'after_vote', 'login_required' => 0,
        ];
        $optionen = $poll ? $this->model->options( (int) $poll['id'] ) : [];
        $hat_stimmen = $poll && $this->model->voter_count( (int) $poll['id'] ) > 0;
        $dt = static fn( $v ) => $v ? str_replace( ' ', 'T', substr( (string) $v, 0, 16 ) ) : '';
        ?>
        <h1><?php echo $poll ? esc_html__( 'Umfrage bearbeiten', 'vereins-plugin' ) : esc_html__( 'Neue Umfrage', 'vereins-plugin' ); ?></h1>
        <p><a href="<?php echo esc_url( $this->url() ); ?>">&larr; <?php esc_html_e( 'Zur Übersicht', 'vereins-plugin' ); ?></a></p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vp-form-card vp-form-card--wide" id="vp-poll-form">
            <input type="hidden" name="action" value="vp_save_poll">
            <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
            <?php wp_nonce_field( 'vp_save_poll' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="vp-poll-title"><?php esc_html_e( 'Titel', 'vereins-plugin' ); ?></label></th>
                    <td><input type="text" class="regular-text" id="vp-poll-title" name="title" required value="<?php echo esc_attr( $p['title'] ); ?>" placeholder="<?php esc_attr_e( 'z. B. Wann machen wir die Weihnachtsfeier?', 'vereins-plugin' ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="vp-poll-desc"><?php esc_html_e( 'Beschreibung', 'vereins-plugin' ); ?></label></th>
                    <td><textarea id="vp-poll-desc" name="description" rows="3" class="large-text"><?php echo esc_textarea( $p['description'] ); ?></textarea></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Art', 'vereins-plugin' ); ?></th>
                    <td>
                        <label><input type="radio" name="kind" value="date" <?php checked( $p['kind'], 'date' ); ?> <?php disabled( $hat_stimmen ); ?>> <?php esc_html_e( 'Terminumfrage – passt / vielleicht / passt nicht je Vorschlag', 'vereins-plugin' ); ?></label><br>
                        <label><input type="radio" name="kind" value="choice" <?php checked( $p['kind'], 'choice' ); ?> <?php disabled( $hat_stimmen ); ?>> <?php esc_html_e( 'Abstimmung oder Nominierung – Auswahl aus Antworten', 'vereins-plugin' ); ?></label>
                        <?php if ( $hat_stimmen ) : ?>
                            <input type="hidden" name="kind" value="<?php echo esc_attr( $p['kind'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Es gibt schon Antworten – die Art lässt sich nicht mehr ändern.', 'vereins-plugin' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Antwortmöglichkeiten', 'vereins-plugin' ); ?></th>
                    <td>
                        <p class="description vp-only-date"><?php esc_html_e( 'Je Zeile ein Terminvorschlag: Beginn, optional Ende und ein Zusatz.', 'vereins-plugin' ); ?></p>
                        <p class="description vp-only-choice"><?php esc_html_e( 'Je Zeile eine Antwort – bei einer Nominierung je Zeile eine Person.', 'vereins-plugin' ); ?></p>
                        <div id="vp-poll-options">
                            <?php
                            $zeilen = $optionen ?: [];
                            $leer   = max( 3, 6 - count( $zeilen ) );
                            for ( $i = 0; $i < $leer; $i++ ) {
                                $zeilen[] = [ 'id' => 0, 'label' => '', 'starts_at' => '', 'ends_at' => '' ];
                            }
                            foreach ( $zeilen as $i => $o ) :
                                ?>
                                <div class="vp-poll-option" style="display:flex;gap:6px;margin-bottom:6px;align-items:center;flex-wrap:wrap;">
                                    <input type="hidden" name="options[<?php echo $i; ?>][id]" value="<?php echo (int) $o['id']; ?>">
                                    <input type="datetime-local" class="vp-only-date" name="options[<?php echo $i; ?>][starts_at]" value="<?php echo esc_attr( $dt( $o['starts_at'] ) ); ?>" aria-label="<?php esc_attr_e( 'Beginn', 'vereins-plugin' ); ?>">
                                    <input type="time" class="vp-only-date" name="options[<?php echo $i; ?>][ends_at]" value="<?php echo esc_attr( $o['ends_at'] ? substr( (string) $o['ends_at'], 11, 5 ) : '' ); ?>" aria-label="<?php esc_attr_e( 'Ende (Uhrzeit, optional)', 'vereins-plugin' ); ?>">
                                    <input type="text" class="regular-text" name="options[<?php echo $i; ?>][label]" value="<?php echo esc_attr( $o['label'] ); ?>" placeholder="<?php esc_attr_e( 'Antwort bzw. Zusatz', 'vereins-plugin' ); ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button" id="vp-poll-add"><?php esc_html_e( 'Weitere Zeile', 'vereins-plugin' ); ?></button>
                        <?php if ( $hat_stimmen ) : ?>
                            <p class="description"><?php esc_html_e( 'Wird eine Zeile geleert, entfallen auch die Antworten darauf.', 'vereins-plugin' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Einstellungen', 'vereins-plugin' ); ?></th>
                    <td>
                        <label class="vp-only-choice"><input type="checkbox" name="multiple" value="1" <?php checked( ! empty( $p['multiple'] ) ); ?>> <?php esc_html_e( 'Mehrere Antworten erlaubt', 'vereins-plugin' ); ?><br></label>
                        <label class="vp-only-date"><input type="checkbox" name="allow_maybe" value="1" <?php checked( ! empty( $p['allow_maybe'] ) ); ?>> <?php esc_html_e( '„Vielleicht“ erlaubt', 'vereins-plugin' ); ?><br></label>
                        <label><input type="checkbox" name="anonymous" value="1" <?php checked( ! empty( $p['anonymous'] ) ); ?>> <?php esc_html_e( 'Geheim – Namen werden niemandem angezeigt, auch nicht dem Orgateam', 'vereins-plugin' ); ?></label><br>
                        <label><input type="checkbox" name="login_required" value="1" <?php checked( ! empty( $p['login_required'] ) ); ?>> <?php esc_html_e( 'Nur angemeldete Mitglieder (empfohlen für Nominierungen – sonst kann jemand unter mehreren Namen abstimmen)', 'vereins-plugin' ); ?></label>
                    </td>
                </tr>
                <tr>
                    <th><label for="vp-poll-results"><?php esc_html_e( 'Ergebnis sichtbar', 'vereins-plugin' ); ?></label></th>
                    <td>
                        <select id="vp-poll-results" name="results">
                            <?php foreach ( [
                                'always'      => __( 'sofort für alle', 'vereins-plugin' ),
                                'after_vote'  => __( 'nach der eigenen Abstimmung', 'vereins-plugin' ),
                                'after_close' => __( 'erst nach dem Ende', 'vereins-plugin' ),
                                'orga'        => __( 'nur für das Orgateam', 'vereins-plugin' ),
                            ] as $wert => $text ) : ?>
                                <option value="<?php echo esc_attr( $wert ); ?>" <?php selected( $p['results'], $wert ); ?>><?php echo esc_html( $text ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="vp-poll-close"><?php esc_html_e( 'Ende', 'vereins-plugin' ); ?></label></th>
                    <td><input type="datetime-local" id="vp-poll-close" name="closes_at" value="<?php echo esc_attr( $dt( $p['closes_at'] ) ); ?>"> <span class="description"><?php esc_html_e( 'optional – danach ist keine Abstimmung mehr möglich', 'vereins-plugin' ); ?></span></td>
                </tr>
                <tr>
                    <th><label for="vp-poll-status"><?php esc_html_e( 'Status', 'vereins-plugin' ); ?></label></th>
                    <td>
                        <select id="vp-poll-status" name="status">
                            <option value="draft" <?php selected( $p['status'], 'draft' ); ?>><?php esc_html_e( 'Entwurf – noch nicht sichtbar', 'vereins-plugin' ); ?></option>
                            <option value="open" <?php selected( $p['status'], 'open' ); ?>><?php esc_html_e( 'Offen', 'vereins-plugin' ); ?></option>
                            <option value="closed" <?php selected( $p['status'], 'closed' ); ?>><?php esc_html_e( 'Beendet', 'vereins-plugin' ); ?></option>
                        </select>
                    </td>
                </tr>
            </table>
            <p><button class="button button-primary"><?php esc_html_e( 'Speichern', 'vereins-plugin' ); ?></button></p>
        </form>
        <script>
        (function(){
            var form = document.getElementById('vp-poll-form');
            function art(){ var r = form.querySelector('input[name="kind"]:checked'); return r ? r.value : 'choice'; }
            function zeigen(){
                var d = art() === 'date';
                form.querySelectorAll('.vp-only-date').forEach(function(e){ e.style.display = d ? '' : 'none'; });
                form.querySelectorAll('.vp-only-choice').forEach(function(e){ e.style.display = d ? 'none' : ''; });
            }
            form.querySelectorAll('input[name="kind"]').forEach(function(r){ r.addEventListener('change', zeigen); });
            document.getElementById('vp-poll-add').addEventListener('click', function(){
                var box = document.getElementById('vp-poll-options');
                var zeilen = box.querySelectorAll('.vp-poll-option');
                var neu = zeilen[zeilen.length - 1].cloneNode(true);
                var i = zeilen.length;
                neu.querySelectorAll('input').forEach(function(e){
                    e.name = e.name.replace(/options\[\d+\]/, 'options[' + i + ']');
                    e.value = e.type === 'hidden' ? '0' : '';
                });
                box.appendChild(neu);
                zeigen();
            });
            zeigen();
        })();
        </script>
        <?php
    }

    public function handle_save(): void {
        check_admin_referer( 'vp_save_poll' );
        $this->require_admin();

        $id   = (int) ( $_POST['id'] ?? 0 );
        $kind = ( $_POST['kind'] ?? '' ) === 'date' ? 'date' : 'choice';
        $dt   = static function ( $v ): ?string {
            $v = sanitize_text_field( wp_unslash( (string) $v ) );
            return $v !== '' ? str_replace( 'T', ' ', substr( $v, 0, 16 ) ) . ':00' : null;
        };

        $poll_id = $this->model->save( [
            'id'             => $id,
            'kind'           => $kind,
            'title'          => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
            'description'    => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'status'         => sanitize_key( $_POST['status'] ?? 'draft' ),
            'closes_at'      => $dt( $_POST['closes_at'] ?? '' ),
            'multiple'       => ! empty( $_POST['multiple'] ),
            'allow_maybe'    => ! empty( $_POST['allow_maybe'] ),
            'anonymous'      => ! empty( $_POST['anonymous'] ),
            'results'        => sanitize_key( $_POST['results'] ?? 'after_vote' ),
            'login_required' => ! empty( $_POST['login_required'] ),
            'created_by'     => get_current_user_id(),
        ] );

        $this->model->save_options( $poll_id, self::parse_options( (array) ( $_POST['options'] ?? [] ), $kind ) );

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
        ];
        $msg = sanitize_key( $_GET['msg'] ?? '' );
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
}
