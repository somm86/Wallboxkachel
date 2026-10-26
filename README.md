# Wallbox-Kachel für Symcon

Responsive HTMLBox für die Kachelvisualisierung, passend zum PVWallboxManager aus den bereitgestellten Screenshots. Benötigt Symcon ab Version 7.0 mit PHP-Modulunterstützung. Keine externen JavaScript-Bibliotheken, Fonts oder Cloud-Dienste im Betrieb.

## Installation

1. Diese Bibliothek nach GitHub übertragen und im Symcon-Modul-Control über ihre Repository-URL hinzufügen. Alternativ die Dateien als lokale Bibliothek in das `modules`-Verzeichnis deiner Symcon-Installation kopieren; `library.json` liegt im Bibliothekswurzelverzeichnis.
2. Eine Instanz **Wallbox Kachel** erstellen. Die Variablen aus deinen Bildern sind vorausgewählt; alle IDs in der Instanzkonfiguration kontrollieren.
3. Falls die Visualisierung einen anderen Ursprung als der Symcon-Webserver verwendet, unter **Symcon-Webserver URL** dessen vom Anzeigegerät erreichbare HTTPS-Adresse eintragen. Bei gleichem Ursprung kann das Feld leer bleiben. Bei Fernzugriff muss dieser Webserver über deinen vorhandenen gesicherten Zugang erreichbar sein. Ein relativer Hook-Pfad funktioniert nicht automatisch über jeden Symcon-Connect- oder App-Zugang.
4. Änderungen übernehmen. Die untergeordnete Stringvariable **Wallbox** mit Profil `~HTMLBox` zur Kachelvisualisierung hinzufügen und als HTMLBox darstellen. Breite und Höhe dort einstellen.
5. Für die Verlaufsdiagramme im Archiv-Control die Aufzeichnung von **50177**, **18090** und **31864** aktivieren. Vorhandene Daten werden verwendet; das Modul verändert deine Archivkonfiguration nicht.

Die Kachel passt sich der verfügbaren Breite an. Bei kleinen Höhen bleibt der Inhalt innerhalb der Kachel scrollbar. Alle Werte bleiben zugänglich; eine Mindesthöhe von 280 px verhindert unbedienbar kleine Inhalte. Die tatsächliche HTMLBox-Höhe hängt auch von den Einstellungen deiner Symcon-Visualisierung ab.

## Anzeigen und Bedienung

- Ladeleistung in kW, geladene Energie in kWh, PV-Überschuss und Hausverbrauch ohne Wallbox.
- Status, Wallboxmodus, maximaler Ladestrom, Fehlercode, Zielzeit, Soll-/Ist-Phasen und Ladezeit.
- Ladefreigabe, Lademodus, PV-Anteil, manueller Ladestrom, manuelle Phasen, Netzbegrenzung, maximale Netzbelastung und Modul aktiv.
- 24 Stunden Leistungsverlauf, mit stündlichen Archiv-Mittelwerten für Wallbox/PV/Haus. Keine Rohdatenabschneidung bei hoher Aufzeichnungsfrequenz.

Die aktuellen Werte werden alle 5 Sekunden geladen, die Diagramme jede Minute. Die Browser-Zeitzone bestimmt die Beschriftung der Zeitachsen. Es wird keine Zielzeit neu berechnet und keine Wallboxregelung ersetzt.

**Steuerung:** Das Modul ruft ausschließlich `RequestAction` für die acht vorgesehenen, konfigurierten Steuervariablen auf. Es verwendet deren tatsächliche Profil-Assoziationen, Min-/Max-Werte und Schrittweiten. Die Moduswerte aus der Demo sind nur Beispieldaten. Ohne Variablenaktion bleibt ein Feld schreibgeschützt; ohne passendes numerisches Profil erscheint ein Hinweis. Ein angenommener Befehl bestätigt keine physische Zustandsänderung der Wallbox; die Folgewertabfragen zeigen den Zustand der Symcon-Variablen.

Die String- und Status-HTML-Variablen werden nicht als fremder HTML-Code ausgeführt. Textausgaben werden mit DOM-Textmethoden gesetzt.

## Variablenzuordnung

| Feld | Standard-ID | Verwendung |
|---|---:|---|
| Status | 56865 | Anzeige |
| Wallbox Modus | 13130 | Anzeige |
| Ladeleistung | 50177 | Anzeige, Archiv |
| Max. Ladestrom | 51121 | Anzeige |
| Ladefreigabe | 16098 | Aktion, sofern vorhanden |
| Geladene Energie | 52260 | Anzeige |
| Fehlercode | 54825 | Anzeige |
| PV-Überschuss W | 18090 | Anzeige, Archiv |
| Hausverbrauch ohne Wallbox | 31864 | Anzeige, Archiv |
| Zielzeit | 56785 | Formatierte Anzeige |
| Lademodus | 25784 | Aktion |
| PV-Anteil | 55870 | Aktion |
| Ampere manuell | 12961 | Aktion |
| Phasen manuell | 11959 | Aktion |
| Netzbegrenzung aktiv | 52763 | Aktion |
| Max. Netzbelastung | 30984 | Aktion |
| Phasen Soll | 59374 | Anzeige |
| Phasen Ist | 41916 | Anzeige |
| Ladezeit | 50934 | Anzeige |
| Modul aktiv | 23338 | Aktion |

## Zugang

Jede Instanz registriert `/hook/wallboxkachel-INSTANZID`. Der HTML-Rahmen ist öffentlich abrufbar; Werte, Archiv und Aktionen erfordern einen zufälligen 256-Bit-Schlüssel. Er steht ausschließlich in der zugriffsgeschützten HTMLBox als URL-Fragment und wird danach in der Tab-Session gespeichert. API-Aufrufe senden ihn in einem Header. Er erscheint nicht als Queryparameter in Webserver-Zugriffslogs. Die API gibt keine CORS-Freigabe und akzeptiert ausschließlich authentifizierte POST-Anfragen.

Wer die HTMLBox lesen kann, kann mit ihrem Schlüssel die konfigurierten Aktionen ausführen. Gib die Variable deshalb nur berechtigten Nutzern frei und verwende bei Fernzugriff HTTPS. Kein zusätzlicher offener Router-Port ist für die Entwicklung nötig. Bei Transport über HTTP ist der Schlüssel nicht gegen Mitlesen geschützt.

## Entwicklung und Tests

Der vorhandene Checkout ist bereits isoliert; kein zusätzliches Git-Worktree erforderlich.

Demovorschau: Im Repository `python3 -m http.server 8080 --bind 127.0.0.1` starten, dann lokal `/WallboxTile/tile.html?demo` öffnen. **Ausschließlich Beispieldaten**, keine Symcon-Verbindung. Die Demo verändert keine realen Variablen.

Backend: `php -n tests/module-test.php` und `php -n -l WallboxTile/module.php`. Die Tests prüfen Aktionen, Grenzen, Profile, fehlende Variablen, Archivsortierung und Modulfreigabe mit Symcon-Stubs.

Browser: Playwright außerhalb des Checkouts installieren, z.B. `npm install --prefix /tmp/wallbox-tools --cache /tmp/wallbox-npm-cache playwright`. Mit laufendem Demoserver `NODE_PATH=/tmp/wallbox-tools/node_modules node tests/browser-test.cjs` ausführen. Verwendet `/usr/bin/chromium`, alternativ `CHROMIUM_PATH` setzen. Prüft vier Größen, Moduswechsel, Freigabe, Diagramme und fehlenden Zugriffsschlüssel. Screenshots werden nach `/tmp` geschrieben.

PHP-Syntaxprüfung, Backend-Vertragstests und Chromium-Demotests wurden in der Cloud ausgeführt. Eine reale Symcon-Instanz samt Wallbox ist hier nicht angebunden. Installation, HTMLBox-Einbettung, Webserver-Zugriff, reale Profile und physische Schaltaktionen müssen dort abschließend geprüft werden. Die Tests schalten keine reale Wallbox.
