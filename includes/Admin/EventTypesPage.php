<?php

namespace VereinsPlugin\Admin;

use VereinsPlugin\Events\EventTypeModel;
use VereinsPlugin\Events\TemplateModel;
use VereinsPlugin\Support\Capabilities;

/**
 * wp-admin-Seite "Termintypen": Liste + Anlegen/Bearbeiten/Löschen.
 */
class EventTypesPage {

    private EventTypeModel $model;
    private TemplateModel  $templates;

    public function __construct() {
        $this->model     = new EventTypeModel();
        $this->templates = new TemplateModel();
    }

    public function register(): void {
        add_action( 'admin_post_vp_save_event_type',   [ $this, 'handle_save' ] );
        add_action( 'admin_post_vp_delete_event_type', [ $this, 'handle_delete' ] );
    }

    public function render(): void {
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
        $editing = $edit_id ? $this->model->find( $edit_id ) : null;
        $types   = $this->model->all();

        $bring_templates = $this->templates->all( 'bring' );
        $help_templates  = $this->templates->all( 'help' );

        AdminStyles::print_inline();
        ?>
        <div class="wrap vp-admin-wrap">
            <h1><?php esc_html_e( 'Termintypen', 'vereins-plugin' ); ?></h1>
            <?php $this->render_notices(); ?>

            <div class="vp-head">
                <div class="vp-head__actions">
                    <?php if ( $editing ) : ?>
                        <?php AdminModal::trigger( 'vp-type-form', __( 'Bearbeitung fortsetzen', 'vereins-plugin' ) ); ?>
                        <a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => 'vp-event-types', 'neu' => 1 ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Neuer Termintyp', 'vereins-plugin' ); ?></a>
                    <?php else : ?>
                        <?php AdminModal::trigger( 'vp-type-form', __( 'Neuer Termintyp', 'vereins-plugin' ) ); ?>
                    <?php endif; ?>
                </div>

                <?php if ( $editing ) : ?>
                    <div class="vp-head__meta">
                        <span class="description">
                            <?php printf( esc_html__( 'In Bearbeitung: %s', 'vereins-plugin' ), esc_html( $editing['name'] ) ); ?>
                            – <a href="<?php echo esc_url( admin_url( 'admin.php?page=vp-event-types' ) ); ?>"><?php esc_html_e( 'Bearbeitung beenden', 'vereins-plugin' ); ?></a>
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <table class="vp-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Farbe', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Ressource?', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Mitbringliste', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Helfer', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Aktionen', 'vereins-plugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $types ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'Noch keine Termintypen angelegt.', 'vereins-plugin' ); ?></td></tr>
                <?php endif; ?>
                <?php foreach ( $types as $t ) : ?>
                    <tr class="<?php echo (int) $t['id'] === $edit_id ? 'is-editing' : ''; ?>">
                        <td><?php echo esc_html( $t['name'] ); ?></td>
                        <td>
                            <span class="vp-swatch" style="background:<?php echo esc_attr( $t['color'] ); ?>"></span>
                            <?php echo esc_html( $t['color'] ); ?>
                        </td>
                        <td><?php echo $t['is_resource'] ? esc_html__( 'Ja', 'vereins-plugin' ) : esc_html__( 'Nein', 'vereins-plugin' ); ?></td>
                        <td><?php echo esc_html( $this->feature_label( $t, 'allows_items', 'default_item_template_id' ) ); ?></td>
                        <td><?php echo esc_html( $this->feature_label( $t, 'needs_helpers', 'default_help_template_id' ) ); ?></td>
                        <td class="vp-actions">
                            <a class="button button-small" href="<?php echo esc_url( add_query_arg( [ 'page' => 'vp-event-types', 'edit' => $t['id'] ], admin_url( 'admin.php' ) ) ); ?>">
                                <?php esc_html_e( 'Bearbeiten', 'vereins-plugin' ); ?>
                            </a>
                            <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Termintyp wirklich löschen?', 'vereins-plugin' ) ); ?>');">
                                <?php wp_nonce_field( 'vp_delete_event_type_' . $t['id'] ); ?>
                                <input type="hidden" name="action" value="vp_delete_event_type">
                                <input type="hidden" name="id" value="<?php echo esc_attr( $t['id'] ); ?>">
                                <button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Löschen', 'vereins-plugin' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            AdminModal::open(
                'vp-type-form',
                $editing ? __( 'Termintyp bearbeiten', 'vereins-plugin' ) : __( 'Neuer Termintyp', 'vereins-plugin' ),
                $editing || ! empty( $_GET['neu'] ),
                $editing['name'] ?? ''
            );
            ?>
            <div class="vp-form-card">
                <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'vp_save_event_type' ); ?>
                    <input type="hidden" name="action" value="vp_save_event_type">
                    <?php if ( $editing ) : ?>
                        <input type="hidden" name="id" value="<?php echo esc_attr( $editing['id'] ); ?>">
                    <?php endif; ?>

                    <div class="vp-field">
                        <label for="vp-name"><?php esc_html_e( 'Name', 'vereins-plugin' ); ?></label>
                        <input type="text" <?php echo AdminForms::NO_AUTOFILL; ?> id="vp-name" name="name" required value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>">
                    </div>

                    <div class="vp-row">
                        <div class="vp-field">
                            <label for="vp-color"><?php esc_html_e( 'Farbe', 'vereins-plugin' ); ?></label>
                            <input type="text" <?php echo AdminForms::NO_AUTOFILL; ?> id="vp-color" name="color" value="<?php echo esc_attr( $editing['color'] ?? '#3B82F6' ); ?>" placeholder="#3B82F6">
                        </div>
                    </div>


                    <div class="vp-field">
                        <label>
                            <input type="checkbox" name="is_resource" value="1" <?php checked( ! empty( $editing['is_resource'] ) ); ?>>
                            <?php esc_html_e( 'Ist eine Ressource (z. B. Bus, Raum)', 'vereins-plugin' ); ?>
                        </label>
                    </div>

                    <hr>

                    <div class="vp-field">
                        <label>
                            <input type="checkbox" name="allows_items" value="1"
                                   class="vp-toggle" data-controls="vp-items-options"
                                   <?php checked( ! empty( $editing['allows_items'] ) ); ?>>
                            <?php esc_html_e( 'Termine dieses Typs können eine Mitbringliste haben', 'vereins-plugin' ); ?>
                        </label>
                    </div>

                    <div class="vp-field vp-dependent" id="vp-items-options">
                        <label for="vp-item-template"><?php esc_html_e( 'Standardvorlage Mitbringliste', 'vereins-plugin' ); ?></label>
                        <select id="vp-item-template" name="default_item_template_id">
                            <option value="0"><?php esc_html_e( '– keine –', 'vereins-plugin' ); ?></option>
                            <?php foreach ( $bring_templates as $tpl ) : ?>
                                <option value="<?php echo esc_attr( $tpl['id'] ); ?>" <?php selected( (int) ( $editing['default_item_template_id'] ?? 0 ), (int) $tpl['id'] ); ?>>
                                    <?php echo esc_html( $tpl['name'] ); ?> (<?php echo count( $tpl['items'] ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Wird beim Anlegen eines Termins vorbelegt und kann dort geändert werden.', 'vereins-plugin' ); ?></p>
                    </div>

                    <div class="vp-field">
                        <label>
                            <input type="checkbox" name="needs_helpers" value="1"
                                   class="vp-toggle" data-controls="vp-helpers-options"
                                   <?php checked( ! empty( $editing['needs_helpers'] ) ); ?>>
                            <?php esc_html_e( 'Für Termine dieses Typs werden Helfer benötigt', 'vereins-plugin' ); ?>
                        </label>
                    </div>

                    <div class="vp-field vp-dependent" id="vp-helpers-options">
                        <label for="vp-help-template"><?php esc_html_e( 'Standardvorlage Helferaufgaben', 'vereins-plugin' ); ?></label>
                        <select id="vp-help-template" name="default_help_template_id">
                            <option value="0"><?php esc_html_e( '– keine –', 'vereins-plugin' ); ?></option>
                            <?php foreach ( $help_templates as $tpl ) : ?>
                                <option value="<?php echo esc_attr( $tpl['id'] ); ?>" <?php selected( (int) ( $editing['default_help_template_id'] ?? 0 ), (int) $tpl['id'] ); ?>>
                                    <?php echo esc_html( $tpl['name'] ); ?> (<?php echo count( $tpl['items'] ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php
                            printf(
                                esc_html__( 'Vorlagen pflegst du unter %s.', 'vereins-plugin' ),
                                '<a href="' . esc_url( admin_url( 'admin.php?page=vp-templates' ) ) . '">' . esc_html__( 'Verein → Vorlagen', 'vereins-plugin' ) . '</a>'
                            );
                            ?>
                        </p>
                    </div>

                    <div class="vp-field">
                        <label>
                            <input type="checkbox" name="allows_photos" value="1" <?php checked( ! empty( $editing['allows_photos'] ) ); ?>>
                            <?php esc_html_e( 'Mitglieder können Fotos zu Terminen dieses Typs hochladen', 'vereins-plugin' ); ?>
                        </label>
                        <p class="description">
                            <?php
                            printf(
                                esc_html__( 'Aufbewahrungsfrist und Speichergrenze stellst du unter %s ein.', 'vereins-plugin' ),
                                '<a href="' . esc_url( admin_url( 'admin.php?page=vp-settings' ) ) . '">' . esc_html__( 'Verein → Einstellungen', 'vereins-plugin' ) . '</a>'
                            );
                            ?>
                        </p>
                    </div>

                    <script>
                    (function(){
                        // Vorlagen-Auswahl nur aktiv, solange die zugehörige
                        // Funktion überhaupt eingeschaltet ist.
                        document.querySelectorAll('.vp-toggle').forEach(function(cb){
                            var box = document.getElementById(cb.dataset.controls);
                            if (!box) { return; }

                            function sync(){
                                box.classList.toggle('is-disabled', !cb.checked);
                                box.querySelectorAll('select, input').forEach(function(el){
                                    el.disabled = !cb.checked;
                                });
                            }

                            cb.addEventListener('change', sync);
                            sync();
                        });
                    })();
                    </script>

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php echo $editing ? esc_html__( 'Speichern', 'vereins-plugin' ) : esc_html__( 'Anlegen', 'vereins-plugin' ); ?></button>
                        <?php if ( $editing ) : ?>
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=vp-event-types' ) ); ?>"><?php esc_html_e( 'Abbrechen', 'vereins-plugin' ); ?></a>
                        <?php else : ?>
                            <button type="button" class="button" data-vp-modal-close><?php esc_html_e( 'Abbrechen', 'vereins-plugin' ); ?></button>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
            <?php AdminModal::close(); ?>
        </div>
        <?php
    }

    public function handle_save(): void {
        check_admin_referer( 'vp_save_event_type' );
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $data = [
            'name'                     => sanitize_text_field( $_POST['name'] ?? '' ),
            'color'                    => preg_match( '/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '' ) ? $_POST['color'] : '#3B82F6',
            'is_resource'              => ! empty( $_POST['is_resource'] ) ? 1 : 0,
            'allows_items'             => ! empty( $_POST['allows_items'] ) ? 1 : 0,
            'needs_helpers'            => ! empty( $_POST['needs_helpers'] ) ? 1 : 0,
            'allows_photos'            => ! empty( $_POST['allows_photos'] ) ? 1 : 0,
            'default_item_template_id' => (int) ( $_POST['default_item_template_id'] ?? 0 ) ?: null,
            'default_help_template_id' => (int) ( $_POST['default_help_template_id'] ?? 0 ) ?: null,
        ];

        if ( $id ) {
            $this->model->update( $id, $data );
        } else {
            $data['created_by'] = get_current_user_id();
            $this->model->create( $data );
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'vp-event-types', 'vp_notice' => 'saved' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_delete(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_delete_event_type_' . $id );
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        if ( $this->model->in_use( $id ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'vp-event-types', 'vp_notice' => 'in_use' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $this->model->delete( $id );
        wp_safe_redirect( add_query_arg( [ 'page' => 'vp-event-types', 'vp_notice' => 'deleted' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Kurzanzeige für die Übersicht: aktiv/inaktiv plus Name der Standardvorlage.
     */
    private function feature_label( array $type, string $flag, string $template_key ): string {
        if ( empty( $type[ $flag ] ) ) {
            return __( '—', 'vereins-plugin' );
        }

        $template_id = (int) ( $type[ $template_key ] ?? 0 );
        if ( ! $template_id ) {
            return __( 'ja (ohne Vorlage)', 'vereins-plugin' );
        }

        $template = $this->templates->find( $template_id );
        return $template
            ? sprintf( __( 'ja – %s', 'vereins-plugin' ), $template['name'] )
            : __( 'ja (ohne Vorlage)', 'vereins-plugin' );
    }

    private function render_notices(): void {
        $notice = sanitize_text_field( $_GET['vp_notice'] ?? '' );
        if ( ! $notice ) {
            return;
        }
        $map = [
            'saved'   => [ 'success', __( 'Termintyp gespeichert.', 'vereins-plugin' ) ],
            'deleted' => [ 'success', __( 'Termintyp gelöscht.', 'vereins-plugin' ) ],
            'in_use'  => [ 'error',   __( 'Termintyp wird noch von Terminen verwendet und kann nicht gelöscht werden.', 'vereins-plugin' ) ],
        ];
        if ( ! isset( $map[ $notice ] ) ) {
            return;
        }
        [ $type, $text ] = $map[ $notice ];
        printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
    }
}
