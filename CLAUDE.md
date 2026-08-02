# Vereins-Plugin – Hinweise für die Arbeit an diesem Plugin

## Zuerst lesen: FEATURES.md

`FEATURES.md` beschreibt den Funktionsumfang zusammenhängend – Personenstufen,
Termine, Zusagen, Mitbringliste, Fotos, Flyer, Rollen, dazu die bewussten
Grenzen. Vor einer Änderung dort nachsehen, was es schon gibt und warum es so
gebaut ist.

**Die Datei ist bei jeder Änderung am Funktionsumfang mitzuführen.** Eine
neue, geänderte oder entfernte Funktion gehört dort hinein, bevor die Arbeit
als erledigt gilt. Eine veraltete Übersicht ist schlimmer als keine, weil man
ihr noch glaubt.

## Vor jedem Abschluss: Prüfungen laufen lassen

```
php tools/pruefen.php
```

Das ist verbindlich, nicht optional. Der Befehl startet fünf Prüfungen und
liefert Rückgabewert 1, sobald eine anschlägt. Läuft `exec()` in der
Umgebung nicht, sind die fünf Dateien einzeln aufzurufen.

| Datei | prüft |
|---|---|
| `tools/check.php` | Syntax, Autoloader-Pfade, Symbole, Shortcode-Registry, Frontend-Texte, Einstellungen, Versionsnummer, „keine WordPress-Profile für Unbestätigte" |
| `tools/zugriffstest.php` | Wer sich anmelden darf, wer in den Mitgliederbereich kommt, wer ins Backend – dazu Aussperrschutz und die Kopplung von Status und Vereinsrolle |
| `tools/personentest.php` | Der Weg einer Person: Zusage über Terminlink, Wiedererkennung, Bestätigung, Aufnahme, Ablehnung, Übernahme von Altbeständen |
| `tools/startdatentest.php` | Startdaten: einmalig bei der Aktivierung, Nachlegen auf Anforderung ohne Dubletten, Wirksamkeit der Vorlagen am Termin |
| `tools/qrtest.php` | Der selbstgebaute QR-Encoder gegen hinterlegte Sollwerte |

Neue Fachlogik gehört mit einem Fall in die passende Testdatei. Die Tests
laufen gegen `tools/lib/wp-stub.php`, einen kleinen WordPress-Ersatz im
Speicher – ohne Datenbank, ohne Installation.

**Wenn ein Test etwas Überraschendes meldet, zuerst den Ersatz verdächtigen.**
Er ist absichtlich naiv, und zwei Fehlalarme kamen schon von ihm: Einmal
wurde `WHERE m.status = …` wegen des Tabellenkürzels stillschweigend
ignoriert, einmal fraß die Kürzelbereinigung das `example.` in E-Mail-Adressen.

## Was die Tests nicht abdecken

Ehrlich benannt, damit niemand sich in Sicherheit wiegt:

- **Alles im Browser.** Die Anordnung auf der Flyer-Leinwand, die Reihenfolge
  beim Laden des Medienmanagers, das Verhalten der Overlays. Das wurde
  einmalig mit eigenen Werkzeugen geprüft, ist aber nicht dauerhaft abgesichert.
- **Echte Datenbank.** Die Migrationen in `Database\Schema` – Umbenennen von
  Spalten, Wechsel auf vorzeichenbehaftete Zahlen – laufen im Test nicht mit.
- **Der QR-Nachweis selbst.** Der Test hält das Ergebnis fest, prüft aber
  nicht erneut gegen einen Decoder.

## Verabredungen im Code

- **Personen-IDs**: positiv = `wp_users`-ID eines aufgenommenen Mitglieds,
  negativ = Eintrag in `vp_people` (Gast oder wartende Registrierung).
  Aufgelöst wird ausschließlich über `Members\Person`.
- **Ein WordPress-Profil entsteht nur bei der Aufnahme.** `wp_insert_user()`
  darf nur in `Members\MemberService` vorkommen; `tools/check.php` wacht darüber.
- **Vereinsrollen werden zusätzlich vergeben**, nie ersetzend. Wer eine Rolle
  der Website trägt – Redakteur, Autor, Abonnent –, behält sie samt Backend.
  Das Plugin nimmt nie etwas weg, was die Seite schon vergeben hatte.
- **Status und Vereinsrolle laufen synchron**: Ohne Vereinsrolle ist niemand
  aktives Mitglied, und Deaktivieren nimmt die Vereinsrolle mit.
- **Rollenkennungen** stehen als Konstanten in `Core\Activator`; frühere Namen
  stehen in `ROLE_HISTORY` und werden beim Update übernommen.
- **Versionsnummer erhöhen**, sobald sich das Schema oder eine Migration
  ändert – sonst läuft `maybe_upgrade()` nicht an. Kopfzeile und `VP_VERSION`
  müssen übereinstimmen, `tools/check.php` prüft das.

## Sprache

Code-Kommentare, Oberfläche und Commit-Nachrichten auf Deutsch.
