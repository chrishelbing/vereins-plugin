<?php

namespace VereinsPlugin\Events;

use VereinsPlugin\Auth\AuthController;
use VereinsPlugin\Support\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST-Controller für Termintypen.
 *
 * GET    /vereins-plugin/v1/event-types          – alle (authentifiziert)
 * POST   /vereins-plugin/v1/event-types          – anlegen (Admin)
 * PUT    /vereins-plugin/v1/event-types/{id}     – bearbeiten (Admin)
 * DELETE /vereins-plugin/v1/event-types/{id}     – löschen (Admin)
 */
class EventTypeController {

    private EventTypeModel $model;
    private AuthController $auth;

    public function __construct() {
        $this->model = new EventTypeModel();
        $this->auth  = new AuthController();
    }

    public function register_routes( string $namespace ): void {
        register_rest_route( $namespace, '/event-types', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'index' ],
                'permission_callback' => [ $this->auth, 'require_auth' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'store' ],
                'permission_callback' => [ $this, 'require_admin' ],
                'args'                => $this->type_args(),
            ],
        ] );

        register_rest_route( $namespace, '/event-types/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update' ],
                'permission_callback' => [ $this, 'require_admin' ],
                'args'                => $this->type_args( required: false ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'destroy' ],
                'permission_callback' => [ $this, 'require_admin' ],
            ],
        ] );
    }

    // ------------------------------------------------------------------
    // Callbacks
    // ------------------------------------------------------------------

    public function index( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( $this->model->all(), 200 );
    }

    public function store( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id = $this->model->create( [
            'name'        => $request->get_param( 'name' ),
            'color'       => $request->get_param( 'color' ),
            'icon'        => $request->get_param( 'icon' ),
            'is_resource' => (int) $request->get_param( 'is_resource' ),
            'created_by'  => $request->get_param( '_vp_person_id' ),
        ] );

        $type = $this->model->find( $id );
        return new WP_REST_Response( $type, 201 );
    }

    public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param( 'id' );

        if ( ! $this->model->find( $id ) ) {
            return new WP_Error( 'not_found', __( 'Termintyp nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        $this->model->update( $id, [
            'name'        => $request->get_param( 'name' ),
            'color'       => $request->get_param( 'color' ),
            'icon'        => $request->get_param( 'icon' ),
            'is_resource' => $request->get_param( 'is_resource' ) !== null
                             ? (int) $request->get_param( 'is_resource' ) : null,
        ] );

        return new WP_REST_Response( $this->model->find( $id ), 200 );
    }

    public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param( 'id' );

        if ( ! $this->model->find( $id ) ) {
            return new WP_Error( 'not_found', __( 'Termintyp nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        if ( $this->model->in_use( $id ) ) {
            return new WP_Error(
                'in_use',
                __( 'Termintyp wird noch von Terminen verwendet und kann nicht gelöscht werden.', 'vereins-plugin' ),
                [ 'status' => 409 ]
            );
        }

        $this->model->delete( $id );
        return new WP_REST_Response( null, 204 );
    }

    // ------------------------------------------------------------------
    // Permission & Args
    // ------------------------------------------------------------------

    /**
     * Termintypen sind Konfiguration und damit Administratoren vorbehalten.
     */
    public function require_admin( WP_REST_Request $request ): bool|WP_Error {
        $result = $this->auth->require_auth( $request );
        if ( is_wp_error( $result ) ) return $result;

        if ( ! Capabilities::is_admin_user( (int) $request->get_param( '_vp_person_id' ) ) ) {
            return new WP_Error( 'forbidden', __( 'Nur Administratoren dürfen Termintypen verwalten.', 'vereins-plugin' ), [ 'status' => 403 ] );
        }

        return true;
    }

    private function type_args( bool $required = true ): array {
        return [
            'name' => [
                'required'          => $required,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'color' => [
                'required'          => false,
                'type'              => 'string',
                'default'           => '#3B82F6',
                'validate_callback' => fn( $v ) => preg_match( '/^#[0-9A-Fa-f]{6}$/', $v ) === 1,
            ],
            'icon' => [
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'is_resource' => [
                'required' => false,
                'type'     => 'boolean',
                'default'  => false,
            ],
        ];
    }
}
