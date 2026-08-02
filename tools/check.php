<?php
/**
 * Selbstprüfung des Plugins.
 *
 * Aufruf im Plugin-Ordner:   php tools/check.php
 *
 * Deckt die Fehlerklassen ab, die beim bloßen Lesen durchrutschen und erst
 * zur Laufzeit auffallen – oder gar nicht, weil sie still danebengehen:
 * ein Symbolname mit Tippfehler liefert einen unsichtbaren Knopf, ein
 * verschobener Namensraum eine Klasse, die der Autoloader nicht findet.
 *
 * Bewusst ohne Abhängigkeiten und ohne WordPress: reine Quelltextanalyse,
 * damit das Skript überall läuft, wo PHP läuft. Rückgabewert 1, wenn etwas
 * nicht stimmt – so lässt es sich auch in einen Haken vor dem Commit hängen.
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );
$fehler = 0;

/** Alle PHP-Dateien des Plugins. */
function dateien( string $root ): array {
    $liste = [];
    $it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes' ) );
    foreach ( $it as $datei ) {
        if ( $datei->isFile() && $datei->getExtension() === 'php' ) {
            $liste[] = $datei->getPathname();
        }
    }
    sort( $liste );
    return $liste;
}

function quelltext( array $dateien ): string {
    return implode( "\n", array_map( fn( $d ) => file_get_contents( $d ), $dateien ) );
}

function melde( string $titel, array $probleme, string $ok_text ): int {
    if ( ! $probleme ) {
        printf( "  OK      %-34s %s\n", $titel, $ok_text );
        return 0;
    }
    printf( "  FEHLER  %s\n", $titel );
    foreach ( $probleme as $p ) {
        printf( "          - %s\n", $p );
    }
    return count( $probleme );
}

$dateien   = dateien( $root );
$quelle    = quelltext( $dateien );

echo "Vereins-Plugin – Selbstprüfung\n";
echo str_repeat( '-', 70 ), "\n";

// ---------------------------------------------------------------------
// 1. Syntax
// ---------------------------------------------------------------------
// php -l braucht exec(); manche Umgebungen sperren das. Dann lieber
// ehrlich überspringen als mit einem Fehler abbrechen.
$exec_frei = function_exists( 'exec' )
    && ! in_array( 'exec', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true );

if ( ! $exec_frei ) {
    printf( "  ----    %-34s %s\n", 'Syntax', 'übersprungen, exec() nicht verfügbar' );
} else {
    $syntax = [];
    foreach ( array_merge( $dateien, [ $root . '/vereins-plugin.php' ] ) as $datei ) {
        $ausgabe = [];
        exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $datei ) . ' 2>&1', $ausgabe, $code );
        $text = trim( implode( ' ', $ausgabe ) );

        // Rückgabewert UND Ausgabe auswerten: Beide sagen im Normalfall
        // dasselbe, aber es gibt Umgebungen, die den Rückgabewert einer
        // Unterprozess-Ausführung nicht durchreichen. Dann trägt der Text.
        $sauber = $code === 0 || str_contains( $text, 'No syntax errors' );
        if ( ! $sauber ) {
            $syntax[] = str_replace( $root . '/', '', $datei ) . ': ' . $text;
        }
    }
    $fehler += melde( 'Syntax', $syntax, ( count( $dateien ) + 1 ) . ' Dateien fehlerfrei' );
}

// ---------------------------------------------------------------------
// 2. Autoloader: Namensraum, Pfad und Klassenname müssen zusammenpassen
// ---------------------------------------------------------------------
$pfade = [];
foreach ( $dateien as $datei ) {
    $inhalt   = file_get_contents( $datei );
    $relativ  = str_replace( $root . '/includes/', '', $datei );
    $erwartet = 'VereinsPlugin';
    if ( str_contains( $relativ, '/' ) ) {
        $erwartet .= '\\' . str_replace( '/', '\\', dirname( $relativ ) );
    }

    if ( preg_match( '/^namespace\s+([\w\\\\]+);/m', $inhalt, $m ) ) {
        if ( $m[1] !== $erwartet ) {
            $pfade[] = "$relativ: Namensraum {$m[1]}, erwartet $erwartet";
        }
    } else {
        $pfade[] = "$relativ: kein Namensraum";
    }

    if ( preg_match( '/^(?:abstract\s+|final\s+)?class\s+(\w+)/m', $inhalt, $m ) ) {
        $dateiname = basename( $datei, '.php' );
        if ( $m[1] !== $dateiname ) {
            $pfade[] = "$relativ: Klasse {$m[1]} passt nicht zum Dateinamen";
        }
    }
}
$fehler += melde( 'Autoloader-Pfade', $pfade, count( $dateien ) . ' Klassen auffindbar' );

// ---------------------------------------------------------------------
// 3. Symbole: jeder verwendete Name muss hinterlegt sein
//
// Icons::svg() gibt bei unbekanntem Namen einen leeren String zurück. Ein
// Tippfehler führt also nicht zu einem Fehler, sondern zu einem leeren Knopf.
// ---------------------------------------------------------------------
$icons     = file_get_contents( $root . '/includes/Support/Icons.php' );
preg_match_all( "/^\s*'([\w-]+)'\s*=>\s*'</m", $icons, $m );
$definiert = $m[1];
preg_match_all( "/Icons::svg\(\s*'([\w-]+)'/", $quelle, $m );
$benutzt   = array_unique( $m[1] );

$symbole = [];
foreach ( array_diff( $benutzt, $definiert ) as $name ) {
    $symbole[] = "'$name' wird verwendet, ist aber nicht hinterlegt";
}
foreach ( array_diff( $definiert, $benutzt ) as $name ) {
    $symbole[] = "'$name' ist hinterlegt, wird aber nirgends verwendet";
}
$fehler += melde( 'Symbole', $symbole, count( $definiert ) . ' Symbole, alle in Gebrauch' );

// ---------------------------------------------------------------------
// 4. Shortcodes: die Übersicht im Backend liest die Registry – jede dort
//    genannte Klasse muss existieren und sich beschreiben können.
// ---------------------------------------------------------------------
$registry = file_get_contents( $root . '/includes/Frontend/ShortcodeRegistry.php' );
preg_match_all( '/([\w]+)::class/', $registry, $m );
$shortcodes = [];
foreach ( array_unique( $m[1] ) as $klasse ) {
    $datei = $root . '/includes/Frontend/' . $klasse . '.php';
    if ( ! file_exists( $datei ) ) {
        $shortcodes[] = "$klasse ist eingetragen, die Datei fehlt aber";
        continue;
    }
    $inhalt = file_get_contents( $datei );
    foreach ( [ 'describe', 'register' ] as $methode ) {
        if ( ! preg_match( '/function\s+' . $methode . '\s*\(/', $inhalt ) ) {
            $shortcodes[] = "$klasse hat keine Methode $methode()";
        }
    }
}
$fehler += melde( 'Shortcode-Registry', $shortcodes, count( array_unique( $m[1] ) ) . ' Shortcodes vollständig' );

// ---------------------------------------------------------------------
// 5. Übersetzungen im Frontend-Skript
//
// Die Terminliste hält ihre Texte in einem JavaScript-Objekt T. Ein dort
// fehlender Schlüssel erscheint im Browser als "undefined".
// ---------------------------------------------------------------------
$shortcode = file_get_contents( $root . '/includes/Frontend/EventsShortcode.php' );
$texte     = [];
if ( preg_match( '/var T = \{(.*?)\n            \};/s', $shortcode, $m ) ) {
    preg_match_all( '/^\s*(\w+)\s*:/m', $m[1], $d );
    preg_match_all( '/\bT\.(\w+)\b/', $shortcode, $v );

    foreach ( array_diff( array_unique( $v[1] ), $d[1] ) as $key ) {
        $texte[] = "T.$key wird verwendet, ist aber nicht definiert";
    }
    foreach ( array_diff( $d[1], array_unique( $v[1] ) ) as $key ) {
        $texte[] = "T.$key ist definiert, wird aber nicht verwendet";
    }
    $anzahl = count( $d[1] );
} else {
    $texte[] = 'Das Textobjekt T konnte nicht gefunden werden';
    $anzahl  = 0;
}
$fehler += melde( 'Frontend-Texte', $texte, "$anzahl Schlüssel stimmig" );

// ---------------------------------------------------------------------
// 6. Einstellungen: gelesene Schlüssel müssen einen Standardwert haben
// ---------------------------------------------------------------------
$settings = file_get_contents( $root . '/includes/Admin/Settings.php' );
preg_match( '/function defaults\(\).*?return \[(.*?)\n        \];/s', $settings, $m );
preg_match_all( "/'([\w]+)'\s*=>/", $m[1] ?? '', $d );
preg_match_all( "/Settings::get\(\s*'([\w]+)'/", $quelle, $v );

$optionen = [];
foreach ( array_diff( array_unique( $v[1] ), $d[1] ) as $key ) {
    $optionen[] = "'$key' wird gelesen, hat aber keinen Standardwert";
}
$fehler += melde( 'Einstellungen', $optionen, count( $d[1] ) . ' Schlüssel mit Standardwert' );

// ---------------------------------------------------------------------
// 7. Nur aufgenommene Personen bekommen ein WordPress-Profil
//
// Der ganze Sinn von vp_people: Wer nur zusagt oder auf Freigabe wartet,
// soll nicht in der Nutzerverwaltung auftauchen. Ein wp_insert_user() an
// falscher Stelle macht das leise wieder zunichte.
// ---------------------------------------------------------------------
$erlaubt = [ 'includes/Members/MemberService.php' ];
$profile = [];

foreach ( $dateien as $datei ) {
    $relativ = str_replace( $root . '/', '', $datei );
    if ( in_array( $relativ, $erlaubt, true ) ) {
        continue;
    }
    if ( str_contains( (string) file_get_contents( $datei ), 'wp_insert_user(' ) ) {
        $profile[] = "$relativ legt einen WordPress-Nutzer an – erlaubt ist das nur in " . implode( ', ', $erlaubt );
    }
}

// Und in MemberService nur dort, wo tatsächlich aufgenommen wird.
$service = file_get_contents( $root . '/includes/Members/MemberService.php' );
$treffer = preg_match_all( '/wp_insert_user\(/', $service );
if ( $treffer > 2 ) {
    $profile[] = "MemberService ruft wp_insert_user() {$treffer}-mal auf, erwartet werden zwei (Aufnahme und Anlage durch Admin)";
}

$fehler += melde( 'Personen ohne Profil', $profile, 'WordPress-Nutzer entstehen nur bei der Aufnahme' );

// ---------------------------------------------------------------------
// 8. Versionsnummer: Schemaänderungen greifen nur bei erhöhter Version
// ---------------------------------------------------------------------
$haupt = file_get_contents( $root . '/vereins-plugin.php' );
preg_match( "/define\(\s*'VP_VERSION',\s*'([^']+)'/", $haupt, $a );
preg_match( '/Version:\s*([\d.]+)/', $haupt, $b );

$version = [];
if ( ( $a[1] ?? '' ) !== ( $b[1] ?? '' ) ) {
    $version[] = "Kopfzeile sagt {$b[1]}, VP_VERSION sagt {$a[1]}";
}
$fehler += melde( 'Version', $version, 'Kopfzeile und VP_VERSION stimmen überein (' . ( $a[1] ?? '?' ) . ')' );

echo str_repeat( '-', 70 ), "\n";
if ( $fehler ) {
    echo "$fehler Beanstandung" . ( $fehler === 1 ? '' : 'en' ) . "\n";
    exit( 1 );
}
echo "Alles in Ordnung.\n";
exit( 0 );
