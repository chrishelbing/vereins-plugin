<?php

namespace VereinsPlugin\Polls;

use VereinsPlugin\Members\GuestService;
use VereinsPlugin\Members\MemberCode;
use VereinsPlugin\Members\Person;
use VereinsPlugin\Members\SavedNames;
use VereinsPlugin\Support\Capabilities;
use VereinsPlugin\Support\PublicLink;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Schnittstelle des Moduls Umfragen: /vereins-plugin/v1/polls…
 *
 * Teilnahme ohne Anmeldung: Gäste nennen einmal ihren Namen (E-Mail
 * freiwillig) und werden danach am Cookie wiedererkannt – dieselbe
 * Gast-Erkennung wie bei den Terminen (Basis). Gehört die E-Mail zu einem
 * Mitgliedskonto, geht ein Zugangscode dorthin (MemberCode).
 */
class PollController {

    private PollModel   $model;
    private PollService $service;

    public function __construct() {
        $this->model   = new PollModel();
        $this->service = new PollService( $this->model );
    }

    public function register_routes( string $namespace ): void {
        register_rest_route( $namespace, '/polls', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'index' ],
            'permission_callback' => '__return_true',
            'args'                => [ 'id' => [ 'type' => 'integer' ] ],
        ] );
        register_rest_route( $namespace, '/polls/identify', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'identify' ],
            'permission_callback' => '__return_true',
        ] );
        // Gemerkte Namen (Basis) aus der Vorschlagsliste nehmen.
        register_rest_route( $namespace, '/polls/saved-names', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'forget_name' ],
            'permission_callback' => static fn() => is_user_logged_in(),
        ] );
        register_rest_route( $namespace, '/polls/(?P<id>\d+)/entries', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'enter' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'withdraw_entries' ],
                'permission_callback' => '__return_true',
            ],
        ] );
        register_rest_route( $namespace, '/polls/(?P<id>\d+)/vote', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'vote' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'withdraw' ],
                'permission_callback' => '__return_true',
            ],
        ] );
    }

    /**
     * Wer fragt? Angemeldet: das Konto. Sonst ein wiedererkannter Gast, sonst 0.
     */
    public static function current_person(): int {
        if ( is_user_logged_in() ) {
            return (int) get_current_user_id();
        }
        return PublicLink::current_guest();
    }

    /** Offene und beendete Umfragen (keine Entwürfe), oder eine bestimmte. */
    public function index( WP_REST_Request $request ): WP_REST_Response {
        $person = self::current_person();
        $orga   = Capabilities::is_orga();
        $id     = (int) $request->get_param( 'id' );

        $umfragen = $id
            ? array_filter( [ $this->model->find( $id ) ] )
            : array_merge( $this->model->all( 'open' ), $this->model->all( 'closed' ) );

        $liste = [];
        foreach ( $umfragen as $poll ) {
            if ( $poll['status'] === 'draft' ) {
                continue;
            }
            $liste[] = $this->service->payload( $poll, $person, $orga );
        }

        return new WP_REST_Response( [
            'person' => $person ? self::person_payload( $person ) : null,
            'polls'  => $liste,
        ], 200 );
    }

    public function identify( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        if ( is_user_logged_in() ) {
            $id = get_current_user_id();
            return new WP_REST_Response( [ 'person' => self::person_payload( $id ) ], 200 );
        }

        if ( ! MemberCode::may_identify() ) {
            return new WP_Error( 'too_many_requests', __( 'Zu viele Versuche. Bitte warte einen Moment.', 'vereins-plugin' ), [ 'status' => 429 ] );
        }

        $return_url = $request->get_param( 'return_url' );
        $result     = ( new GuestService() )->identify( [
            'name'  => $request->get_param( 'name' ),
            'email' => $request->get_param( 'email' ),
            'phone' => $request->get_param( 'phone' ),
        ], $return_url ? esc_url_raw( (string) $return_url ) : home_url( '/' ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Mitgliedskonto: erkannt wird es erst mit dem Zugangscode.
        if ( ! empty( $result['member_id'] ) ) {
            return MemberCode::send( (int) $result['member_id'], sanitize_email( (string) $request->get_param( 'email' ) ) );
        }

        return new WP_REST_Response( [ 'person' => self::person_payload( (int) $result['person_id'] ) ], 200 );
    }

    public function forget_name( WP_REST_Request $request ): WP_REST_Response {
        $person = get_current_user_id();
        SavedNames::forget( $person, (string) $request->get_param( 'name' ) );
        return new WP_REST_Response( [ 'saved_names' => SavedNames::for_person( $person ) ], 200 );
    }

    /** Wer abstimmt – und ob für Rückfragen ein Kontakt hinterlegt ist. */
    private static function person_payload( int $id ): array {
        return [
            'id'      => $id,
            'name'    => Person::name( $id ),
            'guest'   => Person::is_guest( $id ),
            'contact' => EntryService::has_contact( $id ),
        ];
    }

    /** Wettkampfmeldung abgeben oder ändern (alle Starter des Melders). */
    public function enter( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $poll = $this->model->find( (int) $request['id'] );
        if ( ! $poll || $poll['status'] === 'draft' ) {
            return new WP_Error( 'not_found', __( 'Umfrage nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }
        $person = self::current_person();
        $rows   = array_values( array_filter( (array) $request->get_param( 'starters' ), 'is_array' ) );
        if ( ! $rows ) {
            return new WP_Error( 'nothing_entered', __( 'Bitte trage mindestens einen Starter ein.', 'vereins-plugin' ), [ 'status' => 400 ] );
        }
        $result = ( new EntryService( $this->model ) )->submit( $poll, $person, $rows );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return new WP_REST_Response( $this->service->payload( $poll, $person, Capabilities::is_orga() ), 200 );
    }

    public function withdraw_entries( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $poll   = $this->model->find( (int) $request['id'] );
        $person = self::current_person();
        if ( ! $poll || ! $person || $poll['kind'] !== 'entry' ) {
            return new WP_Error( 'not_found', __( 'Umfrage nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }
        if ( ! $this->service->is_open( $poll ) ) {
            return new WP_Error( 'poll_closed', __( 'Der Meldeschluss ist vorbei.', 'vereins-plugin' ), [ 'status' => 409 ] );
        }
        // Schon Gemeldetes wird dabei zur Abmeldung fürs Orgateam.
        $this->model->replace_entries( (int) $poll['id'], $person, [] );
        return new WP_REST_Response( $this->service->payload( $poll, $person, Capabilities::is_orga() ), 200 );
    }

    public function vote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $poll = $this->model->find( (int) $request['id'] );
        if ( ! $poll || $poll['status'] === 'draft' ) {
            return new WP_Error( 'not_found', __( 'Umfrage nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }

        $person = self::current_person();
        $result = $this->service->vote( $poll, $person, [
            'answers' => (array) $request->get_param( 'answers' ),
            'choices' => (array) $request->get_param( 'choices' ),
            'texts'   => (array) $request->get_param( 'texts' ),
        ] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return new WP_REST_Response( $this->service->payload( $poll, $person, Capabilities::is_orga() ), 200 );
    }

    public function withdraw( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $poll   = $this->model->find( (int) $request['id'] );
        $person = self::current_person();
        if ( ! $poll || ! $person ) {
            return new WP_Error( 'not_found', __( 'Umfrage nicht gefunden.', 'vereins-plugin' ), [ 'status' => 404 ] );
        }
        if ( ! $this->service->is_open( $poll ) ) {
            return new WP_Error( 'poll_closed', __( 'Diese Umfrage ist beendet.', 'vereins-plugin' ), [ 'status' => 409 ] );
        }
        $this->model->delete_votes( (int) $poll['id'], $person );
        $this->model->delete_texts( (int) $poll['id'], $person );
        return new WP_REST_Response( $this->service->payload( $poll, $person, Capabilities::is_orga() ), 200 );
    }
}
