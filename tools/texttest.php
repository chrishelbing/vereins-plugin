<?php
/**
 * Beschreibung mit Links (Support\RichText).
 *
 * Aufruf im Plugin-Ordner:   php tools/texttest.php
 *
 * Die Beschreibung schreiben Mitglieder. Was dort steht, landet auf einer
 * öffentlich erreichbaren Seite – deshalb vor allem: kein HTML durchlassen,
 * nur http(s) als Ziel.
 */

declare( strict_types=1 );

require_once __DIR__ . '/lib/wp-stub.php';
stub_load( [ 'Support/RichText.php' ] );

use VereinsPlugin\Support\RichText;

echo "\nLinks erkennen\n";
Pruefung::gleich( 'nackte Adresse',
    RichText::html( 'Infos: https://judo.de/turnier' ),
    'Infos: <a href="https://judo.de/turnier" target="_blank" rel="noopener">judo.de/turnier</a>' );
Pruefung::gleich( 'Satzzeichen gehört nicht zur Adresse',
    RichText::html( 'Siehe https://judo.de/a.pdf.' ),
    'Siehe <a href="https://judo.de/a.pdf" target="_blank" rel="noopener">judo.de/a.pdf</a>.' );
Pruefung::gleich( 'Klammer drumherum',
    RichText::html( '(https://judo.de)' ),
    '(<a href="https://judo.de" target="_blank" rel="noopener">judo.de</a>)' );
Pruefung::gleich( 'www. ohne https',
    RichText::html( 'www.bjv-online.de' ),
    '<a href="https://www.bjv-online.de" target="_blank" rel="noopener">bjv-online.de</a>' );
Pruefung::gleich( 'eigener Text',
    RichText::html( '[Ausschreibung](https://judo.de/a.pdf) lesen' ),
    '<a href="https://judo.de/a.pdf" target="_blank" rel="noopener">Ausschreibung</a> lesen' );
Pruefung::gleich( 'Zeilenumbruch bleibt',
    RichText::html( "Zeile 1\nZeile 2" ), "Zeile 1<br>\nZeile 2" );

echo "\nNichts durchlassen\n";
Pruefung::gleich( 'HTML wird maskiert',
    RichText::html( '<script>alert(1)</script>' ), '&lt;script&gt;alert(1)&lt;/script&gt;' );
Pruefung::gleich( 'javascript: ist kein Link',
    RichText::html( '[klick](javascript:alert(1))' ), '[klick](javascript:alert(1))' );
Pruefung::gleich( 'Anführungszeichen beenden die Adresse',
    RichText::html( 'https://x.de/?a="><b>' ),
    '<a href="https://x.de/?a=" target="_blank" rel="noopener">x.de/?a=</a>&quot;&gt;&lt;b&gt;' );
Pruefung::gleich( 'Text im Link maskiert',
    RichText::html( '[<b>fett</b>](https://x.de)' ),
    '<a href="https://x.de" target="_blank" rel="noopener">&lt;b&gt;fett&lt;/b&gt;</a>' );

echo "\nNur Text (Banner, Flyer)\n";
Pruefung::gleich( 'Banner: Text statt Link, Adresse weg',
    RichText::plain( 'Hier die [Ausschreibung](https://x.de/a.pdf), mehr unter https://x.de.' ),
    'Hier die Ausschreibung, mehr unter .' );
Pruefung::gleich( 'Flyer: Adresse bleibt',
    RichText::plain( 'Mehr unter https://x.de.', true ), 'Mehr unter https://x.de.' );

echo "\nTypografie (Markdown)\n";
Pruefung::gleich( 'fett',
    RichText::html( 'Bitte **pünktlich** sein' ), 'Bitte <strong>pünktlich</strong> sein' );
Pruefung::gleich( 'kursiv mit Sternchen',
    RichText::html( 'Das ist *wichtig*.' ), 'Das ist <em>wichtig</em>.' );
Pruefung::gleich( 'kursiv mit Unterstrich',
    RichText::html( 'Das ist _wichtig_.' ), 'Das ist <em>wichtig</em>.' );
Pruefung::gleich( 'fett und kursiv in einer Zeile',
    RichText::html( '**Achtung:** *neu*' ), '<strong>Achtung:</strong> <em>neu</em>' );
Pruefung::gleich( 'Gendersternchen bleibt',
    RichText::html( 'Sportler*innen und Trainer*innen' ), 'Sportler*innen und Trainer*innen' );
Pruefung::gleich( 'snake_case bleibt',
    RichText::html( 'datei_name_neu' ), 'datei_name_neu' );
Pruefung::gleich( 'Unterstrich in Adresse bleibt Adresse',
    RichText::html( 'https://x.de/_a_' ),
    '<a href="https://x.de/_a_" target="_blank" rel="noopener">x.de/_a_</a>' );
Pruefung::gleich( 'Link in fettem Text',
    RichText::html( '**Hier: [Plan](https://x.de)**' ),
    '<strong>Hier: <a href="https://x.de" target="_blank" rel="noopener">Plan</a></strong>' );
Pruefung::gleich( 'einzelnes Sternchen bleibt',
    RichText::html( '5 * 3 = 15' ), '5 * 3 = 15' );
Pruefung::gleich( 'Aufzählung',
    RichText::html( "Mitbringen:\n- Sportschuhe\n- **Getränk**\nDanke!" ),
    "Mitbringen:<ul><li>Sportschuhe</li><li><strong>Getränk</strong></li></ul>Danke!" );
Pruefung::gleich( 'Aufzählung mit * und •',
    RichText::html( "* eins\n• zwei" ), '<ul><li>eins</li><li>zwei</li></ul>' );
Pruefung::gleich( 'nummerierte Liste',
    RichText::html( "1. Ankommen\n2. Umziehen" ), '<ol><li>Ankommen</li><li>Umziehen</li></ol>' );
Pruefung::gleich( 'HTML in Listenpunkt maskiert',
    RichText::html( '- <b>x</b>' ), '<ul><li>&lt;b&gt;x&lt;/b&gt;</li></ul>' );
Pruefung::gleich( 'Banner: ohne Sternchen, Punkte als •',
    RichText::plain( "**Wichtig:** bitte\n- Schuhe" ), "Wichtig: bitte\n• Schuhe" );
Pruefung::gleich( 'Flyer: Unterstrich in Adresse bleibt',
    RichText::plain( 'Plan: https://x.de/_a_', true ), 'Plan: https://x.de/_a_' );

echo "\nBeispiele der Hilfe (Einstellungen, ? am Feld)\n";
// Jedes gezeigte Beispiel muss etwas bewirken – sonst verspricht die Hilfe
// eine Schreibweise, die es nicht gibt.
foreach ( RichText::syntax() as [ $eingabe, $bedeutung ] ) {
    $roh = nl2br( htmlspecialchars( $eingabe, ENT_QUOTES, 'UTF-8' ), false );
    Pruefung::ok( 'wirkt: ' . str_replace( "\n", ' ', $eingabe ), RichText::html( $eingabe ) !== $roh );
}

Pruefung::abschluss( 'Beschreibung mit Links' );
