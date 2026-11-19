<?php

namespace VereinsPlugin\Polls;

use VereinsPlugin\Admin\AdminStyles;
use VereinsPlugin\Support\Capabilities;

/**
 * Backend: „Umfragen → Vorlagen“ (Abschnitt Wettkämpfe) und der Klassen-Editor, den auch
 * das Formular einer Wettkampfmeldung benutzt.
 *
 * Der Editor wird im Browser aus einer Klassenliste aufgebaut
 * (assets/js/poll-classes.js). In einer Vorlage stehen Altersangaben – sie
 * gelten jedes Jahr. An einer Meldung stehen Jahrgänge, so wie sie in der
 * Ausschreibung stehen; umgerechnet wird mit dem Jahr des Wettkampftags.
 */
class ClassesAdmin {

    public const SLUG = 'vp-poll-classes';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 20 );
        add_action( 'admin_post_vp_save_class_template',   [ $this, 'handle_save' ] );
        add_action( 'admin_post_vp_reset_class_template',  [ $this, 'handle_reset' ] );
        add_action( 'admin_post_vp_remove_class_template', [ $this, 'handle_remove' ] );
    }

    public function add_menu(): void {
        add_submenu_page( 'vp-polls', __( 'Umfragen', 'vereins-plugin' ), __( 'Alle Umfragen', 'vereins-plugin' ), 'manage_options', 'vp-polls' );
        add_submenu_page( 'vp-polls', __( 'Vorlagen', 'vereins-plugin' ), __( 'Vorlagen', 'vereins-plugin' ), 'manage_options', self::SLUG, [ $this, 'render' ] );
    }

    private function url( array $args = [] ): string {
        return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
    }

    // ------------------------------------------------------------------
    // Editor (auch für das Umfrage-Formular)
    // ------------------------------------------------------------------

    /**
     * Klassen-Editor ausgeben.
     *
     * @param string $field   Name der Formularfelder, z. B. 'classes'
     * @param array  $classes Klassen (mit Altersangaben)
     * @param string $mode    'age' (Vorlage) oder 'year' (Meldung)
     */
    public static function editor( string $field, array $classes, string $mode ): void {
        $base = plugin_dir_url( VP_PLUGIN_FILE );
        wp_enqueue_script( 'vp-poll-classes', $base . 'assets/js/poll-classes.js', [], VP_VERSION, true );
        wp_localize_script( 'vp-poll-classes', 'vpPollClasses', [ 't' => self::texts() ] );
        ?>
        <div class="vp-class-editor" data-field="<?php echo esc_attr( $field ); ?>" data-mode="<?php echo esc_attr( $mode ); ?>"
             data-classes="<?php echo esc_attr( wp_json_encode( array_values( $classes ) ) ); ?>"></div>
        <p class="description" style="max-width:60em;">
            <?php esc_html_e( 'Gewichtsklassen mit Leerzeichen oder Komma getrennt: „28 31 34 +34“. Eine Zahl ohne Zeichen gilt als „bis“ (-28 kg), „+“ oder „Ü“ als „über“. Bei „gewichtsnah“ gibt es keine Auswahl – eingeteilt wird beim Wiegen.', 'vereins-plugin' ); ?>
        </p>
        <?php
    }

    /** Texte des Editors – das Skript enthält keine eigenen. */
    public static function texts(): array {
        return [
            'on'      => __( 'startet', 'vereins-plugin' ),
            'key'     => __( 'Klasse', 'vereins-plugin' ),
            'age'     => __( 'Alter von – bis', 'vereins-plugin' ),
            'years'   => __( 'Jahrgänge (ältester – jüngster)', 'vereins-plugin' ),
            'older'   => __( 'leer = und älter', 'vereins-plugin' ),
            'sex'     => __( 'Geschlecht', 'vereins-plugin' ),
            'mw'      => __( 'm + w', 'vereins-plugin' ),
            'm'       => __( 'nur m', 'vereins-plugin' ),
            'w'       => __( 'nur w', 'vereins-plugin' ),
            'near'    => __( 'gewichtsnah', 'vereins-plugin' ),
            'wm'      => __( 'Gewichtsklassen männlich', 'vereins-plugin' ),
            'ww'      => __( 'Gewichtsklassen weiblich', 'vereins-plugin' ),
            'remove'  => __( 'Entfernen', 'vereins-plugin' ),
            'add'     => __( 'Weitere Klasse', 'vereins-plugin' ),
            'noYear'  => __( 'Bitte zuerst den Wettkampftag eintragen – sein Jahr bestimmt die Jahrgänge.', 'vereins-plugin' ),
            'replace' => __( 'Die Klassen dieser Meldung durch die Vorlage ersetzen?', 'vereins-plugin' ),
        ];
    }

    /**
     * Formularzeilen in Klassen übersetzen.
     *
     * @param string $mode 'age' oder 'year' (dann mit $year umrechnen)
     */
    public static function parse_rows( array $rows, string $mode, int $year = 0 ): array {
        $out = [];
        foreach ( $rows as $r ) {
            if ( ! is_array( $r ) ) {
                continue;
            }
            if ( $mode === 'year' && empty( $r['on'] ) ) {
                continue;
            }
            $a = trim( (string) ( $r['from'] ?? '' ) );
            $b = trim( (string) ( $r['to'] ?? '' ) );
            if ( $mode === 'year' ) {
                // from = ältester Jahrgang (leer: „und älter“), to = jüngster
                $jung = (int) $b;
                $alt  = $a === '' ? 0 : (int) $a;
                if ( $alt && $jung && $alt > $jung ) {
                    [ $alt, $jung ] = [ $jung, $alt ];
                }
                if ( ! $jung ) {
                    continue;
                }
                $von = $year - $jung;
                $bis = $alt ? $year - $alt : 99;
            } else {
                $von = (int) $a;
                $bis = $b === '' ? 99 : (int) $b;
            }
            $out[] = [
                'key'  => wp_unslash( (string) ( $r['key'] ?? '' ) ),
                'from' => $von,
                'to'   => $bis,
                'sex'  => (string) ( $r['sex'] ?? 'mw' ),
                'near' => ! empty( $r['near'] ),
                'm'    => wp_unslash( (string) ( $r['m'] ?? '' ) ),
                'w'    => wp_unslash( (string) ( $r['w'] ?? '' ) ),
            ];
        }
        return ClassTemplates::clean( $out );
    }

    // ------------------------------------------------------------------
    // Seite
    // ------------------------------------------------------------------

    public function render(): void {
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }
        AdminStyles::print_inline();
        echo '<div class="wrap vp-admin-wrap">';

        $edit = sanitize_key( $_GET['edit'] ?? '' );
        $neu  = isset( $_GET['neu'] ) ? sanitize_key( $_GET['neu'] ) : null;

        if ( $edit !== '' && ( $t = ClassTemplates::find( $edit ) ) ) {
            $this->render_form( $edit, $t );
        } elseif ( $neu !== null ) {
            $vorlage = ClassTemplates::find( $neu );
            $this->render_form( '', [
                'name'    => $vorlage ? sprintf( __( '%s (Kopie)', 'vereins-plugin' ), $vorlage['name'] ) : '',
                'note'    => '',
                'classes' => $vorlage['classes'] ?? [],
            ] );
        } else {
            $this->render_list();
        }
        echo '</div>';
    }

    private function render_list(): void {
        $msg = sanitize_key( $_GET['msg'] ?? '' );
        ?>
        <h1><?php esc_html_e( 'Vorlagen', 'vereins-plugin' ); ?></h1>
        <h2 style="margin-bottom:4px;"><?php esc_html_e( 'Wettkämpfe: Alters- und Gewichtsklassen', 'vereins-plugin' ); ?></h2>
        <p class="description" style="max-width:70em;">
            <?php esc_html_e( 'Alters- und Gewichtsklassen für Wettkampfmeldungen. Beim Anlegen einer Meldung wird eine Vorlage kopiert und kann für das Turnier angepasst werden – Änderungen hier wirken nur auf neue Meldungen. Maßgeblich ist der Jahrgang: Alter = Wettkampfjahr minus Geburtsjahr.', 'vereins-plugin' ); ?>
        </p>
        <?php if ( $msg ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Gespeichert.', 'vereins-plugin' ); ?></p></div>
        <?php endif; ?>
        <table class="vp-table">
            <thead><tr>
                <th><?php esc_html_e( 'Vorlage', 'vereins-plugin' ); ?></th>
                <th><?php esc_html_e( 'Klassen', 'vereins-plugin' ); ?></th>
                <th><?php esc_html_e( 'Aktionen', 'vereins-plugin' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( ClassTemplates::all() as $slug => $t ) : ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $t['name'] ); ?></strong>
                        <?php if ( ClassTemplates::is_changed( $slug ) ) : ?> <span class="vp-badge vp-badge--pending"><?php esc_html_e( 'angepasst', 'vereins-plugin' ); ?></span><?php endif; ?>
                        <?php if ( $t['note'] ) : ?><br><span class="description"><?php echo esc_html( $t['note'] ); ?></span><?php endif; ?>
                    </td>
                    <td><?php echo esc_html( implode( ', ', array_map( static fn( $c ) => $c['key'] . ( ! empty( $c['near'] ) ? ' (' . __( 'gewichtsnah', 'vereins-plugin' ) . ')' : '' ), $t['classes'] ) ) ); ?></td>
                    <td class="vp-actions">
                        <a class="button button-small" href="<?php echo esc_url( $this->url( [ 'edit' => $slug ] ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'vereins-plugin' ); ?></a>
                        <a class="button button-small" href="<?php echo esc_url( $this->url( [ 'neu' => $slug ] ) ); ?>"><?php esc_html_e( 'Kopieren', 'vereins-plugin' ); ?></a>
                        <?php if ( ClassTemplates::is_changed( $slug ) ) : ?>
                            <?php $this->post_button( 'vp_reset_class_template', $slug, __( 'Auslieferung wiederherstellen', 'vereins-plugin' ) ); ?>
                        <?php endif; ?>
                        <?php $this->post_button( 'vp_remove_class_template', $slug, __( 'Entfernen', 'vereins-plugin' ), __( 'Diese Vorlage entfernen? Bestehende Meldungen bleiben unverändert.', 'vereins-plugin' ) ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><a class="button button-primary" href="<?php echo esc_url( $this->url( [ 'neu' => '' ] ) ); ?>"><?php esc_html_e( 'Neue Vorlage', 'vereins-plugin' ); ?></a></p>
        <?php foreach ( ClassTemplates::removed() as $slug ) : ?>
            <p><?php $this->post_button( 'vp_reset_class_template', $slug, sprintf( __( 'Ausgelieferte Vorlage „%s“ zurückholen', 'vereins-plugin' ), ClassTemplates::defaults()[ $slug ]['name'] ) ); ?></p>
        <?php endforeach;
    }

    private function post_button( string $action, string $slug, string $label, string $confirm = '' ): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"<?php echo $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : ''; ?>>
            <input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
            <input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>">
            <?php wp_nonce_field( $action . '_' . $slug ); ?>
            <button class="button button-small"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    private function render_form( string $slug, array $t ): void {
        ?>
        <h1><?php echo $slug ? esc_html__( 'Wettkampf-Vorlage bearbeiten', 'vereins-plugin' ) : esc_html__( 'Neue Wettkampf-Vorlage', 'vereins-plugin' ); ?></h1>
        <p><a href="<?php echo esc_url( $this->url() ); ?>">&larr; <?php esc_html_e( 'Zur Übersicht', 'vereins-plugin' ); ?></a></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vp-form-card" style="max-width:none;">
            <input type="hidden" name="action" value="vp_save_class_template">
            <input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>">
            <?php wp_nonce_field( 'vp_save_class_template' ); ?>
            <p><label><?php esc_html_e( 'Name', 'vereins-plugin' ); ?><br>
                <input type="text" class="regular-text" name="name" required value="<?php echo esc_attr( $t['name'] ); ?>"></label></p>
            <p><label><?php esc_html_e( 'Hinweis / Quelle', 'vereins-plugin' ); ?><br>
                <textarea name="note" rows="2" class="large-text"><?php echo esc_textarea( $t['note'] ); ?></textarea></label></p>
            <?php self::editor( 'classes', $t['classes'], 'age' ); ?>
            <p><button class="button button-primary"><?php esc_html_e( 'Speichern', 'vereins-plugin' ); ?></button></p>
        </form>
        <?php
    }

    public function handle_save(): void {
        check_admin_referer( 'vp_save_class_template' );
        $this->require_admin();
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $slug = sanitize_key( $_POST['slug'] ?? '' );
        if ( $slug === '' ) {
            $slug = sanitize_key( remove_accents( $name ) ) ?: 'vorlage';
            $basis = $slug;
            for ( $i = 2; ClassTemplates::find( $slug ) || ClassTemplates::is_shipped( $slug ); $i++ ) {
                $slug = $basis . '-' . $i;
            }
        }
        ClassTemplates::save( $slug, [
            'name'    => $name,
            'note'    => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
            'classes' => self::parse_rows( (array) ( $_POST['classes'] ?? [] ), 'age' ),
        ] );
        wp_safe_redirect( $this->url( [ 'msg' => 'saved' ] ) );
        exit;
    }

    public function handle_reset(): void {
        $slug = sanitize_key( $_POST['slug'] ?? '' );
        check_admin_referer( 'vp_reset_class_template_' . $slug );
        $this->require_admin();
        ClassTemplates::reset( $slug );
        wp_safe_redirect( $this->url( [ 'msg' => 'reset' ] ) );
        exit;
    }

    public function handle_remove(): void {
        $slug = sanitize_key( $_POST['slug'] ?? '' );
        check_admin_referer( 'vp_remove_class_template_' . $slug );
        $this->require_admin();
        ClassTemplates::remove( $slug );
        wp_safe_redirect( $this->url( [ 'msg' => 'removed' ] ) );
        exit;
    }

    private function require_admin(): void {
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ), 403 );
        }
    }
}
