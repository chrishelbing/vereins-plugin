<?php

namespace VereinsPlugin\Events;

use VereinsPlugin\Auth\AuthController;
use VereinsPlugin\Members\GuestService;
use VereinsPlugin\Support\Capabilities;
use VereinsPlugin\Support\PublicLink;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST-Controller für Termine.
 *
 * GET    /vereins-plugin/v1/events?from=&to=          – Instanzen im Zeitraum
 * GET    /vereins-plugin/v1/events/{id}               – Stamm-Event
 * POST   /vereins-plugin/v1/events                    – anlegen
 * PUT    /vereins-plugin/v1/events/{id}               – Serie bearbeiten
 * DELETE /vereins-plugin/v1/events/{id}               – Serie löschen
 * POST   /vereins-plugin/v1/events/{id}/exceptions    – Instanz-Ausnahme
 *
 * Rückmeldungen & Mitbringliste:
 * GET    /vereins-plugin/v1/events/{id}/attendance    – Zu-/Absagen + Helfer
 * POST   /vereins-plugin/v1/events/{id}/attendance    – eigene Antwort setzen
 * GET    /vereins-plugin/v1/events/{id}/items         – Mitbringliste
 * POST   /vereins-plugin/v1/events/{id}/items         – Eintrag/Bedarf anlegen
 * POST   /vereins-plugin/v1/items/{item_id}/claim     – Posten übernehmen
 * DELETE /vereins-plugin/v1/items/{item_id}/claim     – Übernahme zurückziehen
 * DELETE /vereins-plugin/v1/items/{item_id}           – Posten löschen
 */
class EventController {

    private EventModel      $model;
    private AttendanceModel $attendance;
    private ItemModel       $items;
    private AuthController  $auth;

    public function __construct() {
        $this->model      = new EventModel();
        $this->attendance = new AttendanceModel();
        $this->items      = new ItemModel();
        $this->auth       = new AuthController();
    }

    public function register_routes( string $namespace ): void {
        register_rest_route( $namespace, '/events', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'index' ],
                'permission_callback' => [ $this->auth, 'require_auth' ],
                'args'                => [
                    'from' => [ 'required' => true,  'type' => 'string' ],
                    'to'   => [ 'required' => true,  'type' => 'string' ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'store' ],
                'permission_callback' => [ $this->auth, 'require_auth' ],
                'args'                => $this->event_args(),
            ],
        ] );

        register_rest_route( $namespace, '/events/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'show' ],
                'permission_callback' => [ $this->auth, 'require_auth' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update' ],
                'permission_callback' => [ $this->auth, 'require_auth' ],
                'args'                => $this->event_args( required: false ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'destroy' ],
                'permission_callback' => [ $this->auth, 'require_auth' ],
            ],
        ] );

        register_rest_route( $namespace, '/events/(?P<id>\d+)/attendance', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'index_attendance' ],
                'permission_callback' => [ $this->auth, 'require_auth_or_link' ],
                'args'                => [
                    'instance_date' => [ 'required' => true, 'type' => 'string' ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'store_attendance' ],
                'permission_callback' => [ $this->auth, 'require_auth_or_link' ],
                'args'                => [
                    'instance_date' => [ 'required' => true, 'type' => 'string' ],
                    'response'      => [
                        'required' => true,
                        'type'     => 'string',
                        'enum'     => [ 'yes', 'no', 'none' ],
                    ],
                    'helping'   => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
                    'help_task' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    // Begleitpersonen: bekannte IDs und/oder neu getippte Namen
                    'companion_ids'   => [ 'required' => false, 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'companion_names' => [ 'required' => false, 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                ],
            ],
        ] );

        // Identifikation ohne Login – öffentlich, aber nur mit gültigem
        // Terminschlüssel und mit Rate-Limit.
        register_rest_route( $namespace, '/guest/identify', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'identify_guest' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id'            => [ 'required' => true, 'type' => 'integer' ],
                    'instance_date' => [ 'required' => true, 'type' => 'string' ],
                    'vp_key'        => [ 'required' => true, 'type' => 'string' ],
                    'name'          => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'email'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'phone'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'return_url'    => [ 'required' => false, 'type' => 'string' ],
                ],
            ],
        ] );

        // Persönliche Vorschlagsliste für Begleitpersonen
        register_rest_route( $namespace, '/companions', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'index_companions' ],
                'permission_callback' => [ $this->auth, 'require_auth_or_link' ],
            ],
        ] );

        register_rest_route( $namespace, '/companions/(?P<companion_id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'destroy_companion' ],
                'permission_callback' => [ $this->auth, 'require_auth_or_link' ],
            ],
        ] );

        // --- Mitbringliste -------------------------------------------------
        register_rest_route( $namespace, '/events/(?P<id>\d+)/items', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'index_items' ],
                'permission_callback' => [ $this->auth, 'require_auth_or_link' ],
                'args'                => [
                    'instance_date' => [ 'required' => true, 'type' => 'string' ],
                    'kind'          => [ 'required' => false, 'type' => 'string', 'default' => 'bring', 'enum' => [ 'bring', 'help' ] ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'store_item' ],
                'permission_callback' => [ $this->auth, 'require_auth_or_link' ],
                'args'                => [
                    'instance_date' => [ 'required' => true, 'type' => 'string' ],
                    'kind'          => [ 'required' => false, 'type' => 'string', 'default' => 'bring', 'enum' => [ 'bring', 'help' ] ],
                    'label'         => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    // Nur Orgateam/Admin dürfen Bedarf vorgeben (siehe store_item).
                    'is_request'      => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
                    'quantity_needed' => [ 'required' => false, 'type' => 'integer', 'default' => 1 ],
                    'note'            => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ] );

        register_rest_route( $namespace, '/items/(?P<item_id>\d+)/claim', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'claim_item' ],
                'permission_callback' => [ $this->auth, 'require_auth_or_link' ],
                'args'                => [
                    'quantity' => [ 'required' => false, 'type' => 'integer', 'default' => 1 ],
                    'note'     => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    // 0 = ich selbst, sonst die Begleitperson
                    'companion_id' => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'release_item' ],
                'permission_callback' => [ $this->auth, 'require_auth_or_link' ],
                'args'                => [
                    'companion_id' => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
                ],
            ],
        ] );

        register_rest_route( $namespace, '/items/(?P<item_id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'destroy_item' ],
                'permission_callback' => [ $this->auth, 'require_auth_or_link' ],
            ],
        ] );

        register_rest_route( $namespace, '/events/(?P<id>\d+)/exceptions', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'store_exception' ],
                'permission_callback' => [ $this->auth, 'require_auth' ],
                'args'                => [
                    'original_date'  => [ 'required' => true,  'type' => 'string' ],
                    'exception_type' => [ 'required' => true,  'type' => 'string', 'enum' => [ 'cancelled', 'modified' ] ],
                    'title'          => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'description'    => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                    'location'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'start_at'       => [ 'required' => false, 'type' => 'string' ],
                    'end_at'         => [ 'required' => false, 'type' => 'string' ],
                ],
            ],
        ] );
    }

    // ------------------------------------------------------------------
    // Callbacks
    // ------------------------------------------------------------------

    public function index( WP_REST_Request $request ): WP_REST_Response {
        $from   = sanitize_text_field( $request->get_param( 'from' ) );
        $to     = sanitize_text_field( $request->get_param( 'to' ) );
        $events = $this->model->in_range( $from, $to );

        $instances = RecurrenceExpander::expand( $events, $from, $to );

        return new WP_REST_Response( $instances, 200 );
    }

    public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $event = $this->model->find( (int) $request->get_param( 'id' ) );
        if ( ! $event ) {
            return new WP_Error( 'not_found', __( 'Termin nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }
        return new WP_REST_Response( $event, 200 );
    }

    public function store( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $data = $this->extract_event_data( $request );

        // Anlegen darf jedes freigeschaltete Mitglied. Sichtbar wird ein Termin
        // aber erst nach Freigabe durch einen Administrator – nur was ein Admin
        // selbst anlegt, ist sofort sichtbar.
        $data['approval_status'] = Capabilities::is_admin_user( (int) $request->get_param( '_vp_person_id' ) )
            ? 'approved'
            : 'pending';

        $event_id = $this->model->create( $data );

        $this->seed_from_templates(
            $event_id,
            $data,
            (int) $request->get_param( '_vp_person_id' ),
            $request->get_param( 'item_template_id' ),
            $request->get_param( 'help_template_id' )
        );

        $event = $this->model->find( $event_id );
        return new WP_REST_Response( $event, 201 );
    }

    /**
     * Befüllt einen frisch angelegten Termin mit den Posten der passenden
     * Vorlagen. Standard sind die am Termintyp hinterlegten Vorlagen; sie
     * lassen sich beim Anlegen überschreiben (0 = ausdrücklich keine).
     *
     * Bei Serien werden nur die Posten für den ersten Termin angelegt –
     * weitere Instanzen bekommen ihre Posten bei Bedarf im Frontend.
     */
    private function seed_from_templates(
        int $event_id,
        array $data,
        int $user_id,
        $item_template_override = null,
        $help_template_override = null
    ): void {
        $type = ( new EventTypeModel() )->find( (int) ( $data['event_type_id'] ?? 0 ) );
        if ( ! $type ) {
            return;
        }

        $instance_date = substr( $data['start_at'] ?? '', 0, 10 );
        if ( ! $instance_date ) {
            return;
        }

        $item_template = $item_template_override !== null
            ? (int) $item_template_override
            : (int) ( $type['default_item_template_id'] ?? 0 );

        $help_template = $help_template_override !== null
            ? (int) $help_template_override
            : (int) ( $type['default_help_template_id'] ?? 0 );

        if ( ! empty( $type['allows_items'] ) && $item_template ) {
            $this->items->apply_template( $event_id, $instance_date, $item_template, $user_id );
        }

        if ( ! empty( $type['needs_helpers'] ) && $help_template ) {
            $this->items->apply_template( $event_id, $instance_date, $help_template, $user_id );
        }
    }

    public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id    = (int) $request->get_param( 'id' );
        $event = $this->model->find( $id );

        if ( ! $event ) {
            return new WP_Error( 'not_found', __( 'Termin nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        $verweigert = $this->verweigere_ohne_bearbeitungsrecht( $event, $request );
        if ( $verweigert ) {
            return $verweigert;
        }

        // Ohne Ersteller: Wer ändert, wird dadurch nicht zum Urheber – sonst
        // verlöre der ursprüngliche Anleger sein Bearbeitungsrecht an den,
        // der zuletzt gespeichert hat.
        $this->model->update( $id, $this->extract_event_data( $request, mit_ersteller: false ) );
        return new WP_REST_Response( $this->model->find( $id ), 200 );
    }

    public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id    = (int) $request->get_param( 'id' );
        $event = $this->model->find( $id );

        if ( ! $event ) {
            return new WP_Error( 'not_found', __( 'Termin nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        $verweigert = $this->verweigere_ohne_bearbeitungsrecht( $event, $request );
        if ( $verweigert ) {
            return $verweigert;
        }

        $this->model->delete( $id );
        return new WP_REST_Response( null, 204 );
    }

    /**
     * Gemeinsame Rechteprüfung fürs Ändern, Löschen und für Instanz-
     * Ausnahmen. Gibt den Fehler zurück, wenn es nicht erlaubt ist, sonst
     * null – so bleibt der Aufrufer bei einer Zeile.
     *
     * require_auth stellt nur sicher, dass überhaupt jemand angemeldet und
     * freigeschaltet ist. Wem welcher Termin gehört, entscheidet sich hier.
     */
    private function verweigere_ohne_bearbeitungsrecht( array $event, WP_REST_Request $request ): ?WP_Error {
        $person_id = (int) $request->get_param( '_vp_person_id' );

        if ( Capabilities::can_edit_event( $event, $person_id ) ) {
            return null;
        }

        return new WP_Error(
            'vp_forbidden',
            __( 'Diesen Termin darfst du nicht bearbeiten. Ändern darf ihn, wer ihn angelegt hat, dazu das Orgateam.', 'vereins-plugin' ),
            [ 'status' => 403 ]
        );
    }

    // ------------------------------------------------------------------
    // Zu-/Absagen
    // ------------------------------------------------------------------

    public function index_attendance( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id    = (int) $request->get_param( 'id' );
        $event = $this->model->find( $id );

        if ( ! $event ) {
            return new WP_Error( 'not_found', __( 'Termin nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        $date    = $this->normalize_date( $request->get_param( 'instance_date' ) );
        $grouped = $this->attendance->for_instance( $id, $date );

        return new WP_REST_Response(
            $this->attendance_payload(
                $grouped,
                (int) $request->get_param( '_vp_person_id' ),
                $id,
                $date,
                (bool) $request->get_param( '_vp_is_guest' )
            ),
            200
        );
    }

    public function store_attendance( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id    = (int) $request->get_param( 'id' );
        $event = $this->model->find( $id );

        if ( ! $event ) {
            return new WP_Error( 'not_found', __( 'Termin nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        // Für noch nicht freigegebene Termine gibt es nichts zuzusagen.
        if ( ( $event['approval_status'] ?? 'approved' ) !== 'approved' ) {
            return new WP_Error( 'not_approved', __( 'Dieser Termin ist noch nicht freigegeben.', 'vereins-plugin' ), [ 'status' => 409 ] );
        }

        $user_id  = (int) $request->get_param( '_vp_person_id' );
        $date     = $this->normalize_date( $request->get_param( 'instance_date' ) );
        $response = $request->get_param( 'response' );

        if ( $response === 'none' ) {
            $this->attendance->remove( $id, $date, $user_id );
        } else {
            $this->attendance->save(
                $id,
                $date,
                $user_id,
                $response,
                (bool) $request->get_param( 'helping' ),
                $request->get_param( 'help_task' )
            );

            // Begleitpersonen werden unabhängig von der eigenen Antwort
            // gesetzt: man kann selbst absagen und trotzdem die Kinder
            // schicken – oder umgekehrt allein kommen.
            if ( $request->get_param( 'companion_ids' ) !== null
                 || $request->get_param( 'companion_names' ) !== null ) {
                ( new CompanionModel() )->set_for_attendance(
                    $id,
                    $date,
                    $user_id,
                    (array) ( $request->get_param( 'companion_ids' ) ?? [] ),
                    (array) ( $request->get_param( 'companion_names' ) ?? [] )
                );
            }
        }

        $grouped = $this->attendance->for_instance( $id, $date );
        return new WP_REST_Response(
            $this->attendance_payload( $grouped, $user_id, $id, $date, (bool) $request->get_param( '_vp_is_guest' ) ),
            200
        );
    }

    // ------------------------------------------------------------------
    // Persönliche Liste der Begleitpersonen
    // ------------------------------------------------------------------

    /**
     * Kennung entgegennehmen: Wer über einen Terminlink zusagt, gibt hier
     * Name und Kontakt an. Danach greift die Wiedererkennung per Cookie und
     * die eigentliche Aktion kann gespeichert werden.
     */
    public function identify_guest( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $event_id = (int) $request->get_param( 'id' );
        $date     = substr( sanitize_text_field( (string) $request->get_param( 'instance_date' ) ), 0, 10 );
        $key      = sanitize_text_field( (string) $request->get_param( 'vp_key' ) );

        if ( ! PublicLink::verify_instance( $key, $event_id, $date ) ) {
            return new WP_Error( 'no_access', __( 'Kein Zugriff auf diesen Termin.', 'vereins-plugin' ), [ 'status' => 401 ] );
        }

        // Einfache Bremse gegen automatisiertes Anlegen von Zugängen.
        $bucket   = 'vp_identify_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        $attempts = (int) get_transient( $bucket );
        if ( $attempts >= 10 ) {
            return new WP_Error( 'too_many_requests', __( 'Zu viele Versuche. Bitte warte einen Moment.', 'vereins-plugin' ), [ 'status' => 429 ] );
        }
        set_transient( $bucket, $attempts + 1, HOUR_IN_SECONDS );

        $return_url = $request->get_param( 'return_url' );
        $return_url = $return_url ? esc_url_raw( $return_url ) : home_url( '/' );

        $result = ( new GuestService() )->identify( [
            'name'  => $request->get_param( 'name' ),
            'email' => $request->get_param( 'email' ),
            'phone' => $request->get_param( 'phone' ),
        ], $return_url );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $user = get_userdata( $result['user_id'] );

        return new WP_REST_Response( [
            'name'      => $user ? $user->display_name : '',
            'created'   => $result['created'],
            'confirmed' => $result['confirmed'],
        ], 200 );
    }

    public function index_companions( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( [
            'saved' => ( new CompanionModel() )->saved_for_user( (int) $request->get_param( '_vp_person_id' ) ),
        ], 200 );
    }

    public function destroy_companion( WP_REST_Request $request ): WP_REST_Response {
        $model   = new CompanionModel();
        $user_id = (int) $request->get_param( '_vp_person_id' );

        $model->forget( $user_id, (int) $request->get_param( 'companion_id' ) );

        return new WP_REST_Response( [ 'saved' => $model->saved_for_user( $user_id ) ], 200 );
    }

    // ------------------------------------------------------------------
    // Mitbringliste & Helferaufgaben (gleiche Mechanik, per kind getrennt)
    // ------------------------------------------------------------------

    public function index_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param( 'id' );
        if ( ! $this->model->find( $id ) ) {
            return new WP_Error( 'not_found', __( 'Termin nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        $user_id = (int) $request->get_param( '_vp_person_id' );
        $date    = $this->normalize_date( $request->get_param( 'instance_date' ) );
        $kind    = $this->normalize_kind( $request->get_param( 'kind' ) );

        return new WP_REST_Response( [
            'kind'       => $kind,
            'items'      => $this->mask_claims(
                $this->items->for_instance( $id, $date, $user_id, $kind ),
                $request
            ),
            'can_manage' => Capabilities::is_orga( $user_id ),
        ], 200 );
    }

    public function store_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id    = (int) $request->get_param( 'id' );
        $event = $this->model->find( $id );

        if ( ! $event ) {
            return new WP_Error( 'not_found', __( 'Termin nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        $user_id  = (int) $request->get_param( '_vp_person_id' );
        $date     = $this->normalize_date( $request->get_param( 'instance_date' ) );
        $kind     = $this->normalize_kind( $request->get_param( 'kind' ) );
        $is_orga  = Capabilities::is_orga( $user_id );

        // Helferaufgaben legt nur das Orgateam fest – Mitglieder übernehmen sie
        // lediglich. Bei der Mitbringliste darf jeder einen eigenen Eintrag machen.
        if ( $kind === 'help' && ! $is_orga ) {
            return new WP_Error( 'forbidden', __( 'Helferaufgaben legt das Orgateam fest.', 'vereins-plugin' ), [ 'status' => 403 ] );
        }

        $is_request = $is_orga && ( $kind === 'help' || $request->get_param( 'is_request' ) );

        $item_id = $this->items->create( [
            'event_id'        => $id,
            'instance_date'   => $date,
            'kind'            => $kind,
            'label'           => $request->get_param( 'label' ),
            'quantity_needed' => $is_request ? max( 1, (int) $request->get_param( 'quantity_needed' ) ) : 1,
            'is_request'      => $is_request,
            'created_by'      => $user_id,
        ] );

        // Freier Eintrag ("Ich bringe Kuchen mit") gehört direkt dem Ersteller.
        if ( ! $is_request ) {
            $this->items->claim( $item_id, $user_id, 1, $request->get_param( 'note' ) );
        }

        return new WP_REST_Response( [
            'kind'       => $kind,
            'items'      => $this->mask_claims(
                $this->items->for_instance( $id, $date, $user_id, $kind ),
                $request
            ),
            'can_manage' => $is_orga,
        ], 201 );
    }

    public function claim_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $item_id = (int) $request->get_param( 'item_id' );
        $item    = $this->items->find( $item_id );

        if ( ! $item ) {
            return new WP_Error( 'not_found', __( 'Eintrag nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        $user_id = (int) $request->get_param( '_vp_person_id' );

        // Für eine Begleitperson übernehmen darf man nur, wenn sie tatsächlich
        // in der eigenen Liste steht.
        $companion_id = (int) $request->get_param( 'companion_id' );
        if ( $companion_id && ! ( new CompanionModel() )->find_saved( $user_id, $companion_id ) ) {
            return new WP_Error( 'unknown_companion', __( 'Diese Person ist nicht hinterlegt.', 'vereins-plugin' ), [ 'status' => 400 ] );
        }

        $ok = $this->items->claim(
            $item_id,
            $user_id,
            max( 1, (int) $request->get_param( 'quantity' ) ),
            $request->get_param( 'note' ),
            $companion_id
        );

        if ( ! $ok ) {
            return new WP_Error( 'no_capacity', __( 'Dieser Posten ist bereits vollständig vergeben.', 'vereins-plugin' ), [ 'status' => 409 ] );
        }

        return new WP_REST_Response( [
            'kind'       => $item['kind'],
            'items'      => $this->mask_claims(
                $this->items->for_instance( (int) $item['event_id'], $item['instance_date'], $user_id, $item['kind'] ),
                $request
            ),
            'can_manage' => Capabilities::is_orga( $user_id ),
        ], 200 );
    }

    public function release_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $item_id = (int) $request->get_param( 'item_id' );
        $item    = $this->items->find( $item_id );

        if ( ! $item ) {
            return new WP_Error( 'not_found', __( 'Eintrag nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        $user_id = (int) $request->get_param( '_vp_person_id' );
        $this->items->release( $item_id, $user_id, (int) $request->get_param( 'companion_id' ) );

        return new WP_REST_Response( [
            'kind'       => $item['kind'],
            'items'      => $this->mask_claims(
                $this->items->for_instance( (int) $item['event_id'], $item['instance_date'], $user_id, $item['kind'] ),
                $request
            ),
            'can_manage' => Capabilities::is_orga( $user_id ),
        ], 200 );
    }

    /**
     * Kompletten Posten löschen – nur Orgateam/Admin oder der Ersteller eines
     * freien Eintrags.
     */
    public function destroy_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $item_id = (int) $request->get_param( 'item_id' );
        $item    = $this->items->find( $item_id );

        if ( ! $item ) {
            return new WP_Error( 'not_found', __( 'Eintrag nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        $user_id = (int) $request->get_param( '_vp_person_id' );

        if ( ! Capabilities::is_orga( $user_id ) && (int) $item['created_by'] !== $user_id ) {
            return new WP_Error( 'forbidden', __( 'Keine Berechtigung.', 'vereins-plugin' ), [ 'status' => 403 ] );
        }

        $this->items->delete( $item_id );

        return new WP_REST_Response( [
            'kind'       => $item['kind'],
            'items'      => $this->mask_claims(
                $this->items->for_instance( (int) $item['event_id'], $item['instance_date'], $user_id, $item['kind'] ),
                $request
            ),
            'can_manage' => Capabilities::is_orga( $user_id ),
        ], 200 );
    }

    /**
     * Einheitliche Antwortstruktur: Namen, Zahlen und die eigene Antwort.
     */
    /**
     * Fremde Namen ausblenden, wenn jemand nur über einen Terminlink da ist.
     *
     * Wer den Link weitergibt, soll damit nicht die Mitgliederliste
     * weiterreichen. Die eigenen Einträge bleiben sichtbar – sonst könnte man
     * seine Übernahme nicht wiederfinden.
     */
    private function mask_claims( array $items, WP_REST_Request $request ): array {
        if ( ! $request->get_param( '_vp_is_guest' ) ) {
            return $items;
        }

        foreach ( $items as &$item ) {
            foreach ( $item['claims'] as &$claim ) {
                if ( empty( $claim['own'] ) ) {
                    // Die eigenen Einträge bleiben unangetastet – sonst ließe
                    // sich die eigene Übernahme nicht mehr zurückziehen.
                    $claim['name'] = __( 'vergeben', 'vereins-plugin' );
                    $claim['via']  = null;
                    $claim['note'] = null;
                    unset( $claim['user_id'], $claim['companion_id'] );
                }
            }
            unset( $claim );
        }
        unset( $item );

        return $items;
    }

    private function attendance_payload( array $grouped, int $user_id, int $event_id = 0, string $instance_date = '', bool $hide_names = false ): array {
        $own         = null;
        $own_helping = false;
        $own_task    = null;

        foreach ( [ 'yes', 'no' ] as $response ) {
            foreach ( $grouped[ $response ] as $entry ) {
                if ( $entry['user_id'] === $user_id ) {
                    $own         = $response;
                    $own_helping = ! empty( $entry['helping'] );
                    $own_task    = $entry['help_task'] ?? null;
                    break 2;
                }
            }
        }

        $companions = new CompanionModel();

        // Helfer aus den übernommenen Aufgaben ableiten – so zählen auch
        // Begleitpersonen mit, die eine Aufgabe übernommen haben.
        $helpers = $event_id
            ? $this->items->helpers_for_instance( $event_id, $instance_date )
            : ( $grouped['helpers'] ?? [] );

        // Gäste über einen Terminlink sehen Zahlen, keine Namen. Wer den Link
        // weitergibt, soll damit nicht die Mitgliederliste weiterreichen.
        $payload_yes       = $hide_names ? [] : $grouped['yes'];
        $payload_no        = $hide_names ? [] : $grouped['no'];
        $payload_helpers   = $hide_names ? [] : $helpers;
        $payload_attending = $hide_names ? [] : ( $grouped['attending'] ?? [] );

        return [
            'yes'           => $payload_yes,
            'no'            => $payload_no,
            'helpers'       => $payload_helpers,
            // Flache Liste aller tatsächlich erwarteten Personen – Mitglieder
            // und Begleitungen, letztere mit Verweis auf das Mitglied.
            'attending'     => $payload_attending,
            'names_hidden'  => $hide_names,
            'yes_count'     => count( $grouped['yes'] ),
            'no_count'      => count( $grouped['no'] ),
            'helper_count'  => count( $helpers ),
            // Erwartete Personen insgesamt (Zusagende + deren Begleitung)
            'people_count'  => (int) ( $grouped['people'] ?? 0 ),
            'own_response'  => $own,
            'own_helping'   => $own_helping,
            'own_help_task' => $own_task,
            // Für die Auswahl im Frontend: eigene gespeicherte Personen und
            // die aktuell für diesen Termin gesetzten.
            'saved_companions' => $companions->saved_for_user( $user_id ),
            'own_companions'   => $event_id
                ? $companions->for_attendance( $event_id, $instance_date, $user_id )
                : [],
        ];
    }

    private function normalize_date( string $value ): string {
        return substr( sanitize_text_field( $value ), 0, 10 );
    }

    private function normalize_kind( $value ): string {
        return $value === 'help' ? 'help' : 'bring';
    }

    public function store_exception( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id    = (int) $request->get_param( 'id' );
        $event = $this->model->find( $id );

        if ( ! $event ) {
            return new WP_Error( 'not_found', __( 'Termin nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        // Eine einzelne Instanz zu verschieben oder abzusagen ist eine
        // Änderung am Termin und braucht dasselbe Recht.
        $verweigert = $this->verweigere_ohne_bearbeitungsrecht( $event, $request );
        if ( $verweigert ) {
            return $verweigert;
        }

        $exc_id = $this->model->save_exception( $id, $request->get_param( 'original_date' ), [
            'exception_type' => $request->get_param( 'exception_type' ),
            'title'          => $request->get_param( 'title' ),
            'description'    => $request->get_param( 'description' ),
            'location'       => $request->get_param( 'location' ),
            'start_at'       => $request->get_param( 'start_at' ),
            'end_at'         => $request->get_param( 'end_at' ),
            'modified_by'    => $request->get_param( '_vp_person_id' ),
        ] );

        return new WP_REST_Response( [ 'exception_id' => $exc_id ], 201 );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function extract_event_data( WP_REST_Request $request, bool $mit_ersteller = true ): array {
        $data = [
            'event_type_id' => $request->get_param( 'event_type_id' ),
            'title'         => $request->get_param( 'title' ),
            'description'   => $request->get_param( 'description' ),
            'location'      => $request->get_param( 'location' ),
            'start_at'      => $request->get_param( 'start_at' ),
            'end_at'        => $request->get_param( 'end_at' ),
            'is_full_day'   => (int) $request->get_param( 'is_full_day' ),
            'resource_id'   => $request->get_param( 'resource_id' ),
            'created_by'    => $mit_ersteller ? $request->get_param( '_vp_person_id' ) : null,
        ];

        $recurrence = $request->get_param( 'recurrence' );
        if ( $recurrence ) {
            $data['recurrence'] = $recurrence;
        }

        return array_filter( $data, fn( $v ) => $v !== null );
    }

    private function event_args( bool $required = true ): array {
        return [
            'event_type_id' => [ 'required' => $required, 'type' => 'integer' ],
            'title'         => [ 'required' => $required, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
            'description'   => [ 'required' => false,     'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field' ],
            'location'      => [ 'required' => false,     'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
            'start_at'      => [ 'required' => $required, 'type' => 'string' ],
            'end_at'        => [ 'required' => $required, 'type' => 'string' ],
            'is_full_day'   => [ 'required' => false,     'type' => 'boolean', 'default' => false ],
            'resource_id'   => [ 'required' => false,     'type' => 'integer' ],
            'recurrence'    => [ 'required' => false,     'type' => 'object' ],
            // Vorlagen: weglassen = Standard des Termintyps, 0 = keine Vorlage
            'item_template_id' => [ 'required' => false, 'type' => 'integer' ],
            'help_template_id' => [ 'required' => false, 'type' => 'integer' ],
        ];
    }
}
