<?php

namespace VereinsPlugin\Core;

/**
 * Aufbau des Plugins: eine gemeinsame Basis und darauf aufsetzende Module.
 *
 * Die Basis gehört allen Modulen gemeinsam – wer ein Modul baut, nutzt sie,
 * statt sie nachzubauen. Module dagegen kennen einander nicht: Termine
 * greifen nicht auf Umfragen zu und umgekehrt. Eine Verbindung zwischen
 * ihnen (etwa „aus einer Terminumfrage einen Termin anlegen“) läuft über die
 * öffentlichen Klassen des anderen Moduls, nie über dessen Tabellen.
 *
 * Diese Liste ist die Quelle für die Übersicht im Backend und für
 * tools/check.php, das prüft, dass kein Modul in ein anderes hineingreift.
 */
class Modules {

    /**
     * Gemeinsame Basis: Personen, Anmeldung, Rollen, Werkzeuge.
     * Namensräume unter VereinsPlugin\, die zur Basis zählen.
     */
    public const BASIS = [
        'label'       => 'Basis',
        'title'       => 'Mitglieder & Anmeldung',
        'description' => 'Gemeinsame Grundlage aller Module: Mitglieder und Gäste (Personen), Anmeldung mit Passwort oder Zugangscode, Wiedererkennung von Gästen, Rollen, Mailversand, Datenschutz-Anbindung und Updates.',
        'namespaces'  => [ 'Core', 'Database', 'Members', 'Auth', 'Privacy', 'Support', 'Api' ],
    ];

    /**
     * Module, die auf der Basis aufsetzen. Schlüssel = Kennung.
     */
    public const MODULES = [
        'termine' => [
            'label'       => 'Termine',
            'description' => 'Terminkalender mit Zu- und Absagen, Mitbringliste, Helferaufgaben, Fotos, Banner und Flyern.',
            'namespaces'  => [ 'Events', 'Photos', 'Flyer' ],
            'menu'        => 'vp-events',
        ],
        'umfragen' => [
            'label'       => 'Umfragen',
            'description' => 'Terminumfragen, Abstimmungen und Nominierungen – Teilnahme auch ohne Anmeldung.',
            'namespaces'  => [ 'Polls' ],
            'menu'        => 'vp-polls',
        ],
    ];

    /**
     * Zu welchem Modul gehört ein Namensraum (erste Ebene unter VereinsPlugin\)?
     * 'basis' für die Basis, null für Admin/Frontend (Oberflächen, die mehrere
     * Module bedienen und nach Klassen zugeordnet werden).
     */
    public static function of_namespace( string $first ): ?string {
        if ( in_array( $first, self::BASIS['namespaces'], true ) ) {
            return 'basis';
        }
        foreach ( self::MODULES as $key => $modul ) {
            if ( in_array( $first, $modul['namespaces'], true ) ) {
                return $key;
            }
        }
        return null;
    }
}
