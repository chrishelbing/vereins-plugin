<?php

namespace VereinsPlugin\Admin;

use VereinsPlugin\Events\EventModel;
use VereinsPlugin\Events\ItemModel;
use VereinsPlugin\Events\TemplateModel;
use VereinsPlugin\Support\Capabilities;

/**
 * wp-admin-Seite "Vorlagen": Vorlagen für Mitbringliste und Helferaufgaben
 * anlegen, bearbeiten, löschen – oder aus einem bestehenden Termin erzeugen.
 */
class TemplatesPage {

    private TemplateModel $model;
    private EventModel    $events;
    private ItemModel     $items;

    public function __construct() {
        $this->model  = new TemplateModel();
        $this->events = new EventModel();
        $this->items  = new ItemModel();
    }

    public function register(): void {
        add_action( 'admin_post_vp_save_template',        [ $this, 'handle_save' ] );
        add_action( 'admin_post_vp_delete_template',      [ $this, 'handle_delete' ] );
        add_action( 'admin_post_vp_template_from_event',  [ $this, 'handle_from_event' ] );
    }

    public function render(): void {
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $edit_id   = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
        $editing   = $edit_id ? $this->model->find( $edit_id ) : null;
        $templates = $this->model->all();

        AdminStyles::print_inline();
        ?>
        <div class="wrap vp-admin-wrap">
            <h1><?php esc_html_e( 'Vorlagen', 'vereins-plugin' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Vorlagen beschreiben, was bei einem Termin mitgebracht wird oder wobei geholfen werden muss. Sie lassen sich am Termintyp als Standard hinterlegen.', 'vereins-plugin' ); ?>
            </p>
            <?php $this->render_notices(); ?>

            <div class="vp-head">
                <div class="vp-head__actions">
                    <?php if ( $editing ) : ?>
                        <?php AdminModal::trigger( 'vp-template-form', __( 'Bearbeitung fortsetzen', 'vereins-plugin' ) ); ?>
                        <a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => 'vp-templates', 'neu' => 1 ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Neue Vorlage', 'vereins-plugin' ); ?></a>
                    <?php else : ?>
                        <?php AdminModal::trigger( 'vp-template-form', __( 'Neue Vorlage', 'vereins-plugin' ) ); ?>
                    <?php endif; ?>
                    <?php AdminModal::trigger( 'vp-template-from-event', __( 'Aus Termin erzeugen', 'vereins-plugin' ), '' ); ?>
                </div>

                <?php if ( $editing ) : ?>
                    <div class="vp-head__meta">
                        <span class="description">
                            <?php printf( esc_html__( 'In Bearbeitung: %s', 'vereins-plugin' ), esc_html( $editing['name'] ) ); ?>
                            – <a href="<?php echo esc_url( admin_url( 'admin.php?page=vp-templates' ) ); ?>"><?php esc_html_e( 'Bearbeitung beenden', 'vereins-plugin' ); ?></a>
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <table class="vp-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Art', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Posten', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Aktionen', 'vereins-plugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $templates ) ) : ?>
                    <tr><td colspan="4"><?php esc_html_e( 'Noch keine Vorlagen angelegt.', 'vereins-plugin' ); ?></td></tr>
                <?php endif; ?>
                <?php foreach ( $templates as $tpl ) : ?>
                    <tr class="<?php echo (int) $tpl['id'] === $edit_id ? 'is-editing' : ''; ?>">
                        <td><strong><?php echo esc_html( $tpl['name'] ); ?></strong></td>
                        <td><?php echo esc_html( $this->kind_label( $tpl['kind'] ) ); ?></td>
                        <td>
                            <?php if ( empty( $tpl['items'] ) ) : ?>
                                <span class="description"><?php esc_html_e( 'leer', 'vereins-plugin' ); ?></span>
                            <?php else : ?>
                                <?php
                                echo esc_html( implode( ', ', array_map(
                                    fn( $i ) => $i['label'] . ( (int) $i['quantity_needed'] > 1 ? ' ' . $i['quantity_needed'] . '×' : '' ),
                                    $tpl['items']
                                ) ) );
                                ?>
                            <?php endif; ?>
                        </td>
                        <td class="vp-actions">
                            <a class="button button-small" href="<?php echo esc_url( add_query_arg( [ 'page' => 'vp-templates', 'edit' => $tpl['id'] ], admin_url( 'admin.php' ) ) ); ?>">
                                <?php esc_html_e( 'Bearbeiten', 'vereins-plugin' ); ?>
                            </a>
                            <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Vorlage wirklich löschen?', 'vereins-plugin' ) ); ?>');">
                                <?php wp_nonce_field( 'vp_delete_template_' . $tpl['id'] ); ?>
                                <input type="hidden" name="action" value="vp_delete_template">
                                <input type="hidden" name="id" value="<?php echo esc_attr( $tpl['id'] ); ?>">
                                <button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Löschen', 'vereins-plugin' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php $this->render_form( $editing ); ?>
            <?php $this->render_from_event_form(); ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Formulare
    // ------------------------------------------------------------------

    private function render_form( ?array $editing ): void {
        // Posten als Textzeilen: "Kuchen 3" – eine Zeile je Posten.
        $lines = '';
        if ( $editing ) {
            foreach ( $editing['items'] as $item ) {
                $lines .= $item['label'] . ' ' . (int) $item['quantity_needed'] . "\n";
            }
        }
        ?>
        <?php
        AdminModal::open(
            'vp-template-form',
            $editing ? __( 'Vorlage bearbeiten', 'vereins-plugin' ) : __( 'Neue Vorlage', 'vereins-plugin' ),
            $editing || ! empty( $_GET['neu'] ),
            $editing['name'] ?? ''
        );
        ?>
        <div class="vp-form-card">
            <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'vp_save_template' ); ?>
                <input type="hidden" name="action" value="vp_save_template">
                <?php if ( $editing ) : ?>
                    <input type="hidden" name="id" value="<?php echo esc_attr( $editing['id'] ); ?>">
                <?php endif; ?>

                <div class="vp-row">
                    <div class="vp-field">
                        <label for="vp-tpl-name"><?php esc_html_e( 'Name', 'vereins-plugin' ); ?></label>
                        <input type="text" <?php echo AdminForms::NO_AUTOFILL; ?> id="vp-tpl-name" name="name" required
                               value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>"
                               placeholder="<?php esc_attr_e( 'z. B. Sommerfest', 'vereins-plugin' ); ?>">
                    </div>
                    <div class="vp-field">
                        <label for="vp-tpl-kind"><?php esc_html_e( 'Art', 'vereins-plugin' ); ?></label>
                        <?php if ( $editing ) : ?>
                            <input type="text" <?php echo AdminForms::NO_AUTOFILL; ?> id="vp-tpl-kind" value="<?php echo esc_attr( $this->kind_label( $editing['kind'] ) ); ?>" disabled>
                            <p class="description"><?php esc_html_e( 'Die Art lässt sich nachträglich nicht ändern.', 'vereins-plugin' ); ?></p>
                        <?php else : ?>
                            <select id="vp-tpl-kind" name="kind">
                                <option value="bring"><?php esc_html_e( 'Mitbringliste', 'vereins-plugin' ); ?></option>
                                <option value="help"><?php esc_html_e( 'Helferaufgaben', 'vereins-plugin' ); ?></option>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="vp-field">
                    <label for="vp-tpl-items"><?php esc_html_e( 'Posten', 'vereins-plugin' ); ?></label>
                    <textarea id="vp-tpl-items" name="items" rows="7"
                              placeholder="<?php echo esc_attr( ( $editing['kind'] ?? 'bring' ) === 'help' ? $this->help_example() : $this->bring_example() ); ?>"><?php echo esc_textarea( trim( $lines ) ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Eine Zeile je Posten, am Ende optional die benötigte Anzahl. Ohne Zahl gilt 1.', 'vereins-plugin' ); ?>
                    </p>
                </div>

                <?php if ( ! $editing ) : ?>
                <script>
                (function(){
                    // Beispiel im Platzhalter zur gewählten Art passend halten.
                    var kind = document.getElementById('vp-tpl-kind');
                    var box  = document.getElementById('vp-tpl-items');
                    var ex   = {
                        bring: <?php echo json_encode( $this->bring_example() ); ?>,
                        help:  <?php echo json_encode( $this->help_example() ); ?>
                    };
                    if (!kind || !box) { return; }
                    kind.addEventListener('change', function(){
                        box.placeholder = ex[kind.value] || ex.bring;
                    });
                })();
                </script>
                <?php endif; ?>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php echo $editing ? esc_html__( 'Speichern', 'vereins-plugin' ) : esc_html__( 'Anlegen', 'vereins-plugin' ); ?></button>
                    <?php if ( $editing ) : ?>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=vp-templates' ) ); ?>"><?php esc_html_e( 'Abbrechen', 'vereins-plugin' ); ?></a>
                    <?php else : ?>
                        <button type="button" class="button" data-vp-modal-close><?php esc_html_e( 'Abbrechen', 'vereins-plugin' ); ?></button>
                    <?php endif; ?>
                </p>
            </form>
        </div>
        <?php
        AdminModal::close();
    }

    /**
     * Vorlage aus einem bestehenden Termin erzeugen. Angeboten werden nur
     * Termine, die überhaupt Posten haben.
     */
    private function render_from_event_form(): void {
        $candidates = $this->events_with_items();
        ?>
        <?php AdminModal::open( 'vp-template-from-event', __( 'Vorlage aus bestehendem Termin erzeugen', 'vereins-plugin' ) ); ?>
        <div class="vp-form-card">
            <?php if ( empty( $candidates ) ) : ?>
                <p class="description"><?php esc_html_e( 'Es gibt noch keinen Termin mit Mitbringliste oder Helferaufgaben.', 'vereins-plugin' ); ?></p>
            <?php else : ?>
                <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'vp_template_from_event' ); ?>
                    <input type="hidden" name="action" value="vp_template_from_event">

                    <div class="vp-field">
                        <label for="vp-src-event"><?php esc_html_e( 'Termin', 'vereins-plugin' ); ?></label>
                        <select id="vp-src-event" name="event_id" required>
                            <?php foreach ( $candidates as $c ) : ?>
                                <option value="<?php echo esc_attr( $c['id'] ); ?>">
                                    <?php echo esc_html( $c['title'] . ' (' . mysql2date( 'd.m.Y', $c['start_at'] ) . ')' ); ?>
                                    – <?php echo esc_html( $this->candidate_summary( $c ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="vp-row">
                        <div class="vp-field">
                            <label for="vp-src-kind"><?php esc_html_e( 'Art', 'vereins-plugin' ); ?></label>
                            <select id="vp-src-kind" name="kind">
                                <option value="bring"><?php esc_html_e( 'Mitbringliste', 'vereins-plugin' ); ?></option>
                                <option value="help"><?php esc_html_e( 'Helferaufgaben', 'vereins-plugin' ); ?></option>
                            </select>
                        </div>
                        <div class="vp-field">
                            <label for="vp-src-name"><?php esc_html_e( 'Name der neuen Vorlage', 'vereins-plugin' ); ?></label>
                            <input type="text" <?php echo AdminForms::NO_AUTOFILL; ?> id="vp-src-name" name="name" required>
                        </div>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Vorlage erzeugen', 'vereins-plugin' ); ?></button>
                        <button type="button" class="button" data-vp-modal-close><?php esc_html_e( 'Abbrechen', 'vereins-plugin' ); ?></button>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
        AdminModal::close();
    }

    // ------------------------------------------------------------------
    // Handler
    // ------------------------------------------------------------------

    public function handle_save(): void {
        check_admin_referer( 'vp_save_template' );
        $this->require_admin();

        $id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $name  = sanitize_text_field( $_POST['name'] ?? '' );
        $items = $this->parse_items( $_POST['items'] ?? '' );

        if ( $id ) {
            $this->model->update( $id, [ 'name' => $name, 'items' => $items ] );
        } else {
            $this->model->create( [
                'kind'       => sanitize_text_field( $_POST['kind'] ?? 'bring' ),
                'name'       => $name,
                'items'      => $items,
                'created_by' => get_current_user_id(),
            ] );
        }

        $this->redirect( 'saved' );
    }

    public function handle_delete(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_delete_template_' . $id );
        $this->require_admin();

        $this->model->delete( $id );
        $this->redirect( 'deleted' );
    }

    public function handle_from_event(): void {
        check_admin_referer( 'vp_template_from_event' );
        $this->require_admin();

        $new_id = $this->model->create_from_event(
            (int) ( $_POST['event_id'] ?? 0 ),
            sanitize_text_field( $_POST['kind'] ?? 'bring' ),
            sanitize_text_field( $_POST['name'] ?? '' ),
            get_current_user_id()
        );

        $this->redirect( $new_id ? 'generated' : 'empty_source' );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Zerlegt die Textarea in Posten. Eine abschließende Zahl gilt als Menge:
     * "Kuchen 3" → label "Kuchen", Menge 3. "Bierzeltgarnitur" → Menge 1.
     *
     * @return array<int, array{label:string, quantity_needed:int}>
     */
    private function parse_items( string $raw ): array {
        $items = [];

        foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
            $line = trim( sanitize_text_field( $line ) );
            if ( $line === '' ) {
                continue;
            }

            $quantity = 1;
            if ( preg_match( '/^(.*?)[\s×x]+(\d{1,3})$/u', $line, $m ) ) {
                $label    = trim( $m[1] );
                $quantity = max( 1, (int) $m[2] );
            } else {
                $label = $line;
            }

            if ( $label !== '' ) {
                $items[] = [ 'label' => $label, 'quantity_needed' => $quantity ];
            }
        }

        return $items;
    }

    /**
     * Termine, zu denen es überhaupt Posten gibt – inklusive Zahl je Art.
     */
    private function events_with_items(): array {
        $result = [];

        foreach ( $this->events->all( null, 100 ) as $event ) {
            $bring = $this->items->totals_for_event( (int) $event['id'], 'bring' );
            $help  = $this->items->totals_for_event( (int) $event['id'], 'help' );

            if ( $bring['items'] === 0 && $help['items'] === 0 ) {
                continue;
            }

            $event['bring_count'] = $bring['items'];
            $event['help_count']  = $help['items'];
            $result[]             = $event;
        }

        return $result;
    }

    private function candidate_summary( array $event ): string {
        $parts = [];
        if ( $event['bring_count'] ) {
            $parts[] = sprintf( __( '%d Mitbring-Posten', 'vereins-plugin' ), $event['bring_count'] );
        }
        if ( $event['help_count'] ) {
            $parts[] = sprintf( __( '%d Helferaufgaben', 'vereins-plugin' ), $event['help_count'] );
        }
        return implode( ', ', $parts );
    }

    private function bring_example(): string {
        return __( "Kuchen 3\nSalat 2\nGetränke 1", 'vereins-plugin' );
    }

    private function help_example(): string {
        return __( "Aufbau 4\nEinlass 2\nCatering 3\nAbbau 4", 'vereins-plugin' );
    }

    private function kind_label( string $kind ): string {
        return $kind === 'help'
            ? __( 'Helferaufgaben', 'vereins-plugin' )
            : __( 'Mitbringliste', 'vereins-plugin' );
    }

    private function require_admin(): void {
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }
    }

    private function redirect( string $notice ): void {
        wp_safe_redirect( add_query_arg( [ 'page' => 'vp-templates', 'vp_notice' => $notice ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private function render_notices(): void {
        $notice = sanitize_text_field( $_GET['vp_notice'] ?? '' );
        $map    = [
            'saved'        => [ 'success', __( 'Vorlage gespeichert.', 'vereins-plugin' ) ],
            'deleted'      => [ 'success', __( 'Vorlage gelöscht.', 'vereins-plugin' ) ],
            'generated'    => [ 'success', __( 'Vorlage aus dem Termin erzeugt.', 'vereins-plugin' ) ],
            'empty_source' => [ 'error',   __( 'Der gewählte Termin hat für diese Art keine Posten.', 'vereins-plugin' ) ],
        ];

        if ( ! isset( $map[ $notice ] ) ) {
            return;
        }

        [ $type, $text ] = $map[ $notice ];
        printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
    }
}
