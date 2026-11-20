<?php

namespace VereinsPlugin\Polls;

use VereinsPlugin\Admin\Settings;
use VereinsPlugin\Members\MemberService;
use VereinsPlugin\Members\Person;

/**
 * Mails an die Melder einer Wettkampfmeldung.
 *
 *   'sent'      – Meldung an den Veranstalter abgeschickt
 *   'confirmed' – Meldung vom Veranstalter bestätigt
 *
 * Betreff und Text stehen unter Verein → Einstellungen → „Wettkampfmeldung:
 * E-Mails an die Melder“. Platzhalter: {name} (Melder), {wettkampf},
 * {datum}, {starter} (Liste der eigenen Starter mit Klasse und Gewicht),
 * {verein}, {absender} (wer im Backend auf den Knopf gedrückt hat).
 *
 * Jeder Melder bekommt nur seine eigenen Starter. Wer keine E-Mail hat
 * (Gast nur mit Telefon, Mitglied mit Ersatzadresse), wird übersprungen
 * und gezählt – das Orgateam muss dann anrufen.
 */
class EntryMail {

    public const PLACEHOLDERS = [ '{name}', '{wettkampf}', '{datum}', '{starter}', '{verein}', '{absender}' ];

    /** Vorgaben – stehen in Admin\Settings, damit die Basis das Modul nicht kennen muss. */
    public static function defaults(): array {
        return array_intersect_key( Settings::defaults(), array_flip( [
            'entry_mail_sent_subject', 'entry_mail_sent_body', 'entry_mail_confirmed_subject', 'entry_mail_confirmed_body',
            'entry_mail_withdrawn_subject', 'entry_mail_withdrawn_body',
        ] ) );
    }

    /**
     * Wer bekäme welche Mail? Für die Vorschau im Backend und fürs Verschicken.
     *
     * Die Mail nennt nur Starter, die beim Veranstalter gemeldet (abgehakt)
     * sind; Melder ohne solche Starter bekommen keine. Mit $nur_gemeldet =
     * false zählen alle Starter – für eine Beispiel-Vorschau vor dem Abhaken.
     *
     * @param int[] $melder Personen-IDs; leer = alle Melder dieser Meldung
     * @return array<int, array{name:string, email:string, subject:string, body:string, starters:string[]}>
     *         email = '' bei Platzhalter-Adresse oder ohne Adresse
     */
    public static function plan( array $poll, string $art, array $melder = [], string $absender = '', bool $nur_gemeldet = true, array $dazu = [] ): array {
        $art     = in_array( $art, [ 'confirmed', 'withdrawn' ], true ) ? $art : 'sent';
        $betreff = (string) Settings::get( "entry_mail_{$art}_subject", self::defaults()[ "entry_mail_{$art}_subject" ] );
        $text    = (string) Settings::get( "entry_mail_{$art}_body", self::defaults()[ "entry_mail_{$art}_body" ] );

        $je_melder = [];
        // Abmeldung: die beim Veranstalter abgemeldeten Starter nennen
        $model  = new PollModel();
        $zeilen = $art === 'withdrawn' ? $model->withdrawn_entries( (int) $poll['id'] ) : $model->entries( (int) $poll['id'] );
        foreach ( $zeilen as $e ) {
            // „bestätigt“ nennt nur Bestätigte – bzw. die, die gerade bestätigt werden ($dazu)
            $passt = match ( $art ) {
                'withdrawn' => ! empty( $e['withdraw_done_at'] ),
                'confirmed' => ! empty( $e['confirmed_at'] ) || in_array( (int) $e['id'], $dazu, true ),
                default     => ! empty( $e['submitted_at'] ),
            };
            if ( ! $nur_gemeldet || $passt ) {
                $je_melder[ (int) $e['person_id'] ][] = $e;
            }
        }
        if ( $melder ) {
            $je_melder = array_intersect_key( $je_melder, array_flip( $melder ) );
        }

        $plan = [];
        foreach ( $je_melder as $pid => $starter ) {
            $p     = Person::find( $pid );
            $name  = (string) ( $p['name'] ?? __( 'Unbekannt', 'vereins-plugin' ) );
            $mail  = (string) ( $p['email'] ?? '' );
            $werte = self::values( $poll, $name, $starter, $absender );
            $plan[ $pid ] = [
                'name'    => $name,
                'email'   => $p && ! MemberService::is_placeholder_email( $mail ) && is_email( $mail ) ? $mail : '',
                'subject' => self::fill( $betreff, $werte ),
                'body'    => self::fill( $text, $werte ),
                'starters' => array_map( static fn( $e ) => (string) $e['first_name'], $starter ),
            ];
        }
        return $plan;
    }

    /**
     * Mails verschicken.
     *
     * @param int[] $melder Personen-IDs; leer = alle Melder dieser Meldung
     * @return array{sent:int, skipped:string[]} Anzahl verschickter Mails, Namen ohne E-Mail
     */
    public static function send( array $poll, string $art, array $melder = [], string $absender = '' ): array {
        $gesendet = 0;
        $ohne     = [];
        foreach ( self::plan( $poll, $art, $melder, $absender ) as $m ) {
            if ( $m['email'] !== '' && wp_mail( $m['email'], $m['subject'], $m['body'] ) ) {
                $gesendet++;
            } else {
                $ohne[] = $m['name'];
            }
        }
        return [ 'sent' => $gesendet, 'skipped' => $ohne ];
    }

    /** Platzhalter füllen. */
    public static function fill( string $vorlage, array $werte ): string {
        return strtr( $vorlage, $werte );
    }

    /** @param array $starter Zeilen aus vp_poll_entries */
    public static function values( array $poll, string $name, array $starter, string $absender = '' ): array {
        $liste = array_map( static function ( $e ) {
            $gew = $e['weight'] !== '' ? ClassTemplates::weight_label( (string) $e['weight'] ) : __( 'gewichtsnah', 'vereins-plugin' );
            return '– ' . $e['first_name'] . ' ' . $e['last_name'] . ' (' . $e['birth_year'] . '), ' . $e['class_key'] . ' ' . $e['sex'] . ', ' . $gew;
        }, $starter );
        return [
            '{name}'      => $name,
            '{wettkampf}' => (string) $poll['title'],
            '{datum}'     => ! empty( $poll['event_date'] ) ? mysql2date( 'd.m.Y', $poll['event_date'] ) : '',
            '{starter}'   => implode( "\n", $liste ),
            '{verein}'    => (string) ( Settings::get( 'club_name', '' ) ?: get_bloginfo( 'name' ) ),
            '{absender}'  => $absender,
        ];
    }
}
