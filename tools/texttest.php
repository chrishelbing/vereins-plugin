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

Pruefung::abschluss( 'Beschreibung mit Links' );
