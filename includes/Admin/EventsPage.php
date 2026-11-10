<?php

namespace VereinsPlugin\Admin;

use VereinsPlugin\Flyer\FlyerPage;
use VereinsPlugin\Support\DateRange;
use VereinsPlugin\Events\AttendanceModel;
use VereinsPlugin\Events\CompanionModel;
use VereinsPlugin\Events\EventModel;
use VereinsPlugin\Events\EventTypeModel;
use VereinsPlugin\Events\ItemModel;
use VereinsPlugin\Events\TemplateModel;
use VereinsPlugin\Photos\PhotoModel;
use VereinsPlugin\Support\Capabilities;
use VereinsPlugin\Support\Tags;

/**
 * wp-admin-Seite "Termine": Liste + Anlegen/Bearbeiten (inkl. Wiederholung) + Löschen.
 */
class EventsPage {

    /** Vorgeschlagener Vorlauf, wenn der Banner eingeschaltet wird. */
    private const BANNER_DEFAULT_DAYS = 14;

    private EventModel      $model;
    private EventTypeModel  $type_model;
    private AttendanceModel $attendance;
    private ItemModel       $items;
    private TemplateModel   $templates;
    private PhotoModel      $photos;
    private CompanionModel  $companions;

    public function __construct() {
        $this->model      = new EventModel();
        $this->type_model = new EventTypeModel();
        $this->attendance = new AttendanceModel();
        $this->items      = new ItemModel();
        $this->templates  = new TemplateModel();
        $this->photos     = new PhotoModel();
        $this->companions = new CompanionModel();
    }

    public function register(): void {
        add_action( 'admin_post_vp_save_event',    [ $this, 'handle_save' ] );
        add_action( 'admin_post_vp_delete_event',  [ $this, 'handle_delete' ] );
        add_action( 'admin_post_vp_event_approve', [ $this, 'handle_approve' ] );
        add_action( 'admin_post_vp_event_reject',  [ $this, 'handle_reject' ] );
        add_action( 'admin_post_vp_event_live',    [ $this, 'handle_live' ] );
    }

    public function render(): void {
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        // Listen-Ansicht eines Termins läuft über dieselbe Seite.
        if ( ( $_GET['view'] ?? '' ) === 'lists' ) {
            ( new AttendancePage() )->render();
            return;
        }

        $edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
        $editing = $edit_id ? $this->model->find( $edit_id ) : null;
        $types   = $this->type_model->all();

        $filter = sanitize_text_field( $_GET['status'] ?? '' );
        $filter = in_array( $filter, [ 'draft', 'pending', 'approved', 'rejected' ], true ) ? $filter : '';
        $events = $this->model->all( $filter ?: null );

        $counts = [
            'draft'    => $this->model->count_by_status( 'draft' ),
            'pending'  => $this->model->count_by_status( 'pending' ),
            'approved' => $this->model->count_by_status( 'approved' ),
            'rejected' => $this->model->count_by_status( 'rejected' ),
        ];

        AdminStyles::print_inline();
        ?>
        <div class="wrap vp-admin-wrap">
            <h1><?php esc_html_e( 'Termine', 'vereins-plugin' ); ?></h1>
            <?php $this->render_notices(); ?>

            <div class="vp-head">
                <div class="vp-head__actions">
                    <?php if ( $editing ) : ?>
                        <?php AdminModal::trigger( 'vp-event-form', __( 'Bearbeitung fortsetzen', 'vereins-plugin' ) ); ?>
                        <a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => 'vp-events', 'neu' => 1 ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Neuer Termin', 'vereins-plugin' ); ?></a>
                    <?php else : ?>
                        <?php AdminModal::trigger( 'vp-event-form', __( 'Neuer Termin', 'vereins-plugin' ) ); ?>
                    <?php endif; ?>
                </div>

                <div class="vp-head__meta">
                    <ul class="subsubsub vp-tabs">
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=vp-events' ) ); ?>" <?php echo $filter === '' ? 'class="current"' : ''; ?>><?php esc_html_e( 'Alle', 'vereins-plugin' ); ?></a> |</li>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=vp-events&status=draft' ) ); ?>" <?php echo $filter === 'draft' ? 'class="current"' : ''; ?>><?php printf( esc_html__( 'Entwürfe (%d)', 'vereins-plugin' ), (int) $counts['draft'] ); ?></a> |</li>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=vp-events&status=pending' ) ); ?>" <?php echo $filter === 'pending' ? 'class="current"' : ''; ?>><?php printf( esc_html__( 'Ausstehend (%d)', 'vereins-plugin' ), (int) $counts['pending'] ); ?></a> |</li>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=vp-events&status=approved' ) ); ?>" <?php echo $filter === 'approved' ? 'class="current"' : ''; ?>><?php printf( esc_html__( 'Live (%d)', 'vereins-plugin' ), (int) $counts['approved'] ); ?></a> |</li>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=vp-events&status=rejected' ) ); ?>" <?php echo $filter === 'rejected' ? 'class="current"' : ''; ?>><?php printf( esc_html__( 'Abgelehnt (%d)', 'vereins-plugin' ), (int) $counts['rejected'] ); ?></a></li>
                    </ul>

                    <?php if ( $editing ) : ?>
                        <span class="description">
                            <?php printf( esc_html__( 'In Bearbeitung: %s', 'vereins-plugin' ), esc_html( $editing['title'] ) ); ?>
                            – <a href="<?php echo esc_url( admin_url( 'admin.php?page=vp-events' ) ); ?>"><?php esc_html_e( 'Bearbeitung beenden', 'vereins-plugin' ); ?></a>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <table class="vp-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Titel', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Typ', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Start', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Ende', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Serie?', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Rückmeldungen', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Angelegt von', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Geprüft von', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Aktionen', 'vereins-plugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $events ) ) : ?>
                    <tr><td colspan="10"><?php esc_html_e( 'Keine Termine in dieser Ansicht.', 'vereins-plugin' ); ?></td></tr>
                <?php endif; ?>
                <?php foreach ( $events as $e ) : ?>
                    <tr class="<?php echo (int) $e['id'] === $edit_id ? 'is-editing' : ''; ?>">
                        <td>
                            <?php echo esc_html( $e['title'] ); ?>
                            <?php if ( isset( $e['banner_days_before'] ) && $e['banner_days_before'] !== null ) : ?>
                                <span class="vp-badge vp-badge--active" title="<?php echo esc_attr( sprintf(
                                    /* translators: %d: Tage vor Beginn */
                                    __( 'Als Banner sichtbar ab %d Tagen vor Beginn', 'vereins-plugin' ),
                                    (int) $e['banner_days_before']
                                ) ); ?>"><?php esc_html_e( 'Banner', 'vereins-plugin' ); ?></span>
                            <?php endif; ?>
                            <?php if ( ! empty( $e['is_public'] ) ) : ?>
                                <span class="vp-badge vp-badge--active" title="<?php esc_attr_e( 'Auch ohne Anmeldung in der Terminliste sichtbar', 'vereins-plugin' ); ?>"><?php esc_html_e( 'Öffentlich', 'vereins-plugin' ); ?></span>
                            <?php endif; ?>
                            <?php $e_tags = Tags::decode( $e['tags'] ?? null ); ?>
                            <?php if ( $e_tags ) : ?>
                                <br><span class="description"><?php echo esc_html( implode( ' · ', $e_tags ) ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $e['type_color'] ?? '' ) : ?>
                                <span class="vp-swatch" style="background:<?php echo esc_attr( $e['type_color'] ); ?>"></span>
                            <?php endif; ?>
                            <?php echo esc_html( $e['type_name'] ?? '—' ); ?>
                        </td>
                        <td><?php echo esc_html( mysql2date( 'd.m.Y H:i', $e['start_at'] ) ); ?></td>
                        <td>
                            <?php echo esc_html( mysql2date( 'd.m.Y H:i', $e['end_at'] ) ); ?>
                            <?php if ( DateRange::is_multi_day( $e['start_at'], $e['end_at'] ) ) : ?>
                                <span class="vp-badge vp-badge--pending"><?php
                                    printf(
                                        esc_html( _n( '%d Tag', '%d Tage', DateRange::days( $e['start_at'], $e['end_at'] ), 'vereins-plugin' ) ),
                                        DateRange::days( $e['start_at'], $e['end_at'] )
                                    );
                                ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $e['is_recurring'] ? esc_html__( 'Ja', 'vereins-plugin' ) : esc_html__( 'Nein', 'vereins-plugin' ); ?></td>
                        <td>
                            <?php $badge = [ 'approved' => 'active', 'draft' => 'draft', 'pending' => 'pending', 'rejected' => 'inactive' ][ $e['approval_status'] ] ?? 'pending'; ?>
                            <span class="vp-badge vp-badge--<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $this->status_label( $e['approval_status'] ) ); ?></span>
                        </td>
                        <td>
                            <?php if ( ! EventTypeModel::rsvp_allowed( $e ) ) : ?>
                                <span class="description"><?php esc_html_e( 'nur Info', 'vereins-plugin' ); ?></span>
                            <?php else : ?>
                            <?php $totals = $this->attendance->totals_for_event( (int) $e['id'] ); ?>
                            <span style="color:#059669;font-weight:600;"><?php printf( esc_html__( '%d zu', 'vereins-plugin' ), $totals['yes'] ); ?></span>
                            /
                            <span style="color:#dc2626;font-weight:600;"><?php printf( esc_html__( '%d ab', 'vereins-plugin' ), $totals['no'] ); ?></span>
                            <?php
                            $guest_count = $this->companions->count_for_event( (int) $e['id'] );
                            if ( $guest_count > 0 ) :
                            ?>
                                <br><span class="description">
                                    <?php printf( esc_html__( '+ %d Begleitpersonen', 'vereins-plugin' ), $guest_count ); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ( $totals['helpers'] > 0 ) : ?>
                                <br><span style="color:#2563eb;font-weight:600;"><?php printf( esc_html__( '%d Helfer', 'vereins-plugin' ), $totals['helpers'] ); ?></span>
                            <?php endif; ?>
                            <?php
                            $item_totals = $this->items->totals_for_event( (int) $e['id'] );
                            if ( $item_totals['items'] > 0 ) :
                            ?>
                                <br><span class="description">
                                    <?php
                                    printf(
                                        esc_html__( 'Mitbringliste: %1$d Posten, %2$d offen', 'vereins-plugin' ),
                                        $item_totals['items'],
                                        $item_totals['open']
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                            <?php endif; // rsvp_allowed ?>
                            <?php
                            $photo_totals = $this->photos->totals_for_event( (int) $e['id'] );
                            if ( $photo_totals['count'] > 0 ) :
                            ?>
                                <br><span class="description">
                                    <?php
                                    printf(
                                        esc_html__( 'Fotos: %1$d (%2$s)', 'vereins-plugin' ),
                                        $photo_totals['count'],
                                        esc_html( size_format( $photo_totals['bytes'], 1 ) )
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                            <?php if ( $e['is_recurring'] ) : ?>
                                <br><span class="description"><?php esc_html_e( 'alle Termine der Serie', 'vereins-plugin' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $this->user_display_name( (int) $e['created_by'] ) ); ?></td>
                        <td>
                            <?php if ( ! empty( $e['reviewed_by'] ) ) : ?>
                                <?php echo esc_html( $this->user_display_name( (int) $e['reviewed_by'] ) ); ?>
                                <?php if ( ! empty( $e['reviewed_at'] ) ) : ?>
                                    <br><span class="description"><?php echo esc_html( mysql2date( 'd.m.Y H:i', $e['reviewed_at'] ) ); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="vp-actions">
                            <?php if ( $e['approval_status'] === 'pending' ) : ?>
                                <?php $this->review_button( $e['id'], 'approve', __( 'Genehmigen', 'vereins-plugin' ), 'button-primary' ); ?>
                                <?php $this->review_button( $e['id'], 'reject', __( 'Ablehnen', 'vereins-plugin' ) ); ?>
                            <?php endif; ?>
                            <?php if ( $e['approval_status'] === 'draft' ) : ?>
                                <?php $this->live_button( (int) $e['id'], true ); ?>
                            <?php elseif ( $e['approval_status'] === 'approved' ) : ?>
                                <?php $this->live_button( (int) $e['id'], false ); ?>
                            <?php endif; ?>
                            <a class="button button-small" href="<?php echo esc_url( add_query_arg( [ 'page' => 'vp-events', 'view' => 'lists', 'event_id' => $e['id'] ], admin_url( 'admin.php' ) ) ); ?>">
                                <?php esc_html_e( 'Listen', 'vereins-plugin' ); ?>
                            </a>
            <a class="button button-small" href="<?php echo esc_url( add_query_arg( [ 'page' => 'vp-events', 'edit' => $e['id'] ], admin_url( 'admin.php' ) ) ); ?>">
                                <?php esc_html_e( 'Bearbeiten', 'vereins-plugin' ); ?>
                            </a>
                            <?php if ( $e['approval_status'] === 'approved' ) : ?>
                                <?php // Flyer immer für die erste Instanz – bei Serien wählt man die weiteren im Frontend. ?>
                                <a class="button button-small" target="_blank" rel="noopener"
                                   href="<?php echo esc_url( FlyerPage::url( (int) $e['id'], substr( $e['start_at'], 0, 10 ) ) ); ?>">
                                    <?php esc_html_e( 'Flyer', 'vereins-plugin' ); ?>
                                </a>
                            <?php endif; ?>
                            <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Termin (inkl. Serie) wirklich löschen?', 'vereins-plugin' ) ); ?>');">
                                <?php wp_nonce_field( 'vp_delete_event_' . $e['id'] ); ?>
                                <input type="hidden" name="action" value="vp_delete_event">
                                <input type="hidden" name="id" value="<?php echo esc_attr( $e['id'] ); ?>">
                                <button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Löschen', 'vereins-plugin' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            AdminModal::open(
                'vp-event-form',
                $editing ? __( 'Termin bearbeiten', 'vereins-plugin' ) : __( 'Neuer Termin', 'vereins-plugin' ),
                $editing || ! empty( $_GET['neu'] ),
                $editing['title'] ?? ''
            );
            ?>
            <div class="vp-form-card">
                <?php if ( empty( $types ) ) : ?>
                    <p>
                        <?php
                        printf(
                            /* translators: %s: link to event types page */
                            esc_html__( 'Bitte zuerst %s anlegen.', 'vereins-plugin' ),
                            '<a href="' . esc_url( admin_url( 'admin.php?page=vp-event-types' ) ) . '">' . esc_html__( 'einen Termintyp', 'vereins-plugin' ) . '</a>'
                        );
                        ?>
                    </p>
                <?php else : ?>
                <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="vp-admin-event-form">
                    <?php wp_nonce_field( 'vp_save_event' ); ?>
                    <input type="hidden" name="action" value="vp_save_event">
                    <?php if ( $editing ) : ?>
                        <input type="hidden" name="id" value="<?php echo esc_attr( $editing['id'] ); ?>">
                    <?php endif; ?>

                    <?php
                    // Ob Fotos angeboten werden, hängt am Termintyp. Der wird
                    // im selben Formular gewählt, also muss der Anfangszustand
                    // hier stimmen – das Umschalten übernimmt danach das
                    // Skript am Ende des Formulars.
                    $gewaehlter_typ = (int) ( $editing['event_type_id'] ?? ( $types[0]['id'] ?? 0 ) );
                    $fotos_erlaubt  = false;
                    foreach ( $types as $typ ) {
                        if ( (int) $typ['id'] === $gewaehlter_typ ) {
                            $fotos_erlaubt = ! empty( $typ['allows_photos'] );
                            break;
                        }
                    }
                    ?>
                    <div class="vp-field">
                        <label for="vp-type"><?php esc_html_e( 'Termintyp', 'vereins-plugin' ); ?></label>
                        <select id="vp-type" name="event_type_id" required>
                            <?php foreach ( $types as $t ) : ?>
                                <option value="<?php echo esc_attr( $t['id'] ); ?>"
                                        data-allows-photos="<?php echo empty( $t['allows_photos'] ) ? '0' : '1'; ?>"
                                        <?php selected( $gewaehlter_typ, (int) $t['id'] ); ?>>
                                    <?php echo esc_html( $t['name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php
                    // Tags: Jeder Typ bringt seine eigene Auswahl mit. Alle
                    // Gruppen stehen im Formular, das Skript unten zeigt die
                    // des gewählten Typs. Mitgeschickt wird nur die sichtbare
                    // (die anderen sind deaktiviert), und handle_save prüft
                    // ohnehin gegen den Typ.
                    $termin_tags = Tags::decode( $editing['tags'] ?? null );
                    ?>
                    <?php foreach ( $types as $typ ) : ?>
                        <?php $typ_tags = EventTypeModel::tags_of( $typ ); ?>
                        <?php if ( ! $typ_tags ) { continue; } ?>
                        <div class="vp-field vp-tagset" data-type="<?php echo esc_attr( $typ['id'] ); ?>"<?php echo (int) $typ['id'] === $gewaehlter_typ ? '' : ' style="display:none;"'; ?>>
                            <label><?php esc_html_e( 'Tags', 'vereins-plugin' ); ?></label>
                            <?php foreach ( $typ_tags as $tag ) : ?>
                                <label style="display:inline-block;margin-right:12px;font-weight:400;">
                                    <input type="checkbox" name="tags[]" value="<?php echo esc_attr( $tag ); ?>"
                                           <?php disabled( (int) $typ['id'] !== $gewaehlter_typ ); ?>
                                           <?php checked( (int) $typ['id'] === $gewaehlter_typ && in_array( $tag, $termin_tags, true ) ); ?>>
                                    <?php echo esc_html( $tag ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="vp-field">
                        <label for="vp-title"><?php esc_html_e( 'Titel', 'vereins-plugin' ); ?></label>
                        <input type="text" <?php echo AdminForms::NO_AUTOFILL; ?> id="vp-title" name="title" required value="<?php echo esc_attr( $editing['title'] ?? '' ); ?>">
                    </div>

                    <div class="vp-field">
                        <label for="vp-location"><?php esc_html_e( 'Ort', 'vereins-plugin' ); ?></label>
                        <input type="text" <?php echo AdminForms::NO_AUTOFILL; ?> id="vp-location" name="location" value="<?php echo esc_attr( $editing['location'] ?? '' ); ?>">
                    </div>

                    <div class="vp-field">
                        <label for="vp-description"><?php esc_html_e( 'Beschreibung', 'vereins-plugin' ); ?> <?php \VereinsPlugin\Frontend\EventForm::format_hint(); ?></label>
                        <textarea id="vp-description" name="description" rows="3"><?php echo esc_textarea( $editing['description'] ?? '' ); ?></textarea>

                    </div>

                    <div class="vp-row">
                        <div class="vp-field">
                            <label for="vp-start"><?php esc_html_e( 'Start', 'vereins-plugin' ); ?></label>
                            <input type="datetime-local" id="vp-start" name="start_at" required value="<?php echo esc_attr( $this->to_local( $editing['start_at'] ?? '' ) ); ?>">
                        </div>
                        <div class="vp-field">
                            <label for="vp-end"><?php esc_html_e( 'Ende', 'vereins-plugin' ); ?></label>
                            <input type="datetime-local" id="vp-end" name="end_at" required value="<?php echo esc_attr( $this->to_local( $editing['end_at'] ?? '' ) ); ?>">
                        </div>
                    </div>

                    <div class="vp-field">
                        <label>
                            <input type="checkbox" id="vp-fullday" name="is_full_day" value="1" <?php checked( ! empty( $editing['is_full_day'] ) ); ?>>
                            <?php esc_html_e( 'Ganztägig', 'vereins-plugin' ); ?>
                        </label>
                    </div>

                    <div class="vp-field">
                        <label>
                            <input type="checkbox" id="vp-recurring" name="is_recurring" value="1" <?php checked( ! empty( $editing['is_recurring'] ) ); ?>>
                            <?php esc_html_e( 'Wiederkehrender Termin', 'vereins-plugin' ); ?>
                        </label>
                    </div>

                    <div class="vp-field" id="vp-photo-field"<?php echo $fotos_erlaubt ? '' : ' style="display:none;"'; ?>>
                        <label for="vp-photo-days"><?php esc_html_e( 'Foto-Upload möglich bis (Tage nach dem Termin)', 'vereins-plugin' ); ?></label>
                        <input type="number" id="vp-photo-days" name="photo_upload_days" min="0" max="365"
                               value="<?php echo esc_attr( $editing['photo_upload_days'] ?? '' ); ?>"
                               placeholder="<?php echo esc_attr( sprintf( __( 'Standard: %d', 'vereins-plugin' ), (int) Settings::get( 'photo_upload_days', 14 ) ) ); ?>">
                        <p class="description"><?php esc_html_e( 'Leer lassen für den globalen Standard, 0 für kein Zeitlimit.', 'vereins-plugin' ); ?></p>
                    </div>

                    <?php
                    // Entwurf oder live. Angeboten nur, wo es um genau diese
                    // beiden Zustände geht: beim Anlegen und bei Terminen, die
                    // schon Entwurf oder live sind. Vorgeschlagene und
                    // abgelehnte Termine laufen weiter über Genehmigen/Ablehnen.
                    $status_jetzt   = $editing['approval_status'] ?? 'approved';
                    $status_schalter = in_array( $status_jetzt, [ 'approved', 'draft' ], true );
                    ?>
                    <?php if ( $status_schalter ) : ?>
                    <div class="vp-field" style="background:#f9fafb;border-radius:8px;padding:14px;">
                        <input type="hidden" name="status_toggle" value="1">
                        <label>
                            <input type="checkbox" name="is_live" value="1" <?php checked( $status_jetzt === 'approved' ); ?>>
                            <?php esc_html_e( 'Live – auf der Website sichtbar', 'vereins-plugin' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Ohne Häkchen bleibt der Termin ein Entwurf: nur hier im Backend zu sehen, nicht in der Terminliste, nicht als Banner, nicht über einen geteilten Link. Umschalten geht jederzeit, auch direkt in der Übersicht.', 'vereins-plugin' ); ?>
                        </p>
                    </div>
                    <?php endif; ?>

                    <div class="vp-field" style="background:#f9fafb;border-radius:8px;padding:14px;">
                        <label>
                            <input type="checkbox" name="is_public" value="1" <?php checked( ! empty( $editing['is_public'] ) ); ?>>
                            <?php esc_html_e( 'Öffentlich – auch ohne Anmeldung in der Terminliste sichtbar', 'vereins-plugin' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Besucher sehen den Termin mit Datum, Ort, Beschreibung und Tags, aber keine Namen, Helferaufgaben oder Fotos. Ein Klick auf „Zusagen“ führt sie zum Terminlink, über den sie ohne Konto zu- oder absagen.', 'vereins-plugin' ); ?>
                        </p>
                    </div>

                    <?php
                    // Banner auf der Website: NULL = aus. Beim Einschalten ist
                    // ein Vorlauf von zwei Wochen vorgeschlagen – lange genug,
                    // um sich den Tag freizuhalten, kurz genug, dass der
                    // Banner nicht monatelang dasselbe zeigt.
                    $banner_an   = isset( $editing['banner_days_before'] ) && $editing['banner_days_before'] !== null;
                    $banner_tage = $banner_an ? (int) $editing['banner_days_before'] : self::BANNER_DEFAULT_DAYS;
                    ?>
                    <div class="vp-field" style="background:#f9fafb;border-radius:8px;padding:14px;">
                        <label>
                            <input type="checkbox" id="vp-banner" name="banner_enabled" value="1" <?php checked( $banner_an ); ?>>
                            <?php esc_html_e( 'Als Banner auf der Website zeigen', 'vereins-plugin' ); ?>
                        </label>
                        <div id="vp-banner-days"<?php echo $banner_an ? '' : ' style="display:none;"'; ?>>
                            <label for="vp-banner-before" style="margin-top:8px;">
                                <?php esc_html_e( 'Sichtbar ab … Tagen vor Beginn', 'vereins-plugin' ); ?>
                            </label>
                            <input type="number" id="vp-banner-before" name="banner_days_before" min="0" max="365"
                                   value="<?php echo esc_attr( $banner_tage ); ?>">
                        </div>
                        <p class="description">
                            <?php esc_html_e( 'Erscheint überall, wo der Shortcode [vp_banner] steht – etwa auf der Startseite –, und verschwindet nach Terminende von selbst. Auch Besucher ohne Anmeldung sehen ihn und kommen per Klick zur Zusage. 0 = sofort sichtbar. Bei einer Serie zeigt der Banner immer den nächsten Termin.', 'vereins-plugin' ); ?>
                        </p>
                    </div>

                    <?php if ( ! $editing ) : ?>
                        <?php
                        // Vorlagen greifen nur beim Anlegen – bei bestehenden
                        // Terminen werden Posten direkt im Frontend gepflegt.
                        $bring_templates = $this->templates->all( 'bring' );
                        $help_templates  = $this->templates->all( 'help' );
                        ?>
                        <?php if ( $bring_templates || $help_templates ) : ?>
                        <div class="vp-field" style="background:#f9fafb;border-radius:8px;padding:14px;">
                            <strong style="display:block;margin-bottom:8px;"><?php esc_html_e( 'Vorlagen', 'vereins-plugin' ); ?></strong>

                            <?php if ( $bring_templates ) : ?>
                                <label for="vp-tpl-bring"><?php esc_html_e( 'Mitbringliste', 'vereins-plugin' ); ?></label>
                                <select id="vp-tpl-bring" name="item_template_id">
                                    <option value=""><?php esc_html_e( 'Standard des Termintyps', 'vereins-plugin' ); ?></option>
                                    <option value="0"><?php esc_html_e( '– keine –', 'vereins-plugin' ); ?></option>
                                    <?php foreach ( $bring_templates as $tpl ) : ?>
                                        <option value="<?php echo esc_attr( $tpl['id'] ); ?>"><?php echo esc_html( $tpl['name'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>

                            <?php if ( $help_templates ) : ?>
                                <label for="vp-tpl-help" style="margin-top:10px;"><?php esc_html_e( 'Helferaufgaben', 'vereins-plugin' ); ?></label>
                                <select id="vp-tpl-help" name="help_template_id">
                                    <option value=""><?php esc_html_e( 'Standard des Termintyps', 'vereins-plugin' ); ?></option>
                                    <option value="0"><?php esc_html_e( '– keine –', 'vereins-plugin' ); ?></option>
                                    <?php foreach ( $help_templates as $tpl ) : ?>
                                        <option value="<?php echo esc_attr( $tpl['id'] ); ?>"><?php echo esc_html( $tpl['name'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>

                            <p class="description">
                                <?php esc_html_e( 'Vorlagen werden nur angewendet, wenn der Termintyp Mitbringliste bzw. Helfer erlaubt.', 'vereins-plugin' ); ?>
                            </p>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php $rec = $editing['recurrence'] ?? null; ?>
                    <div id="vp-recurrence-panel" style="<?php echo $rec ? '' : 'display:none'; ?>; background:#f3f4f6;border-radius:8px;padding:16px;margin-bottom:14px;">
                        <div class="vp-row">
                            <div class="vp-field">
                                <label for="vp-freq"><?php esc_html_e( 'Häufigkeit', 'vereins-plugin' ); ?></label>
                                <select id="vp-freq" name="recurrence_frequency">
                                    <?php foreach ( [ 'DAILY' => 'Täglich', 'WEEKLY' => 'Wöchentlich', 'MONTHLY' => 'Monatlich', 'YEARLY' => 'Jährlich' ] as $val => $label ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $rec['frequency'] ?? 'WEEKLY', $val ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="vp-field">
                                <label for="vp-interval"><?php esc_html_e( 'Alle … Einheiten', 'vereins-plugin' ); ?></label>
                                <input type="number" id="vp-interval" name="recurrence_interval" min="1" max="52" value="<?php echo esc_attr( $rec['interval_n'] ?? 1 ); ?>">
                            </div>
                        </div>

                        <div class="vp-field">
                            <label><?php esc_html_e( 'Wochentage (nur bei „Wöchentlich“)', 'vereins-plugin' ); ?></label>
                            <?php
                            $selected_days = $rec['weekdays'] ?? [];
                            foreach ( [ 'MO' => 'Mo', 'TU' => 'Di', 'WE' => 'Mi', 'TH' => 'Do', 'FR' => 'Fr', 'SA' => 'Sa', 'SU' => 'So' ] as $val => $label ) :
                            ?>
                                <label style="margin-right:10px;font-weight:400;">
                                    <input type="checkbox" name="recurrence_weekdays[]" value="<?php echo esc_attr( $val ); ?>" <?php checked( in_array( $val, (array) $selected_days, true ) ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="vp-row">
                            <div class="vp-field">
                                <label for="vp-until"><?php esc_html_e( 'Ende der Serie (Datum)', 'vereins-plugin' ); ?></label>
                                <input type="date" id="vp-until" name="recurrence_until" value="<?php echo esc_attr( $rec['until'] ?? '' ); ?>">
                            </div>
                            <div class="vp-field">
                                <label for="vp-count"><?php esc_html_e( 'oder max. Anzahl', 'vereins-plugin' ); ?></label>
                                <input type="number" id="vp-count" name="recurrence_count" min="1" value="<?php echo esc_attr( $rec['count'] ?? '' ); ?>" placeholder="∞">
                            </div>
                        </div>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php echo $editing ? esc_html__( 'Speichern', 'vereins-plugin' ) : esc_html__( 'Anlegen', 'vereins-plugin' ); ?></button>
                        <?php if ( $editing ) : ?>
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=vp-events' ) ); ?>"><?php esc_html_e( 'Abbrechen', 'vereins-plugin' ); ?></a>
                        <?php else : ?>
                            <button type="button" class="button" data-vp-modal-close><?php esc_html_e( 'Abbrechen', 'vereins-plugin' ); ?></button>
                        <?php endif; ?>
                    </p>
                </form>
                <script>
                (function(){
                    var cb = document.getElementById('vp-recurring');
                    var panel = document.getElementById('vp-recurrence-panel');
                    if ( cb && panel ) {
                        cb.addEventListener('change', function(){ panel.style.display = cb.checked ? 'block' : 'none'; });
                    }

                    // Die Frist für den Foto-Upload nur zeigen, wenn der
                    // gewählte Termintyp überhaupt Fotos erlaubt. Das Feld
                    // bleibt im Formular stehen und wird weiter mitgeschickt:
                    // Wer versehentlich den Typ wechselt und zurückwechselt,
                    // soll seine Eingabe wiederfinden.
                    var banner = document.getElementById('vp-banner');
                    var bannerTage = document.getElementById('vp-banner-days');
                    if ( banner && bannerTage ) {
                        banner.addEventListener('change', function(){ bannerTage.style.display = banner.checked ? '' : 'none'; });
                    }

                    var typ  = document.getElementById('vp-type');
                    var foto = document.getElementById('vp-photo-field');

                    // Ganztägig: Start und Ende nur als Datum, ohne Uhrzeit.
                    // Die Uhrzeit wird gemerkt, falls das Häkchen wieder
                    // rausgeht. Der Server setzt 00:00 bis 23:59.
                    var ganz = document.getElementById('vp-fullday');
                    var nurTage = function(){
                        ['vp-start', 'vp-end'].forEach(function(id, i){
                            var f = document.getElementById(id);
                            if (!f) { return; }
                            if (ganz.checked && f.type !== 'date') {
                                f.dataset.zeit = f.value.slice(11, 16);
                                var tag = f.value.slice(0, 10);
                                f.type = 'date';
                                f.value = tag;
                            } else if (!ganz.checked && f.type === 'date') {
                                var tag2 = f.value;
                                f.type = 'datetime-local';
                                f.value = tag2 ? tag2 + 'T' + (f.dataset.zeit || (i ? '17:00' : '09:00')) : '';
                            }
                        });
                    };
                    if ( ganz ) {
                        ganz.addEventListener('change', nurTage);
                        nurTage();
                    }

                    // Tag-Auswahl des gewählten Typs zeigen, die übrigen
                    // ausblenden und deaktivieren, damit sie nicht mitgehen.
                    if ( typ ) {
                        typ.addEventListener('change', function(){
                            document.querySelectorAll('.vp-tagset').forEach(function(set){
                                var an = set.dataset.type === typ.value;
                                set.style.display = an ? '' : 'none';
                                set.querySelectorAll('input').forEach(function(i){ i.disabled = !an; });
                            });
                        });
                    }
                    if ( typ && foto ) {
                        typ.addEventListener('change', function(){
                            var opt = typ.options[ typ.selectedIndex ];
                            foto.style.display = ( opt && opt.dataset.allowsPhotos === '1' ) ? '' : 'none';
                        });
                    }
                })();
                </script>
                <?php endif; ?>
            </div>
            <?php AdminModal::close(); ?>
        </div>
        <?php
    }

    public function handle_save(): void {
        check_admin_referer( 'vp_save_event' );
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $data = [
            'event_type_id' => (int) ( $_POST['event_type_id'] ?? 0 ),
            'title'         => sanitize_text_field( $_POST['title'] ?? '' ),
            'description'   => sanitize_textarea_field( $_POST['description'] ?? '' ),
            'location'      => sanitize_text_field( $_POST['location'] ?? '' ),
            'start_at'      => $this->from_local( $_POST['start_at'] ?? '' ),
            'end_at'        => $this->from_local( $_POST['end_at'] ?? '' ),
            'is_full_day'   => ! empty( $_POST['is_full_day'] ) ? 1 : 0,
            'is_public'     => ! empty( $_POST['is_public'] ) ? 1 : 0,
            'created_by'    => get_current_user_id(),
            // Leeres Feld = globaler Standard (NULL), "0" = kein Zeitlimit.
            'photo_upload_days' => ( $_POST['photo_upload_days'] ?? '' ) === ''
                ? null
                : max( 0, min( 365, (int) $_POST['photo_upload_days'] ) ),
            // Ohne Häkchen kein Banner (NULL). Mit Häkchen und leerem Feld
            // gilt der Vorschlag, damit das Einschalten allein schon wirkt.
            'banner_days_before' => empty( $_POST['banner_enabled'] )
                ? null
                : ( ( $_POST['banner_days_before'] ?? '' ) === ''
                    ? self::BANNER_DEFAULT_DAYS
                    : max( 0, min( 365, (int) $_POST['banner_days_before'] ) ) ),
        ];

        // Ganztägig: nur die Tage zählen – 00:00 bis 23:59. Das Formular
        // schickt dann reine Datumsfelder.
        if ( $data['is_full_day'] ) {
            [ $data['start_at'], $data['end_at'] ] = DateRange::full_day_bounds(
                sanitize_text_field( $_POST['start_at'] ?? '' ),
                sanitize_text_field( $_POST['end_at'] ?? '' )
            );
        }

        // Nur Tags, die der gewählte Typ vorgibt.
        $data['tags'] = Tags::encode( Tags::restrict(
            array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['tags'] ?? [] ) ) ),
            EventTypeModel::tags_of( $this->type_model->find( $data['event_type_id'] ) )
        ) );

        // Den Status nur anfassen, wenn das Formular den Schalter gezeigt hat –
        // sonst würde das Speichern eines vorgeschlagenen Termins ihn
        // nebenbei freigeben.
        if ( ! empty( $_POST['status_toggle'] ) ) {
            $bisher = $id ? ( $this->model->find( $id )['approval_status'] ?? 'approved' ) : 'approved';
            if ( in_array( $bisher, [ 'approved', 'draft' ], true ) ) {
                $data['approval_status'] = ! empty( $_POST['is_live'] ) ? 'approved' : 'draft';
            }
        }

        if ( ! empty( $_POST['is_recurring'] ) ) {
            $weekdays = isset( $_POST['recurrence_weekdays'] ) ? array_map( 'sanitize_text_field', (array) $_POST['recurrence_weekdays'] ) : [];
            $data['recurrence'] = array_filter( [
                'frequency' => sanitize_text_field( $_POST['recurrence_frequency'] ?? 'WEEKLY' ),
                'interval'  => max( 1, (int) ( $_POST['recurrence_interval'] ?? 1 ) ),
                'weekdays'  => $weekdays ?: null,
                'until'     => ! empty( $_POST['recurrence_until'] ) ? sanitize_text_field( $_POST['recurrence_until'] ) : null,
                'count'     => ! empty( $_POST['recurrence_count'] ) ? (int) $_POST['recurrence_count'] : null,
            ], fn( $v ) => $v !== null );
        }

        if ( $id ) {
            $this->model->update( $id, $data );
        } else {
            $id = $this->model->create( $data );
            $this->apply_templates( $id, $data );
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'vp-events', 'vp_notice' => 'saved' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Überträgt Mitbring- und Helfervorlagen auf einen neu angelegten Termin.
     * Leeres Feld = Standard des Termintyps, "0" = ausdrücklich keine Vorlage.
     */
    private function apply_templates( int $event_id, array $data ): void {
        $type = $this->type_model->find( (int) ( $data['event_type_id'] ?? 0 ) );
        if ( ! $type ) {
            return;
        }

        $instance_date = substr( $data['start_at'] ?? '', 0, 10 );
        if ( ! $instance_date ) {
            return;
        }

        $user_id = get_current_user_id();

        $pairs = [
            [ 'allows_items',  'item_template_id', 'default_item_template_id' ],
            [ 'needs_helpers', 'help_template_id', 'default_help_template_id' ],
        ];

        foreach ( $pairs as [ $flag, $post_key, $default_key ] ) {
            if ( empty( $type[ $flag ] ) ) {
                continue;
            }

            $raw         = $_POST[ $post_key ] ?? '';
            $template_id = ( $raw === '' )
                ? (int) ( $type[ $default_key ] ?? 0 )
                : (int) $raw;

            if ( $template_id ) {
                $this->items->apply_template( $event_id, $instance_date, $template_id, $user_id );
            }
        }
    }

    public function handle_delete(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_delete_event_' . $id );
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $this->model->delete( $id );
        wp_safe_redirect( add_query_arg( [ 'page' => 'vp-events', 'vp_notice' => 'deleted' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_approve(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_event_review_' . $id );
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $this->model->approve( $id, get_current_user_id() );
        wp_safe_redirect( add_query_arg( [ 'page' => 'vp-events', 'vp_notice' => 'approved' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_reject(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_event_review_' . $id );
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $this->model->reject( $id, get_current_user_id() );
        wp_safe_redirect( add_query_arg( [ 'page' => 'vp-events', 'vp_notice' => 'rejected' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_live(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_event_live_' . $id );
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $live = ! empty( $_POST['live'] );
        $this->model->set_live( $id, $live );

        // Zurück in dieselbe Ansicht, aus der umgeschaltet wurde.
        $args = [ 'page' => 'vp-events', 'vp_notice' => $live ? 'live' : 'draft' ];
        $filter = sanitize_text_field( $_POST['filter'] ?? '' );
        if ( $filter !== '' ) {
            $args['status'] = $filter;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function live_button( int $id, bool $live ): void {
        ?>
        <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'vp_event_live_' . $id ); ?>
            <input type="hidden" name="action" value="vp_event_live">
            <input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
            <input type="hidden" name="live" value="<?php echo $live ? '1' : '0'; ?>">
            <input type="hidden" name="filter" value="<?php echo esc_attr( sanitize_text_field( $_GET['status'] ?? '' ) ); ?>">
            <button type="submit" class="button button-small <?php echo $live ? 'button-primary' : ''; ?>">
                <?php echo $live ? esc_html__( 'Live schalten', 'vereins-plugin' ) : esc_html__( 'Auf Entwurf', 'vereins-plugin' ); ?>
            </button>
        </form>
        <?php
    }

    private function review_button( int $id, string $action, string $label, string $class = '' ): void {
        ?>
        <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'vp_event_review_' . $id ); ?>
            <input type="hidden" name="action" value="vp_event_<?php echo esc_attr( $action ); ?>">
            <input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
            <button type="submit" class="button button-small <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    private function user_display_name( int $user_id ): string {
        if ( ! $user_id ) {
            return '—';
        }
        $user = get_userdata( $user_id );
        return $user ? $user->display_name : __( '(gelöschter Nutzer)', 'vereins-plugin' );
    }

    private function status_label( string $status ): string {
        return match ( $status ) {
            'approved' => __( 'Live', 'vereins-plugin' ),
            'draft'    => __( 'Entwurf', 'vereins-plugin' ),
            'pending'  => __( 'Ausstehend', 'vereins-plugin' ),
            'rejected' => __( 'Abgelehnt', 'vereins-plugin' ),
            default    => $status,
        };
    }

    private function to_local( string $mysql_datetime ): string {
        if ( ! $mysql_datetime ) {
            return '';
        }
        return str_replace( ' ', 'T', substr( $mysql_datetime, 0, 16 ) );
    }

    private function from_local( string $local ): string {
        $local = sanitize_text_field( $local );
        return str_replace( 'T', ' ', $local ) . ':00';
    }

    private function render_notices(): void {
        $notice = sanitize_text_field( $_GET['vp_notice'] ?? '' );
        $map = [
            'saved'    => [ 'success', __( 'Termin gespeichert.', 'vereins-plugin' ) ],
            'deleted'  => [ 'success', __( 'Termin gelöscht.', 'vereins-plugin' ) ],
            'approved' => [ 'success', __( 'Termin genehmigt und jetzt sichtbar.', 'vereins-plugin' ) ],
            'rejected' => [ 'success', __( 'Termin abgelehnt.', 'vereins-plugin' ) ],
            'live'     => [ 'success', __( 'Termin ist live und auf der Website sichtbar.', 'vereins-plugin' ) ],
            'draft'    => [ 'success', __( 'Termin ist jetzt ein Entwurf und auf der Website nicht mehr sichtbar.', 'vereins-plugin' ) ],
        ];
        if ( ! isset( $map[ $notice ] ) ) {
            return;
        }
        [ $type, $text ] = $map[ $notice ];
        printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
    }
}
