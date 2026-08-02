<?php

namespace VereinsPlugin\Admin;

/**
 * Kleine Hilfen für die Formulare im Backend.
 *
 * Passwortverwaltungen ins Leere laufen lassen
 * --------------------------------------------
 * 1Password, LastPass und Bitwarden erkennen Anmelde- und Registrierungs-
 * formulare nicht am Namen der Felder, sondern am Muster: ein Textfeld für
 * einen Namen, daneben eines vom Typ `email` – fertig ist der Verdacht auf
 * eine Registrierung. Genau so sieht aber „Mitglied anlegen“ aus, und ebenso
 * die Formulare für Vorlagen und Termintypen. Die Folge war ein Angebot zum
 * Speichern eines Zugangs, wo niemand einen Zugang anlegt.
 *
 * Ein Umbenennen der Felder hilft dagegen nicht – das Muster bleibt. Und
 * `autocomplete="off"` allein reicht ebenfalls nicht: Die Erweiterungen
 * ignorieren es bewusst, weil zu viele Seiten es fälschlich setzen. Was sie
 * beachten, sind ihre eigenen Merkmale, und die stehen hier gebündelt an
 * einer Stelle:
 *
 *   data-1p-ignore  – 1Password
 *   data-lpignore   – LastPass
 *   data-bwignore   – Bitwarden
 *
 * Die Merkmale müssen serverseitig im Markup stehen. Sie später per Skript
 * nachzutragen käme zu spät: Die Erweiterungen sehen sich die Seite an,
 * sobald sie geladen ist.
 *
 * Bewusst NICHT gesetzt wird das an Feldern, bei denen ein Vorschlag des
 * Browsers hilft – etwa der Absender-E-Mail in den Einstellungen. Dort geht
 * es tatsächlich um eine Adresse, die man schon einmal getippt hat.
 */
class AdminForms {

    /**
     * Merkmale für ein einzelnes Feld. Als Konstante, damit sie sich im
     * Markup direkt hinter den Feldtyp echoen lässt.
     */
    public const NO_AUTOFILL = 'autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore="true"';

    /**
     * Merkmale für das ganze Formular.
     */
    public const NO_AUTOFILL_FORM = 'autocomplete="off" data-1p-ignore';
}
