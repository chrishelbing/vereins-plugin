<?php

namespace VereinsPlugin\Admin;

use VereinsPlugin\Core\Activator;
use VereinsPlugin\Members\MemberModel;
use VereinsPlugin\Members\PeopleModel;
use VereinsPlugin\Members\Person;
use VereinsPlugin\Members\MemberService;
use VereinsPlugin\Support\Capabilities;

/**
 * wp-admin-Seite "Mitglieder": Liste (mit Status-Filter), Freischalten/
 * Deaktivieren, Rolle ändern, manuell anlegen.
 */
class MembersPage {

    private MemberModel   $model;
    private MemberService $service;

    public function __construct() {
        $this->model   = new MemberModel();
        $this->service = new MemberService();
    }

    public function register(): void {
        add_action( 'admin_post_vp_member_set_status', [ $this, 'handle_set_status' ] );
        add_action( 'admin_post_vp_member_set_roles',   [ $this, 'handle_set_roles' ] );
        add_action( 'admin_post_vp_member_create',      [ $this, 'handle_create' ] );
        add_action( 'admin_post_vp_person_decide',      [ $this, 'handle_person_decide' ] );
        add_action( 'admin_post_vp_member_add_existing',[ $this, 'handle_add_existing' ] );
        add_action( 'admin_post_vp_member_erase',       [ $this, 'handle_erase' ] );
        add_action( 'admin_post_vp_member_update',      [ $this, 'handle_update' ] );
    }

    public function render(): void {
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $filter = sanitize_text_field( $_GET['status'] ?? '' );
        $filter = in_array( $filter, [ 'pending', 'active', 'inactive' ], true ) ? $filter : '';
        $members = $this->model->all( $filter ?: null );

        $edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
        $editing = null;
        if ( $edit_id ) {
            foreach ( $this->model->all() as $m ) {
                if ( (int) $m['id'] === $edit_id ) {
                    $editing = $m;
                }
            }
        }

        $counts = [
            'pending'  => $this->model->count_by_status( 'pending' ),
            'active'   => $this->model->count_by_status( 'active' ),
            'inactive' => $this->model->count_by_status( 'inactive' ),
        ];

        AdminStyles::print_inline();
        ?>
        <div class="wrap vp-admin-wrap">
            <h1><?php esc_html_e( 'Mitglieder', 'vereins-plugin' ); ?></h1>
            <?php $this->render_notices(); ?>

            <?php $this->render_open_people(); ?>

            <div class="vp-head">
                <div class="vp-head__actions">
                    <?php AdminModal::trigger( 'vp-member-form', __( 'Mitglied anlegen', 'vereins-plugin' ) ); ?>
                    <?php if ( $this->has_open_users() ) : ?>
                        <?php AdminModal::trigger( 'vp-member-existing', __( 'Vorhandenen Nutzer aufnehmen', 'vereins-plugin' ), '' ); ?>
                    <?php endif; ?>
                </div>

                <div class="vp-head__meta">
                    <ul class="subsubsub vp-tabs">
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=vp-members' ) ); ?>" <?php echo $filter === '' ? 'class="current"' : ''; ?>><?php esc_html_e( 'Alle', 'vereins-plugin' ); ?></a> |</li>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=vp-members&status=pending' ) ); ?>" <?php echo $filter === 'pending' ? 'class="current"' : ''; ?>><?php printf( esc_html__( 'Wartend (%d)', 'vereins-plugin' ), (int) $counts['pending'] ); ?></a> |</li>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=vp-members&status=active' ) ); ?>" <?php echo $filter === 'active' ? 'class="current"' : ''; ?>><?php printf( esc_html__( 'Aktiv (%d)', 'vereins-plugin' ), (int) $counts['active'] ); ?></a> |</li>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=vp-members&status=inactive' ) ); ?>" <?php echo $filter === 'inactive' ? 'class="current"' : ''; ?>><?php printf( esc_html__( 'Inaktiv (%d)', 'vereins-plugin' ), (int) $counts['inactive'] ); ?></a></li>
                    </ul>
                </div>
            </div>

            <?php
            // Nach Vereinsrolle gruppieren. Wer keine trägt, steht getrennt –
            // das ist meist ein Versehen und soll auffallen.
            $mit  = [];
            $ohne = [];
            foreach ( $members as $m ) {
                if ( array_intersect( (array) $m['roles'], Capabilities::club_roles() ) ) {
                    $mit[] = $m;
                } else {
                    $ohne[] = $m;
                }
            }
            ?>

            <?php $this->render_member_table( $mit, __( 'Mitglieder', 'vereins-plugin' ), '' ); ?>

            <?php if ( $ohne ) : ?>
                <?php $this->render_member_table(
                    $ohne,
                    __( 'Ohne Vereinsrolle', 'vereins-plugin' ),
                    __( 'Diese Personen haben einen Mitgliedseintrag, tragen aber keine Rolle des Vereins. Der Zugang zum Mitgliederbereich hängt am Status, nicht an der Rolle – sie können die Website also weiterhin nutzen. Die Rolle brauchen sie für Rechte im WordPress und für die Erkennung als Orgateam.', 'vereins-plugin' )
                ); ?>
            <?php endif; ?>

            <?php AdminModal::open( 'vp-member-form', __( 'Mitglied manuell anlegen', 'vereins-plugin' ) ); ?>
            <div class="vp-form-card">
                <p class="description"><?php esc_html_e( 'Wird sofort aktiv – kein Freischaltungsschritt nötig.', 'vereins-plugin' ); ?></p>
                <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'vp_member_create' ); ?>
                    <input type="hidden" name="action" value="vp_member_create">

                    <div class="vp-row">
                        <div class="vp-field">
                            <label for="vp-m-name"><?php esc_html_e( 'Name', 'vereins-plugin' ); ?></label>
                            <input type="text" <?php echo AdminForms::NO_AUTOFILL; ?> id="vp-m-name" name="display_name" required>
                        </div>
                        <div class="vp-field">
                            <label for="vp-m-email"><?php esc_html_e( 'E-Mail', 'vereins-plugin' ); ?></label>
                            <input type="email" <?php echo AdminForms::NO_AUTOFILL; ?> id="vp-m-email" name="email" required>
                        </div>
                    </div>
                    <div class="vp-row">
                        <div class="vp-field">
                            <label for="vp-m-phone"><?php esc_html_e( 'Telefon (optional)', 'vereins-plugin' ); ?></label>
                            <input type="tel" <?php echo AdminForms::NO_AUTOFILL; ?> id="vp-m-phone" name="phone">
                        </div>
                        <div class="vp-field">
                            <label for="vp-m-role"><?php esc_html_e( 'Rolle', 'vereins-plugin' ); ?></label>
                            <select id="vp-m-role" name="role">
                                <option value="vp_member"><?php esc_html_e( 'Mitglied', 'vereins-plugin' ); ?></option>
                                <option value="<?php echo esc_attr( Activator::ROLE_ORGA ); ?>"><?php esc_html_e( 'Orgateam', 'vereins-plugin' ); ?></option>
                            </select>
                        </div>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Anlegen', 'vereins-plugin' ); ?></button>
                        <button type="button" class="button" data-vp-modal-close><?php esc_html_e( 'Abbrechen', 'vereins-plugin' ); ?></button>
                    </p>
                </form>
            </div>
            <?php AdminModal::close(); ?>

            <?php $this->render_existing_user_form(); ?>
            <?php if ( $editing ) : ?>
                <?php $this->render_edit_form( $editing ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Offene Personen: Zusagen über Terminlinks und Selbstregistrierungen,
     * die noch kein WordPress-Profil haben.
     *
     * Bewusst über der Mitgliedertabelle: Das ist der einzige Ort, an dem
     * etwas zu entscheiden ist – der Rest der Seite ist Verwaltung.
     */
    private function render_open_people(): void {
        $people = ( new PeopleModel() )->all();

        if ( ! $people ) {
            return;
        }

        $quelle = [
            'link'         => __( 'über Terminlink', 'vereins-plugin' ),
            'registration' => __( 'Selbstregistrierung', 'vereins-plugin' ),
        ];
        ?>
        <div class="vp-form-card vp-form-card--wide">
            <h2>
                <?php printf( esc_html__( 'Offene Personen (%d)', 'vereins-plugin' ), count( $people ) ); ?>
            </h2>
            <p class="description">
                <?php esc_html_e( 'Diese Personen haben noch kein WordPress-Profil. Erst mit der Aufnahme entsteht ein Zugang; bisherige Zusagen und übernommene Aufgaben bleiben dabei erhalten.', 'vereins-plugin' ); ?>
            </p>

            <table class="vp-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Kontakt', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Herkunft', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Seit', 'vereins-plugin' ); ?></th>
                        <th><?php esc_html_e( 'Aktionen', 'vereins-plugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $people as $person ) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $person['display_name'] ); ?></strong>
                            <?php if ( empty( $person['confirmed_at'] ) ) : ?>
                                <span class="vp-badge vp-badge--pending"><?php esc_html_e( 'unbestätigt', 'vereins-plugin' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $person['email'] ) : ?>
                                <?php echo esc_html( $person['email'] ); ?>
                            <?php endif; ?>
                            <?php if ( $person['phone'] ) : ?>
                                <br><span class="description"><?php echo esc_html( $person['phone'] ); ?></span>
                            <?php endif; ?>
                            <?php if ( ! $person['email'] && ! $person['phone'] ) : ?>
                                <span class="description"><?php esc_html_e( 'keine Angabe', 'vereins-plugin' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $quelle[ $person['source'] ] ?? $person['source'] ); ?></td>
                        <td><?php echo esc_html( mysql2date( 'd.m.Y', $person['created_at'] ) ); ?></td>
                        <td class="vp-actions">
                            <?php $this->person_button( (int) $person['id'], 'accept', __( 'Aufnehmen', 'vereins-plugin' ), 'button-primary' ); ?>
                            <?php $this->person_button( (int) $person['id'], 'reject', __( 'Ablehnen', 'vereins-plugin' ), 'button-link-delete' ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Eine Mitgliedertabelle mit Überschrift.
     */
    private function render_member_table( array $members, string $titel, string $hinweis ): void {
        ?>
        <h2 style="margin-top:26px;"><?php echo esc_html( $titel ); ?> <span class="description">(<?php echo count( $members ); ?>)</span></h2>
        <?php if ( $hinweis ) : ?>
            <p class="description" style="max-width:70em;"><?php echo esc_html( $hinweis ); ?></p>
        <?php endif; ?>

        <table class="vp-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'E-Mail', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Telefon', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Rollen', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'vereins-plugin' ); ?></th>
                    <th><?php esc_html_e( 'Aktionen', 'vereins-plugin' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( ! $members ) : ?>
                <tr><td colspan="6"><?php esc_html_e( 'Keine Einträge in dieser Ansicht.', 'vereins-plugin' ); ?></td></tr>
            <?php endif; ?>
            <?php foreach ( $members as $m ) : ?>
                <tr<?php echo (int) ( $_GET['edit'] ?? 0 ) === (int) $m['id'] ? ' class="is-editing"' : ''; ?>>
                    <td><?php echo esc_html( $m['display_name'] ?: $m['user_login'] ); ?></td>
                    <td><?php echo esc_html( $m['user_email'] ); ?></td>
                    <td><?php echo esc_html( $m['phone'] ?: '—' ); ?></td>
                    <td><?php $this->render_roles_cell( $m ); ?></td>
                    <td><span class="vp-badge vp-badge--<?php echo esc_attr( $m['status'] ); ?>"><?php echo esc_html( $this->status_label( $m['status'] ) ); ?></span></td>
                    <td class="vp-actions">
                        <a class="button button-small" href="<?php echo esc_url( add_query_arg( [ 'page' => 'vp-members', 'edit' => $m['id'] ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'vereins-plugin' ); ?></a>
                        <?php if ( $m['status'] === 'pending' ) : ?>
                            <?php $this->status_button( $m['id'], 'active', __( 'Freischalten', 'vereins-plugin' ), 'button-primary' ); ?>
                            <?php $this->status_button( $m['id'], 'inactive', __( 'Ablehnen', 'vereins-plugin' ) ); ?>
                        <?php elseif ( $m['status'] === 'active' ) : ?>
                            <?php $this->status_button( $m['id'], 'inactive', __( 'Deaktivieren', 'vereins-plugin' ) ); ?>
                        <?php else : ?>
                            <?php $this->status_button( $m['id'], 'active', __( 'Aktivieren', 'vereins-plugin' ) ); ?>
                        <?php endif; ?>
                        <?php $this->erase_button( (int) $m['wp_user_id'] ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Dialog zum Korrigieren von Name, E-Mail und Telefon. Öffnet von selbst,
     * wenn die Seite mit ?edit=<Mitglieds-ID> geladen wird – wie bei den
     * übrigen Backend-Seiten (siehe AdminModal).
     */
    private function render_edit_form( array $m ): void {
        $email_frei = $this->service->role_is_changeable( (int) $m['wp_user_id'] );
        $name       = $m['display_name'] ?: $m['user_login'];

        AdminModal::open( 'vp-member-edit', __( 'Mitglied bearbeiten', 'vereins-plugin' ), true, $name );
        ?>
        <div class="vp-form-card">
            <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'vp_member_update_' . $m['id'] ); ?>
                <input type="hidden" name="action" value="vp_member_update">
                <input type="hidden" name="id" value="<?php echo esc_attr( $m['id'] ); ?>">

                <div class="vp-row">
                    <div class="vp-field">
                        <label for="vp-me-name"><?php esc_html_e( 'Name', 'vereins-plugin' ); ?></label>
                        <input type="text" <?php echo AdminForms::NO_AUTOFILL; ?> id="vp-me-name" name="display_name" value="<?php echo esc_attr( $name ); ?>" required>
                    </div>
                    <div class="vp-field">
                        <label for="vp-me-email"><?php esc_html_e( 'E-Mail', 'vereins-plugin' ); ?></label>
                        <input type="email" <?php echo AdminForms::NO_AUTOFILL; ?> id="vp-me-email" name="email" value="<?php echo esc_attr( $m['user_email'] ); ?>" <?php echo $email_frei ? 'required' : 'readonly'; ?>>
                    </div>
                </div>
                <div class="vp-row">
                    <div class="vp-field">
                        <label for="vp-me-phone"><?php esc_html_e( 'Telefon (optional)', 'vereins-plugin' ); ?></label>
                        <input type="tel" <?php echo AdminForms::NO_AUTOFILL; ?> id="vp-me-phone" name="phone" value="<?php echo esc_attr( $m['phone'] ?? '' ); ?>">
                    </div>
                </div>

                <p class="description">
                    <?php if ( $email_frei ) : ?>
                        <?php esc_html_e( 'Die E-Mail ist zugleich die Anmeldeadresse – nach einer Änderung meldet sich die Person mit der neuen Adresse an.', 'vereins-plugin' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Die E-Mail von Administratoren und des eigenen Kontos wird im WordPress-Profil geändert.', 'vereins-plugin' ); ?>
                    <?php endif; ?>
                </p>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Speichern', 'vereins-plugin' ); ?></button>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=vp-members' ) ); ?>"><?php esc_html_e( 'Abbrechen', 'vereins-plugin' ); ?></a>
                </p>
            </form>
        </div>
        <?php
        AdminModal::close();
    }

    public function handle_update(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_member_update_' . $id );
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $data = [
            'display_name' => sanitize_text_field( $_POST['display_name'] ?? '' ),
            'phone'        => sanitize_text_field( $_POST['phone'] ?? '' ),
        ];
        if ( isset( $_POST['email'] ) ) {
            $data['email'] = sanitize_email( $_POST['email'] );
        }

        $result = $this->service->update_profile( $id, $data );

        $args = [ 'page' => 'vp-members', 'vp_notice' => 'updated' ];
        if ( is_wp_error( $result ) ) {
            $args['vp_notice'] = 'update_' . $result->get_error_code();
            $args['edit']      = $id;
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Recht auf Löschung: Vereinsdaten (Zusagen, Aufgaben, Begleitpersonen,
     * Fotos) werden immer entfernt. Das WordPress-Konto fällt nur mit weg,
     * wenn es ausschließlich Vereinsrollen trägt und keine eigenen Beiträge
     * oder Kommentare hat – siehe MemberService::erase_member().
     */
    private function erase_button( int $wp_user_id ): void {
        if ( ! $this->service->role_is_changeable( $wp_user_id ) ) {
            return;
        }

        $frage = __( 'Mitglied vollständig löschen? Zusagen, Aufgaben, Begleitpersonen und Fotos werden entfernt. Das WordPress-Konto fällt nur weg, wenn es ausschließlich Vereinsrollen trägt und keine eigenen Beiträge hat.', 'vereins-plugin' );
        ?>
        <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              onsubmit="return confirm('<?php echo esc_js( $frage ); ?>');">
            <?php wp_nonce_field( 'vp_member_erase_' . $wp_user_id ); ?>
            <input type="hidden" name="action" value="vp_member_erase">
            <input type="hidden" name="wp_user_id" value="<?php echo esc_attr( $wp_user_id ); ?>">
            <button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Vollständig löschen', 'vereins-plugin' ); ?></button>
        </form>
        <?php
    }

    /**
     * Alle Rollen einer Person – anzeigen und ändern.
     *
     * Die Bearbeitung steckt in einem aufklappbaren Bereich: Ausgeklappte
     * Ankreuzfelder in jeder Zeile machten die Tabelle unlesbar.
     */
    private function render_roles_cell( array $m ): void {
        $user_id = (int) $m['wp_user_id'];
        $rollen  = (array) $m['roles'];
        $alle    = MemberService::editable_roles();

        // Vereinsrollen zuerst, danach die Rollen der Website.
        $verein  = array_intersect_key( $alle, array_flip( Capabilities::club_roles() ) );
        $website = array_diff_key( $alle, $verein );

        if ( ! $rollen ) {
            printf( '<span class="description">%s</span>', esc_html__( 'keine Rolle', 'vereins-plugin' ) );
        }
        foreach ( $rollen as $rolle ) {
            $eigene = in_array( $rolle, Capabilities::club_roles(), true );
            printf(
                '<span class="vp-badge vp-badge--%s" style="margin:0 4px 4px 0;display:inline-block;">%s</span>',
                $eigene ? 'active' : 'inactive',
                esc_html( $alle[ $rolle ]['name'] ?? wp_roles()->roles[ $rolle ]['name'] ?? $rolle )
            );
        }

        if ( ! $this->service->role_is_changeable( $user_id ) ) {
            printf(
                '<p class="description" style="margin:4px 0 0;">%s</p>',
                esc_html( $user_id === get_current_user_id()
                    ? __( 'Eigenes Konto – Rollen hier nicht änderbar.', 'vereins-plugin' )
                    : __( 'Administrator – Rollen nur über die WordPress-Benutzerverwaltung.', 'vereins-plugin' ) )
            );
            return;
        }
        ?>
        <details class="vp-roles">
            <summary><?php esc_html_e( 'Rollen ändern', 'vereins-plugin' ); ?></summary>
            <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'vp_member_set_roles_' . $m['id'] ); ?>
                <input type="hidden" name="action" value="vp_member_set_roles">
                <input type="hidden" name="id" value="<?php echo esc_attr( $m['id'] ); ?>">

                <?php foreach ( [ __( 'Verein', 'vereins-plugin' ) => $verein, __( 'Website', 'vereins-plugin' ) => $website ] as $gruppe => $liste ) : ?>
                    <?php if ( ! $liste ) { continue; } ?>
                    <strong class="vp-roles__group"><?php echo esc_html( $gruppe ); ?></strong>
                    <?php foreach ( $liste as $slug => $daten ) : ?>
                        <label class="vp-roles__item">
                            <input type="checkbox" name="roles[]" value="<?php echo esc_attr( $slug ); ?>"
                                   <?php checked( in_array( $slug, $rollen, true ) ); ?>>
                            <?php echo esc_html( translate_user_role( $daten['name'] ) ); ?>
                        </label>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <button type="submit" class="button button-small"><?php esc_html_e( 'Übernehmen', 'vereins-plugin' ); ?></button>
            </form>
        </details>
        <?php
    }

    /**
     * Vorhandene WordPress-Nutzer aufnehmen.
     *
     * Auf einer bestehenden Seite arbeiten oft schon Leute – Redakteure,
     * Autoren –, die auch im Verein sind. Für sie ein zweites Konto anzulegen
     * wäre falsch; sie bekommen den Mitgliedseintrag und die Vereinsrolle
     * zusätzlich zu ihrer bisherigen.
     */
    private function render_existing_user_form(): void {
        $mitglieder = array_map( fn( $m ) => (int) $m['wp_user_id'], $this->model->all() );

        $offen = get_users( [
            'exclude' => $mitglieder ?: [ 0 ],
            'orderby' => 'display_name',
            'number'  => 200,
        ] );

        if ( ! $offen ) {
            return;
        }
        ?>
        <?php AdminModal::open( 'vp-member-existing', __( 'Vorhandenen WordPress-Nutzer aufnehmen', 'vereins-plugin' ) ); ?>
        <div class="vp-form-card">
            <p class="description">
                <?php esc_html_e( 'Für Menschen, die auf dieser Seite schon einen Zugang haben. Die Vereinsrolle kommt zu ihrer bisherigen Rolle hinzu; bestehende Rechte bleiben unverändert.', 'vereins-plugin' ); ?>
            </p>

            <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'vp_member_add_existing' ); ?>
                <input type="hidden" name="action" value="vp_member_add_existing">

                <div class="vp-row">
                    <div class="vp-field">
                        <label for="vp-existing-user"><?php esc_html_e( 'Nutzer', 'vereins-plugin' ); ?></label>
                        <select id="vp-existing-user" name="wp_user_id" required>
                            <option value=""><?php esc_html_e( '– bitte wählen –', 'vereins-plugin' ); ?></option>
                            <?php foreach ( $offen as $user ) : ?>
                                <?php
                                $rollen = array_map(
                                    fn( $r ) => wp_roles()->roles[ $r ]['name'] ?? $r,
                                    (array) $user->roles
                                );
                                ?>
                                <option value="<?php echo esc_attr( $user->ID ); ?>">
                                    <?php echo esc_html( sprintf(
                                        '%s (%s)%s',
                                        $user->display_name,
                                        $user->user_email,
                                        $rollen ? ' – ' . implode( ', ', $rollen ) : ''
                                    ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="vp-field">
                        <label for="vp-existing-role"><?php esc_html_e( 'Vereinsrolle', 'vereins-plugin' ); ?></label>
                        <select id="vp-existing-role" name="role">
                            <option value="<?php echo esc_attr( Activator::ROLE_MEMBER ); ?>"><?php esc_html_e( 'Mitglied', 'vereins-plugin' ); ?></option>
                            <option value="<?php echo esc_attr( Activator::ROLE_ORGA ); ?>"><?php esc_html_e( 'Orgateam', 'vereins-plugin' ); ?></option>
                        </select>
                    </div>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Aufnehmen', 'vereins-plugin' ); ?></button>
                    <button type="button" class="button" data-vp-modal-close><?php esc_html_e( 'Abbrechen', 'vereins-plugin' ); ?></button>
                </p>
            </form>
        </div>
        <?php
        AdminModal::close();
    }

    /**
     * Gibt es überhaupt WordPress-Nutzer, die noch kein Mitglied sind?
     *
     * Der Knopf über der Tabelle darf sonst keinen leeren Dialog öffnen –
     * render_existing_user_form() steigt in diesem Fall vorher aus.
     */
    private function has_open_users(): bool {
        $mitglieder = array_map( fn( $m ) => (int) $m['wp_user_id'], $this->model->all() );

        return (bool) get_users( [
            'exclude' => $mitglieder ?: [ 0 ],
            'number'  => 1,
            'fields'  => 'ID',
        ] );
    }

    public function handle_add_existing(): void {
        check_admin_referer( 'vp_member_add_existing' );

        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $result = ( new MemberService() )->add_existing_user(
            (int) ( $_POST['wp_user_id'] ?? 0 ),
            sanitize_text_field( $_POST['role'] ?? '' )
        );

        wp_safe_redirect( add_query_arg( [
            'page'      => 'vp-members',
            'vp_notice' => is_wp_error( $result ) ? 'existing_error' : 'existing_added',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private function person_button( int $id, string $decision, string $label, string $class = '' ): void {
        $frage = $decision === 'reject'
            ? __( 'Eintrag samt Zusagen wirklich löschen?', 'vereins-plugin' )
            : '';
        ?>
        <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              <?php echo $frage ? 'onsubmit="return confirm(\'' . esc_js( $frage ) . '\');"' : ''; ?>>
            <?php wp_nonce_field( 'vp_person_decide_' . $id ); ?>
            <input type="hidden" name="action" value="vp_person_decide">
            <input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
            <input type="hidden" name="decision" value="<?php echo esc_attr( $decision ); ?>">
            <button type="submit" class="button button-small <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    public function handle_person_decide(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_person_decide_' . $id );

        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $person_id = Person::from_guest( $id );
        $service   = new MemberService();

        if ( sanitize_text_field( $_POST['decision'] ?? '' ) === 'accept' ) {
            $result = $service->accept_person( $person_id );
            $notice = is_wp_error( $result ) ? 'person_failed' : 'person_accepted';
        } else {
            $notice = $service->reject_person( $person_id ) ? 'person_rejected' : 'person_failed';
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'vp-members', 'vp_notice' => $notice ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private function status_button( int $id, string $new_status, string $label, string $class = '' ): void {
        ?>
        <form method="post" <?php echo AdminForms::NO_AUTOFILL_FORM; ?> action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'vp_member_set_status_' . $id ); ?>
            <input type="hidden" name="action" value="vp_member_set_status">
            <input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
            <input type="hidden" name="status" value="<?php echo esc_attr( $new_status ); ?>">
            <button type="submit" class="button button-small <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    public function handle_set_status(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_member_set_status_' . $id );
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $status = sanitize_text_field( $_POST['status'] ?? '' );
        if ( $status === 'active' ) {
            $this->service->approve( $id );
        } else {
            $this->service->set_status( $id, $status );
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'vp-members', 'vp_notice' => 'status_updated' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_set_roles(): void {
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'vp_member_set_roles_' . $id );

        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $member = $this->model->find( $id );
        $notice = 'role_error';

        if ( $member ) {
            $roles  = array_map( 'sanitize_key', (array) ( $_POST['roles'] ?? [] ) );
            $result = $this->service->set_roles( (int) $member['wp_user_id'], $roles );
            $notice = is_wp_error( $result ) ? 'role_error' : 'role_updated';
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'vp-members', 'vp_notice' => $notice ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_create(): void {
        check_admin_referer( 'vp_member_create' );
        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $result = $this->service->create_by_admin( [
            'display_name' => sanitize_text_field( $_POST['display_name'] ?? '' ),
            'email'        => sanitize_email( $_POST['email'] ?? '' ),
            'phone'        => sanitize_text_field( $_POST['phone'] ?? '' ),
            'role'         => sanitize_text_field( $_POST['role'] ?? Activator::ROLE_MEMBER ),
        ] );

        $notice = is_wp_error( $result ) ? 'create_error' : 'created';
        wp_safe_redirect( add_query_arg( [ 'page' => 'vp-members', 'vp_notice' => $notice ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_erase(): void {
        $wp_user_id = (int) ( $_POST['wp_user_id'] ?? 0 );
        check_admin_referer( 'vp_member_erase_' . $wp_user_id );

        if ( ! Capabilities::is_admin_user() ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'vereins-plugin' ) );
        }

        $result = $this->service->erase_member( $wp_user_id );

        $notice = 'erase_error';
        if ( ! is_wp_error( $result ) ) {
            $notice = $result['account_deleted'] ? 'erased' : 'erased_data_only';
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'vp-members', 'vp_notice' => $notice ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private function status_label( string $status ): string {
        return match ( $status ) {
            'active'   => __( 'Aktiv', 'vereins-plugin' ),
            'pending'  => __( 'Wartend', 'vereins-plugin' ),
            'inactive' => __( 'Inaktiv', 'vereins-plugin' ),
            default    => $status,
        };
    }

    private function render_notices(): void {
        $notice = sanitize_text_field( $_GET['vp_notice'] ?? '' );
        $map = [
            'status_updated' => [ 'success', __( 'Status aktualisiert.', 'vereins-plugin' ) ],
            'role_updated'   => [ 'success', __( 'Rollen aktualisiert.', 'vereins-plugin' ) ],
            'role_error'     => [ 'error',   __( 'Rollen konnten nicht geändert werden.', 'vereins-plugin' ) ],
            'created'        => [ 'success', __( 'Mitglied angelegt.', 'vereins-plugin' ) ],
            'updated'                => [ 'success', __( 'Mitglied gespeichert.', 'vereins-plugin' ) ],
            'update_empty_name'      => [ 'error',   __( 'Der Name darf nicht leer sein.', 'vereins-plugin' ) ],
            'update_invalid_email'   => [ 'error',   __( 'Bitte gib eine gültige E-Mail-Adresse an.', 'vereins-plugin' ) ],
            'update_email_exists'    => [ 'error',   __( 'Diese E-Mail-Adresse gehört bereits zu einem anderen Zugang.', 'vereins-plugin' ) ],
            'update_protected'       => [ 'error',   __( 'Die E-Mail von Administratoren und des eigenen Kontos wird im WordPress-Profil geändert.', 'vereins-plugin' ) ],
            'update_not_found'       => [ 'error',   __( 'Mitglied nicht gefunden.', 'vereins-plugin' ) ],
            'create_error'   => [ 'error',   __( 'Mitglied konnte nicht angelegt werden (E-Mail evtl. schon vergeben).', 'vereins-plugin' ) ],
            'person_accepted'=> [ 'success', __( 'Person aufgenommen. Der Zugang wurde angelegt, bisherige Zusagen bleiben erhalten.', 'vereins-plugin' ) ],
            'person_rejected'=> [ 'success', __( 'Eintrag gelöscht.', 'vereins-plugin' ) ],
            'person_failed'  => [ 'error',   __( 'Der Eintrag konnte nicht verarbeitet werden (E-Mail evtl. schon vergeben).', 'vereins-plugin' ) ],
            'existing_added' => [ 'success', __( 'Nutzer aufgenommen. Die bisherige Rolle bleibt bestehen.', 'vereins-plugin' ) ],
            'existing_error' => [ 'error',   __( 'Nutzer konnte nicht aufgenommen werden (evtl. bereits Mitglied).', 'vereins-plugin' ) ],
            'erased'          => [ 'success', __( 'Mitglied vollständig gelöscht, inklusive WordPress-Konto.', 'vereins-plugin' ) ],
            'erased_data_only'=> [ 'success', __( 'Vereinsdaten gelöscht. Das WordPress-Konto bleibt bestehen, da es eine Rolle der Website oder eigene Beiträge trägt – die Vereinsrollen wurden entzogen.', 'vereins-plugin' ) ],
            'erase_error'     => [ 'error',   __( 'Konnte nicht gelöscht werden (eigenes Konto oder Administrator?).', 'vereins-plugin' ) ],
        ];
        if ( ! isset( $map[ $notice ] ) ) {
            return;
        }
        [ $type, $text ] = $map[ $notice ];
        printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
    }
}
