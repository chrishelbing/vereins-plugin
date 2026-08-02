<?php

namespace VereinsPlugin\Photos;

use VereinsPlugin\Auth\AuthController;
use VereinsPlugin\Events\EventModel;
use VereinsPlugin\Support\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST-Controller für Termin-Fotos.
 *
 * GET    /vereins-plugin/v1/events/{id}/photos  – Galerie einer Instanz
 * POST   /vereins-plugin/v1/events/{id}/photos  – Fotos hochladen (multipart)
 * DELETE /vereins-plugin/v1/photos/{photo_id}   – eigenes Foto löschen
 */
class PhotoController {

    private PhotoModel     $model;
    private PhotoService   $service;
    private EventModel     $events;
    private AuthController $auth;

    public function __construct() {
        $this->model   = new PhotoModel();
        $this->service = new PhotoService();
        $this->events  = new EventModel();
        $this->auth    = new AuthController();
    }

    public function register_routes( string $namespace ): void {
        register_rest_route( $namespace, '/events/(?P<id>\d+)/photos', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'index' ],
                'permission_callback' => [ $this->auth, 'require_auth' ],
                'args'                => [
                    'instance_date' => [ 'required' => true, 'type' => 'string' ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'store' ],
                'permission_callback' => [ $this->auth, 'require_auth' ],
                'args'                => [
                    'instance_date' => [ 'required' => true, 'type' => 'string' ],
                ],
            ],
        ] );

        register_rest_route( $namespace, '/photos/(?P<photo_id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'destroy' ],
                'permission_callback' => [ $this->auth, 'require_auth' ],
            ],
        ] );

        // Titelbild des Termins – setzen und entfernen darf das Orgateam.
        register_rest_route( $namespace, '/events/(?P<id>\d+)/image', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'store_image' ],
                'permission_callback' => [ $this->auth, 'require_auth' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'destroy_image' ],
                'permission_callback' => [ $this->auth, 'require_auth' ],
            ],
        ] );
    }

    // ------------------------------------------------------------------
    // Titelbild
    // ------------------------------------------------------------------

    public function store_image( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id    = (int) $request->get_param( 'id' );
        $event = $this->events->find( $id );

        if ( ! $event ) {
            return new WP_Error( 'not_found', __( 'Termin nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        $user_id = (int) $request->get_param( '_vp_person_id' );
        if ( ! Capabilities::is_orga( $user_id ) ) {
            return new WP_Error( 'forbidden', __( 'Das Titelbild setzt das Orgateam.', 'vereins-plugin' ), [ 'status' => 403 ] );
        }

        $files = array_values( $request->get_file_params() );
        if ( empty( $files ) ) {
            return new WP_Error( 'no_files', __( 'Es wurde keine Datei übertragen.', 'vereins-plugin' ), [ 'status' => 400 ] );
        }

        $result = $this->service->store_cover( $files[0], $id, $user_id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return new WP_REST_Response( $result, 201 );
    }

    public function destroy_image( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id    = (int) $request->get_param( 'id' );
        $event = $this->events->find( $id );

        if ( ! $event ) {
            return new WP_Error( 'not_found', __( 'Termin nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        $user_id = (int) $request->get_param( '_vp_person_id' );
        if ( ! Capabilities::is_orga( $user_id ) ) {
            return new WP_Error( 'forbidden', __( 'Das Titelbild setzt das Orgateam.', 'vereins-plugin' ), [ 'status' => 403 ] );
        }

        $this->service->remove_cover( $id );

        return new WP_REST_Response( [ 'image' => null ], 200 );
    }

    // ------------------------------------------------------------------
    // Callbacks
    // ------------------------------------------------------------------

    public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param( 'id' );
        if ( ! $this->events->find( $id ) ) {
            return new WP_Error( 'not_found', __( 'Termin nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        $user_id = (int) $request->get_param( '_vp_person_id' );
        $date    = $this->normalize_date( $request->get_param( 'instance_date' ) );

        return new WP_REST_Response( $this->payload( $id, $date, $user_id ), 200 );
    }

    /**
     * Nimmt einen oder mehrere Uploads entgegen. Fehlerhafte Einzeldateien
     * (zu groß, falscher Typ, Kontingent voll) brechen den Rest nicht ab,
     * sondern werden gesammelt zurückgemeldet.
     */
    public function store( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $id    = (int) $request->get_param( 'id' );
        $event = $this->events->find( $id );

        if ( ! $event ) {
            return new WP_Error( 'not_found', __( 'Termin nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        if ( ( $event['approval_status'] ?? 'approved' ) !== 'approved' ) {
            return new WP_Error( 'not_approved', __( 'Dieser Termin ist noch nicht freigegeben.', 'vereins-plugin' ), [ 'status' => 409 ] );
        }

        $user_id = (int) $request->get_param( '_vp_person_id' );
        $date    = $this->normalize_date( $request->get_param( 'instance_date' ) );
        $files   = $this->normalize_files( $request->get_file_params() );

        // Zustimmung zur Nutzung auf Website und in sozialen Medien – Pflicht,
        // wird als multipart-Feld mitgeschickt (die Checkbox im Frontend).
        $consent = filter_var( $request->get_param( 'consent' ), FILTER_VALIDATE_BOOLEAN );

        if ( empty( $files ) ) {
            return new WP_Error( 'no_files', __( 'Es wurde keine Datei übertragen.', 'vereins-plugin' ), [ 'status' => 400 ] );
        }

        $stored = 0;
        $errors = [];

        foreach ( $files as $file ) {
            $result = $this->service->store( $file, $id, $date, $user_id, $consent );

            if ( is_wp_error( $result ) ) {
                $errors[] = [
                    'file'    => $file['name'] ?? '',
                    'message' => $result->get_error_message(),
                ];
                continue;
            }
            $stored++;
        }

        $payload           = $this->payload( $id, $date, $user_id );
        $payload['stored'] = $stored;
        $payload['errors'] = $errors;

        return new WP_REST_Response( $payload, $stored > 0 ? 201 : 400 );
    }

    public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $photo_id = (int) $request->get_param( 'photo_id' );
        $photo    = $this->model->find( $photo_id );

        if ( ! $photo ) {
            return new WP_Error( 'not_found', __( 'Foto nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        $user_id = (int) $request->get_param( '_vp_person_id' );

        // Eigene Fotos darf jeder löschen, fremde nur Orgateam und Admins.
        if ( (int) $photo['wp_user_id'] !== $user_id && ! Capabilities::is_orga( $user_id ) ) {
            return new WP_Error( 'forbidden', __( 'Keine Berechtigung.', 'vereins-plugin' ), [ 'status' => 403 ] );
        }

        $this->model->delete( $photo_id );

        return new WP_REST_Response(
            $this->payload( (int) $photo['event_id'], $photo['instance_date'], $user_id ),
            200
        );
    }

    // ------------------------------------------------------------------
    // Helfer
    // ------------------------------------------------------------------

    private function payload( int $event_id, string $instance_date, int $user_id ): array {
        $window = $this->service->upload_window( $event_id, $instance_date );

        return [
            'photos'     => $this->model->for_instance( $event_id, $instance_date, $user_id ),
            'quota'      => $this->service->quota_status( $event_id, $instance_date, $user_id ),
            'can_manage' => Capabilities::is_orga( $user_id ),
            'upload'     => [
                'open'          => $window['open'],
                'unlimited'     => $window['unlimited'],
                'days_left'     => $window['days_left'],
                'deadline'      => $window['deadline'],
                'deadline_text' => $window['deadline'] ? mysql2date( 'd.m.Y', $window['deadline'] ) : null,
            ],
        ];
    }

    /**
     * Bringt $_FILES in eine einheitliche Liste – egal ob eine einzelne Datei
     * (photo) oder mehrere (photos[]) geschickt wurden.
     *
     * @return array<int, array>
     */
    private function normalize_files( array $params ): array {
        $files = [];

        foreach ( $params as $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['name'] ) ) {
                continue;
            }

            // Mehrfach-Upload: die Werte sind selbst Arrays.
            if ( is_array( $entry['name'] ) ) {
                foreach ( array_keys( $entry['name'] ) as $i ) {
                    $files[] = [
                        'name'     => $entry['name'][ $i ],
                        'type'     => $entry['type'][ $i ]     ?? '',
                        'tmp_name' => $entry['tmp_name'][ $i ] ?? '',
                        'error'    => $entry['error'][ $i ]    ?? UPLOAD_ERR_NO_FILE,
                        'size'     => $entry['size'][ $i ]     ?? 0,
                    ];
                }
                continue;
            }

            $files[] = $entry;
        }

        // Leere Slots aussortieren (kommen bei manchen Browsern vor).
        return array_values( array_filter(
            $files,
            fn( $f ) => ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_NO_FILE
        ) );
    }

    private function normalize_date( string $value ): string {
        return substr( sanitize_text_field( $value ), 0, 10 );
    }
}
