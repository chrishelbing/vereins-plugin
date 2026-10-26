<?php
/**
 * Die Schnittstelle aus Sicht einer App prüfen.
 *
 * Aufruf im Plugin-Ordner:   php tools/apitest.php
 *
 * Das Web-Frontend meldet sich per Cookie an und merkt von alldem nichts.
 * Eine App hat nur ein Token, und die Fehler, die daran hängen, sind leise:
 * Ein Token, das nach 30 Tagen still abläuft, sieht aus wie ein Fehler der
 * App. Ein Profil ohne Rollenangabe zeigt Knöpfe, die der Server hinterher
 * ablehnt. Ein Versandweg, der gar nicht angeschlossen ist, meldet „Code
 * konnte nicht gesendet werden“ und lässt jemanden auf eine SMS warten, die
 * nie losgeschickt wurde.
 *
 * Geprüft wird deshalb genau das: Lebensdauer und Widerruf von Sitzungen,
 * die Form des Profils und die Erkennung nicht angeschlossener Kanäle.
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/wp-stub.php';

stub_load( [
    'Core/Activator.php',
    'Support/Capabilities.php',
    'Members/MemberModel.php',
    'Members/PeopleModel.php',
    'Members/Person.php',
    'Members/MemberService.php',
    'Admin/Settings.php',
    'Events/CompanionModel.php',
    'Auth/OtpModel.php',
    'Auth/SessionModel.php',
    'Auth/AuthService.php',
    'Auth/AuthController.php',
    'Api/MeController.php',
] );

use VereinsPlugin\Api\MeController;
use VereinsPlugin\Auth\AuthService;
use VereinsPlugin\Auth\SessionModel;
use VereinsPlugin\Core\Activator;
use VereinsPlugin\Events\CompanionModel;

stub_default_roles();
Activator::register_roles();

$M = Activator::ROLE_MEMBER;
$O = Activator::ROLE_ORGA;

// Was das Schema als DEFAULT vorgibt, muss der Ersatz mitbekommen – sonst
// fehlt die Spalte 'revoked' und die Bedingung "revoked = 0" trifft nie zu.
$GLOBALS['wpdb']->defaults['wp_vp_sessions'] = [
    'revoked'        => 0,
    'last_active_at' => null,
];

/** Die gespeicherte Sitzungszeile zu einem Klartext-Token. */
function sitzung( string $token ): ?array {
    $hash = hash( 'sha256', $token );
    foreach ( $GLOBALS['wpdb']->t['wp_vp_sessions'] ?? [] as $row ) {
        if ( $row['token_hash'] === $hash ) {
            return $row;
        }
    }
    return null;
}

/** Das Ablaufdatum einer Sitzung von Hand setzen – in Tagen ab jetzt. */
function ablauf_setzen( string $token, int $tage ): void {
    $hash = hash( 'sha256', $token );
    $GLOBALS['wpdb']->update(
        'wp_vp_sessions',
        [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $tage * DAY_IN_SECONDS ) ],
        [ 'token_hash' => $hash ]
    );
}

/** Abstand des Ablaufdatums von jetzt, in Tagen (gerundet). */
function ablauf_in_tagen( string $token ): int {
    $row = sitzung( $token );
    if ( ! $row ) {
        return -999;
    }
    return (int) round( ( strtotime( $row['expires_at'] . ' UTC' ) - time() ) / DAY_IN_SECONDS );
}

$sessions = new SessionModel();

// =====================================================================
echo "Sitzungen: Lebensdauer und Widerruf\n";

$anna  = stub_user( 'Anna', [ $M ], 'anna@example.test' );
$token = $sessions->create( $anna, 'Testgerät' );

Pruefung::gleich( 'frisches Token löst auf', $sessions->resolve( $token ), $anna );
Pruefung::gleich( 'frisches Token gilt 30 Tage', ablauf_in_tagen( $token ), 30 );
Pruefung::ok( 'Klartext-Token steht nicht in der Tabelle',
    ( sitzung( $token )['token_hash'] ?? '' ) !== $token );
Pruefung::gleich( 'Gerätename wird vermerkt',
    sitzung( $token )['device_name'] ?? '', 'Testgerät' );

// Der eigentliche Punkt: Wer die App benutzt, darf nicht herausfliegen.
ablauf_setzen( $token, 3 );
Pruefung::gleich( 'kurz vor Ablauf löst es noch auf', $sessions->resolve( $token ), $anna );
Pruefung::gleich( 'Benutzung verlängert auf 30 Tage', ablauf_in_tagen( $token ), 30 );
Pruefung::ok( 'letzte Benutzung wird vermerkt',
    ! empty( sitzung( $token )['last_active_at'] ) );

// Ebenso wichtig die Gegenrichtung: Abgelaufenes bleibt abgelaufen.
ablauf_setzen( $token, -1 );
Pruefung::gleich( 'abgelaufenes Token löst nicht auf', $sessions->resolve( $token ), null );
Pruefung::ok( 'abgelaufenes Token wird nicht wiederbelebt', ablauf_in_tagen( $token ) < 0 );

$zweites = $sessions->create( $anna, 'Zweitgerät' );
$sessions->revoke( $anna, $zweites );
Pruefung::gleich( 'widerrufenes Token löst nicht auf', $sessions->resolve( $zweites ), null );

$a = $sessions->create( $anna, 'A' );
$b = $sessions->create( $anna, 'B' );
$sessions->revoke( $anna );
Pruefung::ok( 'Abmelden auf allen Geräten trifft alle Sitzungen',
    $sessions->resolve( $a ) === null && $sessions->resolve( $b ) === null );

$unbekannt = $sessions->resolve( bin2hex( random_bytes( 8 ) ) );
Pruefung::gleich( 'unbekanntes Token löst nicht auf', $unbekannt, null );

// =====================================================================
echo "\nProfil: was die App über sich erfährt\n";

$me = new MeController();

$companions = new CompanionModel();
$companions->remember( $anna, 'Jonas' );
$companions->remember( $anna, 'Mia' );

$profil = $me->profile( $anna );

Pruefung::gleich( 'Kennung', $profil['id'], $anna );
Pruefung::gleich( 'Anzeigename', $profil['display_name'], 'Anna' );
Pruefung::gleich( 'E-Mail', $profil['email'], 'anna@example.test' );
Pruefung::gleich( 'Mitglied ist nicht Orgateam', $profil['is_orgateam'], false );
Pruefung::gleich( 'Mitglied ist nicht Administrator', $profil['is_admin'], false );
Pruefung::gleich( 'Begleitpersonen kommen mit',
    array_column( $profil['companions'], 'name' ), [ 'Jonas', 'Mia' ] );

$berta = stub_user( 'Berta', [ $O ], 'berta@example.test' );
$chef  = stub_user( 'Chef',  [ 'administrator' ], 'chef@example.test' );

Pruefung::gleich( 'Orgateam wird als solches gemeldet',
    $me->profile( $berta )['is_orgateam'], true );
Pruefung::gleich( 'Orgateam ist kein Administrator',
    $me->profile( $berta )['is_admin'], false );
Pruefung::gleich( 'Administrator gilt auch als Orgateam',
    $me->profile( $chef )['is_orgateam'], true );
Pruefung::gleich( 'Administrator wird als solcher gemeldet',
    $me->profile( $chef )['is_admin'], true );

// Gäste haben negative Kennungen und dürfen nie Rechte durchreichen.
$gast = $me->profile( -5 );
Pruefung::gleich( 'Gast ist kein Orgateam',      $gast['is_orgateam'], false );
Pruefung::gleich( 'Gast ist kein Administrator', $gast['is_admin'],    false );
Pruefung::gleich( 'Gast hat keine E-Mail im Profil', $gast['email'], '' );

// =====================================================================
echo "\nVersandwege: nur melden, was auch geht\n";

Pruefung::gleich( 'E-Mail ist immer verfügbar',
    AuthService::channel_available( 'email' ), true );
Pruefung::gleich( 'SMS ohne Anbindung ist nicht verfügbar',
    AuthService::channel_available( 'sms' ), false );
Pruefung::gleich( 'WhatsApp ohne Anbindung ist nicht verfügbar',
    AuthService::channel_available( 'whatsapp' ), false );
Pruefung::gleich( 'erfundener Weg ist nicht verfügbar',
    AuthService::channel_available( 'brieftaube' ), false );

add_filter( 'vp_send_sms_otp', fn( $gesendet, $nummer, $code ) => true );
Pruefung::gleich( 'mit angehängtem Versand wird SMS verfügbar',
    AuthService::channel_available( 'sms' ), true );
Pruefung::gleich( 'WhatsApp bleibt davon unberührt',
    AuthService::channel_available( 'whatsapp' ), false );

Pruefung::abschluss( 'Schnittstelle' );
