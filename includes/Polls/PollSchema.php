<?php

namespace VereinsPlugin\Polls;

/**
 * Tabellen des Moduls Umfragen.
 *
 * Eigene Tabellen mit eigenem Präfix vp_poll…, damit das Modul vom Modul
 * Termine sichtbar getrennt bleibt. Die Personen-ID folgt der Basis-
 * Verabredung (positiv = Mitglied, negativ = Gast aus vp_people).
 */
class PollSchema {

    /** @return string[] CREATE-Anweisungen für dbDelta */
    public static function tables( string $prefix, string $charset ): array {
        // --- vp_polls --------------------------------------------------------
        // kind:    'date'   – Terminumfrage (passt / vielleicht / passt nicht)
        //          'choice' – Abstimmung oder Nominierung (Auswahl)
        // results: wann andere das Ergebnis sehen –
        //          'always' sofort, 'after_vote' nach eigener Abstimmung,
        //          'after_close' erst nach Ende, 'orga' nur Orgateam.
        $polls = "CREATE TABLE {$prefix}vp_polls (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind            ENUM('date','choice') NOT NULL DEFAULT 'choice',
            title           VARCHAR(255)    NOT NULL,
            description     TEXT            DEFAULT NULL,
            status          ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
            closes_at       DATETIME        DEFAULT NULL,
            multiple        TINYINT(1)      NOT NULL DEFAULT 0,
            allow_maybe     TINYINT(1)      NOT NULL DEFAULT 1,
            anonymous       TINYINT(1)      NOT NULL DEFAULT 0,
            results         ENUM('always','after_vote','after_close','orga') NOT NULL DEFAULT 'after_vote',
            login_required  TINYINT(1)      NOT NULL DEFAULT 0,
            created_by      BIGINT          NOT NULL DEFAULT 0,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY          status (status)
        ) $charset;";

        // --- vp_poll_options -------------------------------------------------
        // Bei Terminumfragen tragen starts_at/ends_at den Vorschlag; label ist
        // dann ein optionaler Zusatz („mit Grillen“).
        $options = "CREATE TABLE {$prefix}vp_poll_options (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id     BIGINT UNSIGNED NOT NULL,
            label       VARCHAR(255)    NOT NULL DEFAULT '',
            starts_at   DATETIME        DEFAULT NULL,
            ends_at     DATETIME        DEFAULT NULL,
            sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY          poll_id (poll_id)
        ) $charset;";

        // --- vp_poll_votes ---------------------------------------------------
        // Eine Zeile je Person und Antwortmöglichkeit. Auch bei geheimen
        // Umfragen wird die Person gespeichert – sonst ließe sich nicht
        // verhindern, dass jemand mehrfach abstimmt. Angezeigt wird sie dann
        // niemandem, auch dem Orgateam nicht.
        $votes = "CREATE TABLE {$prefix}vp_poll_votes (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id     BIGINT UNSIGNED NOT NULL,
            option_id   BIGINT UNSIGNED NOT NULL,
            person_id   BIGINT          NOT NULL,
            value       ENUM('yes','maybe','no') NOT NULL DEFAULT 'yes',
            voted_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   option_person (option_id, person_id),
            KEY          poll_person (poll_id, person_id)
        ) $charset;";

        return [ $polls, $options, $votes ];
    }
}
