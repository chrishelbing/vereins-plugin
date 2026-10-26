<?php

namespace VereinsPlugin\Api;

use VereinsPlugin\Auth\AuthController;
use VereinsPlugin\Events\CompanionModel;
use VereinsPlugin\Members\Person;
use VereinsPlugin\Support\Capabilities;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Auskunft über die angemeldete Person.
 *
 * GET /vereins-plugin/v1/me
 *
 * Nach dem Tausch von Einmalcode gegen Token kennt eine App nur eine Zahl.
 * Sie weiß weder, wie die Person heißt, noch ob sie zum Orgateam gehört –
 * kann also weder begrüßen noch entscheiden, ob der Knopf „Bedarf vorgeben“
 * überhaupt erscheinen darf. Genau diese Lücke schließt dieser Endpunkt:
 * ein Aufruf beim Start, danach hält die App die Antwort vor.
 *
 * Die Angaben sind ausdrücklich nur ein Hinweis für die Oberfläche. Jeder
 * schreibende Endpunkt prüft die Rechte selbst noch einmal – eine App, die
 * hier lügt oder die Antwort verbiegt, gewinnt damit nichts.
 */
class MeController {

    private AuthController $auth;

    public function __construct() {
        $this->auth = new AuthController();
    }

    public function register_routes( string $namespace ): void {
        register_rest_route( $namespace, '/me', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'show' ],
            'permission_callback' => [ $this->auth, 'require_auth' ],
        ] );
    }

    public function show( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response(
            $this->profile( (int) $request->get_param( '_vp_person_id' ) ),
            200
        );
    }

    /**
     * Das Profil als reines Feld – ohne REST-Umgebung, damit es sich prüfen
     * lässt.
     *
     * Die Begleitpersonen kommen mit, weil die App sie beim ersten Zusage-
     * Dialog schon braucht und ein zweiter Aufruf dafür Verschwendung wäre.
     *
     * @return array{
     *     id: int, display_name: string, email: string,
     *     is_orgateam: bool, is_admin: bool, companions: array
     * }
     */
    public function profile( int $user_id ): array {
        $user = $user_id > 0 ? get_userdata( $user_id ) : false;

        return [
            'id'           => $user_id,
            'display_name' => Person::name( $user_id ),
            'email'        => $user ? (string) $user->user_email : '',
            'is_orgateam'  => Capabilities::is_orga( $user_id ),
            'is_admin'     => Capabilities::is_admin_user( $user_id ),
            'companions'   => ( new CompanionModel() )->saved_for_user( $user_id ),
        ];
    }
}
