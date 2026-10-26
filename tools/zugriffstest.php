<?php
/**
 * Zugriffsregeln prüfen.
 *
 * Aufruf im Plugin-Ordner:   php tools/zugriffstest.php
 *
 * Hier hängt der heikelste Teil des Plugins: Wer darf sich anmelden, wer
 * kommt in den Mitgliederbereich, wer ins WordPress-Backend. Diese drei
 * Fragen werden an drei verschiedenen Stellen beantwortet, und ein Fehler
 * darin fällt niemandem auf – er sperrt still jemanden aus.
 *
 * Genau das ist schon passiert: Eine frühere Fassung wies über den Filter
 * wp_authenticate_user JEDE Anmeldung ab, zu der es keinen aktiven
 * Mitgliedseintrag gab. Auf einer bestehenden Website hätte das schlagartig
 * alle Redaktionszugänge blockiert.
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
] );

use VereinsPlugin\Core\Activator;
use VereinsPlugin\Members\MemberService;
use VereinsPlugin\Support\Capabilities;

stub_default_roles();
Activator::register_roles();

$service = new MemberService();
$M       = Activator::ROLE_MEMBER;
$O       = Activator::ROLE_ORGA;

/** Nutzer anlegen und bei Bedarf als Mitglied eintragen. */
function mitglied( string $name, array $rollen, ?string $status ): int {
    $id = stub_user( $name, $rollen );
    if ( $status !== null ) {
        $GLOBALS['wpdb']->insert( 'wp_vp_members', [
            'wp_user_id'   => $id,
            'display_name' => $name,
            'status'       => $status,
            'confirmed_at' => null,
        ] );
    }
    return $id;
}

/** Aktueller Status eines Mitglieds. */
function status_von( int $user_id ): string {
    foreach ( $GLOBALS['wpdb']->t['wp_vp_members'] ?? [] as $row ) {
        if ( (int) $row['wp_user_id'] === $user_id ) {
            return (string) $row['status'];
        }
    }
    return '(kein Eintrag)';
}

/** Zeilen-ID des Mitgliedseintrags. */
function mitglied_id( int $user_id ): int {
    foreach ( $GLOBALS['wpdb']->t['wp_vp_members'] ?? [] as $row ) {
        if ( (int) $row['wp_user_id'] === $user_id ) {
            return (int) $row['id'];
        }
    }
    return 0;
}

// =====================================================================
echo "Wer darf was\n";
printf( "    %-36s %-11s %-14s %s\n", '', 'Anmeldung', 'Mitgliederb.', 'wp-admin' );

// [ Bezeichnung, Rollen, Status, erwartet: Anmeldung, Mitgliederbereich, Backend ]
$faelle = [
    [ 'Redakteur, nie Mitglied',            [ 'editor' ],              null,       true,  false, true  ],
    [ 'Redakteur, Mitglied aktiv',          [ 'editor', $M ],          'active',   true,  true,  true  ],
    [ 'Redakteur, Mitglied deaktiviert',    [ 'editor' ],              'inactive', true,  false, true  ],
    [ 'Redakteur, Mitglied wartend',        [ 'editor' ],              'pending',  true,  false, true  ],
    [ 'Abonnent, Mitglied aktiv',           [ 'subscriber', $M ],      'active',   true,  true,  true  ],

    [ 'nur Verein, aktiv',                  [ $M ],                    'active',   true,  true,  false ],
    [ 'nur Verein, deaktiviert',            [ $M ],                    'inactive', false, false, false ],
    [ 'nur Verein, wartend',                [ $M ],                    'pending',  false, false, false ],
    [ 'nur Orgateam, ohne Eintrag',         [ $O ],                    null,       true,  true,  false ],

    // Der Fall, um den es hier besonders geht: Wer die Website betreut und
    // im Verein „nur“ Mitglied ist, muss weiterarbeiten können.
    [ 'Administrator, ohne Mitgliedschaft', [ 'administrator' ],       null,       true,  true,  true  ],
    [ 'Administrator + Mitglied, aktiv',    [ 'administrator', $M ],   'active',   true,  true,  true  ],
    [ 'Administrator + Mitglied, deakt.',   [ 'administrator', $M ],   'inactive', true,  true,  true  ],
    [ 'Administrator + Mitglied, wartend',  [ 'administrator', $M ],   'pending',  true,  true,  true  ],
    [ 'Administrator + Orgateam',           [ 'administrator', $O ],   'active',   true,  true,  true  ],
];

foreach ( $faelle as [ $name, $rollen, $status, $e_login, $e_bereich, $e_backend ] ) {
    $id   = mitglied( $name, $rollen, $status );
    $user = $GLOBALS['stub_users'][ $id ];

    $login   = ! is_wp_error( $service->block_unapproved_login( $user ) );
    $bereich = $service->is_approved( $id );
    // Dieselbe Bedingung wie in BackendGuard::block_backend_access()
    $backend = Capabilities::is_admin_user( $id ) || ! Capabilities::is_club_only( $id );

    $zeige = static fn( bool $ist, bool $soll ): string
        => ( $ist ? 'ja' : 'nein' ) . ( $ist === $soll ? '' : ' ←' . ( $soll ? 'ja' : 'nein' ) );

    Pruefung::ok(
        sprintf(
            "%-36s %-11s %-14s %s",
            $name,
            $zeige( $login, $e_login ),
            $zeige( $bereich, $e_bereich ),
            $zeige( $backend, $e_backend )
        ),
        $login === $e_login && $bereich === $e_bereich && $backend === $e_backend
    );
}

// =====================================================================
echo "\nAussperrschutz\n";

$GLOBALS['stub_current'] = mitglied( 'Chef', [ 'administrator', $M ], 'active' );
$fremder_admin           = mitglied( 'Chefin', [ 'administrator' ], null );

Pruefung::ok( 'eigenes Konto lässt sich nicht ändern',
    is_wp_error( $service->set_roles( $GLOBALS['stub_current'], [ $M ] ) ) );
Pruefung::ok( 'fremder Administrator lässt sich nicht ändern',
    is_wp_error( $service->set_roles( $fremder_admin, [ $M ] ) ) );
Pruefung::ok( 'Administrator behält seine Rolle',
    in_array( 'administrator', $GLOBALS['stub_users'][ $GLOBALS['stub_current'] ]->roles, true ) );
Pruefung::ok( 'Administrator gilt trotz Rolle „Mitglied“ als Orgateam',
    Capabilities::is_orga( $GLOBALS['stub_current'] ) );

// =====================================================================
echo "\nStatus und Vereinsrolle laufen nicht auseinander\n";

$GLOBALS['stub_current'] = 0;
$petra = mitglied( 'Petra', [ 'editor', $M ], 'active' );

$service->set_roles( $petra, [ 'editor' ] );          // Vereinsrollen abwählen
Pruefung::gleich( 'ohne Vereinsrolle nicht mehr aktiv', status_von( $petra ), 'inactive' );
Pruefung::ok( 'Rolle der Website bleibt erhalten',
    in_array( 'editor', $GLOBALS['stub_users'][ $petra ]->roles, true ) );

$service->set_roles( $petra, [ 'editor', $O ] );      // wieder aufnehmen
Pruefung::gleich( 'mit Vereinsrolle wieder aktiv', status_von( $petra ), 'active' );

$service->set_status( mitglied_id( $petra ), 'inactive' );
Pruefung::ok( 'Deaktivieren entfernt die Vereinsrolle',
    ! array_intersect( $GLOBALS['stub_users'][ $petra ]->roles, [ $M, $O ] ) );
Pruefung::ok( 'Deaktivieren lässt die Rolle der Website stehen',
    in_array( 'editor', $GLOBALS['stub_users'][ $petra ]->roles, true ) );

// =====================================================================
echo "\nWer einen bestehenden Termin bearbeiten darf\n";

$GLOBALS['stub_current'] = 0;

$anleger  = mitglied( 'Anleger',  [ $M ], 'active' );
$fremder  = mitglied( 'Fremder',  [ $M ], 'active' );
$orga     = mitglied( 'Orgakraft', [ $O ], 'active' );
$ruhend   = mitglied( 'Ruhend',   [ $M ], 'inactive' );
$chefin   = mitglied( 'Chefin',   [ 'administrator' ], 'active' );

$termin = [ 'id' => 1, 'title' => 'Sommerfest', 'created_by' => $anleger ];

Pruefung::ok( 'wer den Termin angelegt hat, darf ihn ändern',
    Capabilities::can_edit_event( $termin, $anleger ) );
Pruefung::ok( 'ein anderes Mitglied darf fremde Termine NICHT ändern',
    ! Capabilities::can_edit_event( $termin, $fremder ) );
Pruefung::ok( 'das Orgateam darf auch fremde Termine ändern',
    Capabilities::can_edit_event( $termin, $orga ) );
Pruefung::ok( 'ein Administrator darf auch fremde Termine ändern',
    Capabilities::can_edit_event( $termin, $chefin ) );
Pruefung::ok( 'wer nicht freigeschaltet ist, darf nichts ändern',
    ! Capabilities::can_edit_event( $termin, $ruhend ) );

// Auch dann nicht, wenn der Termin auf den eigenen Namen läuft: Ein
// deaktivierter Zugang ist deaktiviert.
$eigener_ruhender = [ 'id' => 2, 'title' => 'Altlast', 'created_by' => $ruhend ];
Pruefung::ok( 'auch am eigenen Termin nicht, solange der Zugang ruht',
    ! Capabilities::can_edit_event( $eigener_ruhender, $ruhend ) );

// Gäste haben negative IDs (vp_people, kein WordPress-Profil). Sie kommen
// über einen Terminlink herein und dürfen dort zusagen – mehr nicht.
$gast_termin = [ 'id' => 3, 'title' => 'Sommerfest', 'created_by' => -7 ];
Pruefung::ok( 'ein Gast darf keinen Termin bearbeiten',
    ! Capabilities::can_edit_event( $gast_termin, -7 ) );

Pruefung::ok( 'ohne Termin kein Recht',
    ! Capabilities::can_edit_event( null, $orga ) );

$GLOBALS['stub_current'] = 0;

Pruefung::abschluss( 'Zugriffsregeln' );
