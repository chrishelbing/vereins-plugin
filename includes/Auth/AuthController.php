<?php

namespace VereinsPlugin\Auth;

use VereinsPlugin\Events\EventModel;
use VereinsPlugin\Events\ItemModel;
use VereinsPlugin\Members\MemberService;
use VereinsPlugin\Members\PeopleModel;
use VereinsPlugin\Support\PublicLink;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST-Controller für alle Auth-Endpunkte.
 *
 * POST /vereins-plugin/v1/auth/request-otp
 * POST /vereins-plugin/v1/auth/verify-otp
 * POST /vereins-plugin/v1/auth/logout
 */
class AuthController {

    private AuthService $service;

    public function __construct() {
        $this->service = new AuthService();
    }

    public function register_routes( string $namespace ): void {
        register_rest_route( $namespace, '/auth/request-otp', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'request_otp' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'identifier' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => [ $this, 'validate_identifier' ],
                ],
                'channel' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'email',
                    'enum'              => [ 'email', 'sms', 'whatsapp' ],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        register_rest_route( $namespace, '/auth/verify-otp', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'verify_otp' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'identifier' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'code' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn( $v ) => preg_match( '/^\d{6}$/', $v ) === 1,
                ],
                'device' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                // Die Website meldet über diesen Endpunkt auch ihre eigenen
                // Besucher an und braucht dafür das WordPress-Cookie. Eine
                // App braucht es nicht: Sie weist sich mit dem Token aus und
                // schleppte das Cookie sonst bei jeder Anfrage ungenutzt mit.
                'with_cookie' => [
                    'required' => false,
                    'type'     => 'boolean',
                    'default'  => true,
                ],
            ],
        ] );

        register_rest_route( $namespace, '/auth/logout', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'logout' ],
            'permission_callback' => [ $this, 'require_auth' ],
        ] );
    }

    // ------------------------------------------------------------------
    // Callbacks
    // ------------------------------------------------------------------

    public function request_otp( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $identifier = $request->get_param( 'identifier' );
        $channel    = $request->get_param( 'channel' );

        // Vor allem anderen: Gibt es diesen Weg überhaupt? Sonst verbrauchte
        // eine aussichtslose Anfrage einen Platz im Rate-Limit und legte
        // einen Code an, der nirgends ankommt.
        if ( ! AuthService::channel_available( $channel ) ) {
            return new WP_Error(
                'channel_unavailable',
                __( 'Dieser Versandweg ist auf dieser Website nicht eingerichtet.', 'vereins-plugin' ),
                [ 'status' => 501 ]
            );
        }

        // Rate-Limit: max. 5 Anfragen pro 10 Minuten (einfach via Transient)
        $rate_key = 'vp_otp_rate_' . md5( $identifier );
        $attempts = (int) get_transient( $rate_key );
        if ( $attempts >= 5 ) {
            return new WP_Error( 'too_many_requests', __( 'Zu viele Anfragen. Bitte warte 10 Minuten.', 'vereins-plugin' ), [ 'status' => 429 ] );
        }
        set_transient( $rate_key, $attempts + 1, 10 * MINUTE_IN_SECONDS );

        // Noch nicht aufgenommene Personen haben keinen Zugang – ein Code
        // brächte sie nicht weiter. Lieber sagen, woran es liegt.
        if ( $channel === 'email' && ( new PeopleModel() )->find_by_email( $identifier ) && ! get_user_by( 'email', $identifier ) ) {
            return new WP_Error(
                'not_approved',
                __( 'Deine Anmeldung liegt vor, ist aber noch nicht freigeschaltet.', 'vereins-plugin' ),
                [ 'status' => 403 ]
            );
        }

        $sent = $this->service->request_otp( $identifier, $channel );

        if ( ! $sent ) {
            return new WP_Error( 'send_failed', __( 'Code konnte nicht gesendet werden.', 'vereins-plugin' ), [ 'status' => 500 ] );
        }

        return new WP_REST_Response( [ 'message' => __( 'Code gesendet.', 'vereins-plugin' ) ], 200 );
    }

    public function verify_otp( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $result = $this->service->verify_otp(
            $request->get_param( 'identifier' ),
            $request->get_param( 'code' ),
            $request->get_param( 'device' )
        );

        if ( ! $result ) {
            return new WP_Error( 'invalid_code', __( 'Ungültiger oder abgelaufener Code.', 'vereins-plugin' ), [ 'status' => 401 ] );
        }

        $member_service = new MemberService();
        if ( ! $member_service->is_approved( (int) $result['user_id'] ) ) {
            // Kein Zugriff -> die bereits erzeugte Session sofort widerrufen.
            $this->service->revoke_session( (int) $result['user_id'], $result['token'] );
            return new WP_Error( 'not_approved', __( 'Dein Zugang wurde noch nicht freigeschaltet.', 'vereins-plugin' ), [ 'status' => 403 ] );
        }

        // Zusätzlich zum Bearer-Token (App) auch die WordPress-Session starten,
        // damit der OTP-Login auch als vollwertiger Website-Login funktioniert.
        // Die App schaltet das über with_cookie=false ab.
        if ( $request->get_param( 'with_cookie' ) ) {
            wp_set_auth_cookie( (int) $result['user_id'], true );
            wp_set_current_user( (int) $result['user_id'] );
        }

        return new WP_REST_Response( [
            'token'   => $result['token'],
            'user_id' => $result['user_id'],
        ], 200 );
    }

    public function logout( WP_REST_Request $request ): WP_REST_Response {
        $user_id = $request->get_param( '_vp_person_id' ); // gesetzt durch require_auth
        $token   = $this->extract_bearer( $request );

        $this->service->revoke_session( $user_id, $token );
        wp_clear_auth_cookie();

        return new WP_REST_Response( [ 'message' => __( 'Abgemeldet.', 'vereins-plugin' ) ], 200 );
    }

    // ------------------------------------------------------------------
    // Permission-Callback & Helpers
    // ------------------------------------------------------------------

    /**
     * Middleware: prüft Bearer-Token ODER WordPress-Cookie-Auth (Nonce).
     * Setzt _vp_person_id im Request für nachfolgende Callbacks.
     */
    public function require_auth( WP_REST_Request $request ): bool|WP_Error {
        $user_id = null;

        // --- 1. WordPress Cookie-Auth (Frontend-Formulare) ---------------
        if ( is_user_logged_in() ) {
            $nonce = $request->get_header( 'X-WP-Nonce' );
            if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                $user_id = get_current_user_id();
            }
        }

        // --- 2. Bearer-Token (App / API) ----------------------------------
        if ( ! $user_id ) {
            $token = $this->extract_bearer( $request );
            if ( ! $token ) {
                return new WP_Error( 'no_token', __( 'Kein Token angegeben.', 'vereins-plugin' ), [ 'status' => 401 ] );
            }

            $service = new AuthService();
            $user_id = $service->resolve_session( $token );

            if ( ! $user_id ) {
                return new WP_Error( 'invalid_token', __( 'Token ungültig oder abgelaufen.', 'vereins-plugin' ), [ 'status' => 401 ] );
            }
        }

        // --- 3. Freischaltung prüfen (unabhängig vom Auth-Weg) ------------
        if ( ! ( new MemberService() )->is_approved( $user_id ) ) {
            return new WP_Error( 'not_approved', __( 'Dein Zugang wurde noch nicht freigeschaltet.', 'vereins-plugin' ), [ 'status' => 403 ] );
        }

        $request->set_param( '_vp_person_id', $user_id );
        return true;
    }

    /**
     * Wie require_auth, lässt aber zusätzlich Gäste über einen signierten
     * Terminlink zu.
     *
     * Gedacht für Zusage, Helferaufgaben und Mitbringliste: Wer den Link hat,
     * soll seine Aufgabe erledigen können, ohne sich anzumelden. Fotos bleiben
     * bewusst außen vor – dort hängt das Speicherkontingent an der Identität
     * und es sind Personen abgebildet.
     *
     * Zwei Bedingungen müssen zusammenkommen:
     *   1. gültiger, signierter Schlüssel für genau diese Termininstanz
     *   2. eine bereits hinterlegte Gast-Kennung (Cookie)
     * Die Kennung entsteht über den Identifikations-Endpunkt.
     */
    public function require_auth_or_link( WP_REST_Request $request ): bool|WP_Error {
        if ( is_user_logged_in() || $this->extract_bearer( $request ) ) {
            return $this->require_auth( $request );
        }

        $event_id = (int) $request->get_param( 'id' );
        $date     = substr( sanitize_text_field( (string) $request->get_param( 'instance_date' ) ), 0, 10 );
        // Der Schlüssel kommt als Parameter oder – bequemer für JavaScript –
        // als Header mit jeder Anfrage.
        $key = sanitize_text_field( (string) ( $request->get_param( 'vp_key' ) ?: $request->get_header( 'x-vp-key' ) ) );

        // Bei Endpunkten, die nur die Posten-ID kennen, den Termin nachschlagen.
        if ( ! $event_id && $request->get_param( 'item_id' ) ) {
            $item = ( new ItemModel() )->find( (int) $request->get_param( 'item_id' ) );
            if ( $item ) {
                $event_id = (int) $item['event_id'];
                $date     = $item['instance_date'];
            }
        }

        if ( ! $event_id || ! $date || ! $key || ! PublicLink::verify_instance( $key, $event_id, $date ) ) {
            return new WP_Error( 'no_access', __( 'Kein Zugriff auf diesen Termin.', 'vereins-plugin' ), [ 'status' => 401 ] );
        }

        // Ein zurückgezogener Termin schließt auch den Link – sonst liefe die
        // Zusage weiter, obwohl die Seite den Termin nicht mehr zeigt.
        $event = ( new EventModel() )->find( $event_id );
        if ( ! $event || ( $event['approval_status'] ?? 'approved' ) !== 'approved' ) {
            return new WP_Error( 'no_access', __( 'Kein Zugriff auf diesen Termin.', 'vereins-plugin' ), [ 'status' => 401 ] );
        }

        $guest_id = PublicLink::current_guest();
        if ( ! $guest_id ) {
            return new WP_Error( 'identify_first', __( 'Bitte gib zuerst an, wer du bist.', 'vereins-plugin' ), [ 'status' => 401 ] );
        }

        $request->set_param( '_vp_person_id', $guest_id );
        $request->set_param( '_vp_is_guest', true );
        return true;
    }

    private function extract_bearer( WP_REST_Request $request ): ?string {
        $auth = $request->get_header( 'authorization' );
        if ( $auth && preg_match( '/^Bearer\s+(.+)$/i', $auth, $m ) ) {
            return $m[1];
        }
        return null;
    }

    public function validate_identifier( string $value, WP_REST_Request $request ): bool {
        $channel = $request->get_param( 'channel' ) ?: 'email';
        if ( $channel === 'email' ) {
            return is_email( $value );
        }
        // Für SMS/WhatsApp: einfache Telefonnummernprüfung
        return preg_match( '/^\+?[\d\s\-]{7,20}$/', $value ) === 1;
    }
}
