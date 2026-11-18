<?php

namespace VereinsPlugin\Members;

use VereinsPlugin\Auth\AuthController;
use VereinsPlugin\Auth\AuthService;
use VereinsPlugin\Auth\OtpModel;
use WP_Error;
use WP_REST_Response;

/**
 * Basis: Zugangscode an ein Mitglied schicken, das sich als Gast eintragen
 * wollte – mit der E-Mail-Adresse seines Kontos.
 *
 * Gemeinsam genutzt von den Modulen Termine (Terminlink) und Umfragen
 * (Teilnahme ohne Anmeldung). Die Adresse allein weist niemanden aus; erst
 * wer den Code eingibt, ist als dieses Mitglied angemeldet (seit 1.15.6).
 *
 * Die Antwort nennt weder Namen noch Rolle – wer nur die Adresse kennt,
 * erfährt hier nichts über das Konto. Eingelöst wird der Code über
 * /auth/verify-otp, denselben Weg wie die gewöhnliche Anmeldung.
 */
class MemberCode {

    public static function send( int $member_id, string $email ): WP_REST_Response|WP_Error {
        // Ein Konto ohne freigeschalteten Zugang könnte den Code nicht
        // einlösen. Dann lieber gleich sagen, wie es weitergeht.
        if ( ! ( new MemberService() )->is_approved( $member_id ) ) {
            return new WP_Error(
                'not_approved',
                __( 'Zu dieser E-Mail-Adresse gibt es ein Konto, das für den Mitgliederbereich nicht freigeschaltet ist. Lass das Feld leer, um als Gast teilzunehmen.', 'vereins-plugin' ),
                [ 'status' => 403 ]
            );
        }

        if ( OtpModel::is_locked( $email ) ) {
            return AuthController::locked_error();
        }

        if ( ! AuthService::may_request( $email ) ) {
            return new WP_Error( 'too_many_requests', __( 'Zu viele Anfragen. Bitte warte 10 Minuten.', 'vereins-plugin' ), [ 'status' => 429 ] );
        }

        if ( ! ( new AuthService() )->request_otp( $email, 'email' ) ) {
            return new WP_Error( 'send_failed', __( 'Code konnte nicht gesendet werden.', 'vereins-plugin' ), [ 'status' => 500 ] );
        }

        return new WP_REST_Response( [ 'code_required' => true ], 200 );
    }

    /**
     * Einfache Bremse gegen automatisiertes Anlegen von Gast-Zugängen:
     * höchstens zehn Versuche je IP-Adresse und Stunde. Zählt mit und sagt,
     * ob der Versuch noch erlaubt ist. Gemeinsam für Termine und Umfragen.
     */
    public static function may_identify(): bool {
        $bucket   = 'vp_identify_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        $attempts = (int) get_transient( $bucket );
        if ( $attempts >= 10 ) {
            return false;
        }
        set_transient( $bucket, $attempts + 1, HOUR_IN_SECONDS );
        return true;
    }
}
