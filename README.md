# Wallbox-Kachel für Symcon

Native PHP-Modulkachel für die Symcon-Kachelvisualisierung, passend zum PVWallboxManager. **Ab Version 2.0 wird Symcon 8.0 oder neuer benötigt.** PHP liefert die Kachel über `GetVisualizationTile()`; Darstellung und Bedienung laufen als HTML/CSS/JavaScript innerhalb der nativen Visualisierung. Keine externen JavaScript-Bibliotheken, Fonts oder Cloud-Dienste im Betrieb.

## Installation

1. Im Symcon-Modul-Control die Bibliothek `https://github.com/somm86/Wallboxkachel` hinzufügen oder aktualisieren.
2. Eine Instanz **Wallbox Kachel** erstellen oder die bestehende Instanz öffnen. Alle vorausgewählten Variablen-IDs kontrollieren und Änderungen übernehmen.
3. **Die Instanz selbst** zur Symcon-Kachelvisualisierung hinzufügen. Die Instanz liefert ihre eigene Kacheldarstellung. Breite und Höhe dort einstellen.
4. Für die Verlaufsdiagramme im Archiv-Control die Aufzeichnung von **50177**, **18090**, **31864** und für den Netzverlauf **13288** aktivieren. Vorhandene Daten werden verwendet; das Modul verändert deine Archivkonfiguration nicht.

Beim Update von Version 1.x bleiben die Variablenzuordnungen erhalten. Nach dem Übernehmen der Änderungen die bisherige HTMLBox in der Visualisierung durch die Instanz ersetzen. Die alte HTMLBox-Variable wird mit einem Umstellungshinweis erhalten; sie wird nicht automatisch gelöscht. Der vom Modul registrierte alte Webhook wird entfernt, fremde Webhooks bleiben erhalten. BaseURL und Zugriffsschlüssel werden nicht mehr verwendet. Es sind weder eine HTMLBox noch ein zusätzlicher Webserver-Endpunkt erforderlich.

Die Energieübersicht gruppiert Wallbox, Haus/PV, Speicher und E-Auto in vier Bereichen; auf mittleren Breiten werden daraus zwei Spalten. Darunter stehen Ladesteuerung sowie Betriebsdaten und Verlauf nebeneinander; auf schmalen Kacheln untereinander. Betriebsdaten und Verlauf bilden eine eigene, kompakte Spalte. Die vier Übersichtskarten sind bei mehrspaltiger Darstellung gleich hoch. Betriebsdaten und Verlauf sind zusammen bündig mit der Ladesteuerung; zusätzliche Höhe nutzt das Diagramm. Die Steuerzeilen werden nicht auseinandergezogen; Abstände bleiben auch bei hohen Kacheln begrenzt. „Modul aktiv“ bleibt der erste Schalter. Schriftgrößen, Abstände, Bedienelemente und Diagramme passen sich an Breite und Höhe der nativen Kachel an. Bei sehr kleinen Größen bleibt der Inhalt scrollbar.

## Anzeigen und Bedienung

- Haus & Sonne: Netzleistung **13288** in W mit Vorzeichen. Anklicken öffnet einen 24-Stunden-Graphen mit stündlichen Archiv-Mittelwerten; Escape oder „Schließen“ schließt ihn. Keine Zuordnung von positiven/negativen Werten zu Bezug/Einspeisung ohne Kenntnis der Zählerkonvention.
- E-Auto: Akkustand **48784** mit Füllbalken, Restreichweite **59274** in km und Soll-Ladestand **42679** als Dropdown. Das Dropdown bietet **0 bis 100 % in Schritten von 10 %**, unabhängig vom Anzeigeprofil. Der numerische Prozentwert wird direkt übertragen und auch serverseitig auf 0 bis 100 in Zehnerschritten begrenzt. Ohne numerische Variable oder Variablenaktion bleibt das Dropdown gesperrt. Änderungen werden über `RequestAction` weitergegeben; die Fahrzeuganbindung muss diese unterstützen.
- PV-Speicher-Restzeit aus **29716**, als Stunden und Minuten (z.B. `0 Std. 33 Min.`) mit voraussichtlicher Endzeit (`bis 14:25 Uhr`). Das formatierte `h:m` aus Symcon wird ausgewertet; der rohe Float wird wegen seiner unbekannten Einheit nicht umgerechnet. Die Endzeit wird aus der aktuellen Gerätezeit plus Restdauer berechnet; bei Tageswechsel erscheint „morgen“ oder das Datum. Ungültige Zeitangaben werden als „–“ angezeigt. Bei positiver Leistung „Restzeit Laden“, bei negativer „Restzeit Entladen“. Im Ruhezustand oder bei fehlenden Daten erscheint „–“. Die Variable muss selbst die zur aktuellen Richtung passende Restzeit liefern; es wird keine separate Ladezeit berechnet.
- PV-Speicher: Ladestand (45769) mit Füllbalken und Leistung (18192) in W. Positive Leistung bedeutet Laden, negative Leistung Entladen, 0 W Ruhe. Fehlende Werte werden als solche angezeigt.
- Ladeleistung in kW, geladene Energie in kWh, PV-Überschuss und Hausverbrauch ohne Wallbox.
- Status, Wallboxmodus, maximaler Ladestrom, Fehlercode, Zielzeit, Soll-/Ist-Phasen und Ladezeit.
- Ladefreigabe, Lademodus, PV-Anteil, manueller Ladestrom, manuelle Phasen, Netzbegrenzung, maximale Netzbelastung und Modul aktiv.
- 24 Stunden Leistungsverlauf, mit stündlichen Archiv-Mittelwerten für Wallbox/PV/Haus. Keine Rohdatenabschneidung bei hoher Aufzeichnungsfrequenz.

Die sichtbare Kachel fragt aktuelle Werte ungefähr alle 5 Sekunden ab; nach einer langsamen Antwort beginnt das nächste Intervall erst nach Abschluss der Anfrage. Ausgeblendete Kacheln pausieren und aktualisieren sich beim Einblenden sofort. Diagrammdaten werden höchstens einmal pro Minute angefordert. Die Browser-Zeitzone bestimmt die Beschriftung der Zeitachsen. Es wird keine Zielzeit neu berechnet und keine Wallboxregelung ersetzt.

**Steuerung:** Das Modul ruft ausschließlich `RequestAction` für die neun vorgesehenen, konfigurierten Steuervariablen auf. Für die Wallboxsteuerung verwendet es deren tatsächliche Profil-Assoziationen, Min-/Max-Werte und Schrittweiten. Der E-Auto-Soll-Ladestand verwendet ausdrücklich 0–100 % in 10-%-Schritten. Die Moduswerte aus der Demo sind nur Beispieldaten. Ohne Variablenaktion bleibt ein Feld schreibgeschützt; ohne passendes numerisches Profil erscheint ein Hinweis. Ein angenommener Befehl bestätigt keine physische Zustandsänderung der Wallbox; die Folgewertabfragen zeigen den Zustand der Symcon-Variablen.

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
| PV Speicher Ladestand | 45769 | Anzeige in % |
| Netzleistung | 13288 | Anzeige, Verlauf beim Anklicken |
| E-Auto Akkustand | 48784 | Anzeige in % |
| E-Auto Restreichweite | 59274 | Anzeige in km |
| E-Auto Soll-Ladestand | 42679 | Dropdown, Aktion |
| PV Speicher Restzeit | 29716 | Stunden, Minuten und voraussichtliche Uhrzeit, je nach Leistung Laden/Entladen |
| PV Speicher Leistung | 18192 | Anzeige in W, + Laden / − Entladen |
| Ladezeit | 50934 | Anzeige |
| Modul aktiv | 23338 | Aktion |

## Native Anbindung und Zugang

`SetVisualizationType(1)` registriert die eigene Darstellung der PHP-Instanz. Die Kachel sendet ihre Anfragen über Symcons `requestAction()` an die Instanzmethode `RequestAction()`. Antworten laufen über `UpdateVisualizationValue()` und `handleMessage()`. Zufällige Anfragekennungen ordnen die Antwort der jeweiligen Anfrage und geöffneten Kachel zu. Fehler und ausbleibende Antworten werden sichtbar angezeigt.

Der Zugang erfolgt über die Symcon-Visualisierung und ihre Benutzerberechtigungen. Gib die Instanz nur Nutzern frei, die die zugeordneten Geräte steuern dürfen. Das Modul betreibt keine separate HTTP-API und benötigt keinen eigenen Zugriffsschlüssel. Datenabfragen und Steuerung verwenden denselben nativen Symcon-Kanal.

Die Schnittstelle folgt dem offiziellen Beispiel [Symcon/Strompreis](https://github.com/symcon/Strompreis/tree/master/PowerPrice) und den [Symcon-SDK-Stubs](https://github.com/symcon/SymconStubs).

## CPU-Optimierung

- Unveränderte Steuerfelder, Dropdowns und Betriebsdaten behalten ihre DOM-Elemente. Ein geänderter Leistungswert baut keine unabhängigen Bedienelemente neu auf.
- Zahlenformatierer werden wiederverwendet. Gleiche Texte werden nicht erneut ins DOM geschrieben. Die Speicher-Endzeit wird bei Wertänderungen oder im nächsten Minutenwechsel aktualisiert.
- Diagramme werden nur bei geänderten Daten, Abmessungen oder im nächsten Minutenwechsel neu gezeichnet. Größenmeldungen werden pro Browser-Frame zusammengefasst.
- Gleichzeitige Wertabfragen und Archivanforderungen werden im Browser zusammengefasst. Der PHP-Archivcache gilt 60 Sekunden für alle geöffneten Kacheln dieser Instanz; neue Variablenzuordnungen oder übernommene Konfigurationsänderungen verwerfen ihn. Änderungen der Archivierung können entsprechend bis zu einer Minute benötigen.
- Für reine Anzeigen entfallen zusätzliche Profil-Metadatenabfragen und deren Übertragung. Formatierte Werte bleiben erhalten.

Der Performance-Test prüft vermiedene Arbeit: zehn unveränderte Abfragen ohne DOM-Änderungen an Steuerung/Dropdown/Betriebsdaten/Diagramm, jeweils nur eine gleichzeitige Anfrage und keine neuen Abfragen bei ausgeblendeter Kachel. Er misst keine CPU-Prozentwerte auf einer echten Symcon-Installation.

## Entwicklung und Tests

Der vorhandene Checkout ist bereits isoliert; kein zusätzliches Git-Worktree erforderlich.

Demovorschau: Im Repository `python3 -m http.server 8080 --bind 127.0.0.1` starten, dann lokal `/WallboxTile/tile.html?demo` öffnen. **Ausschließlich Beispieldaten**, keine Symcon-Verbindung. Die Demo verändert keine realen Variablen.

Performance-Prüfung (mit laufendem Demoserver und Playwright): `NODE_PATH=/tmp/wallbox-tools/node_modules node tests/performance-test.cjs`.

Restzeit-Prüfung: `node tests/battery-time-test.cjs` prüft Stunden/Minuten, Endzeit, Tageswechsel und fehlende/ungültige Werte.

Backend: `php -n tests/module-test.php` und `php -n -l WallboxTile/module.php`. Die Tests prüfen Aktionen, Grenzen, Profile, fehlende Variablen, Archivsortierung, Netzverlauf, Fahrzeugwerte/Sollwertaktion, Speicherwerte und Modulfreigabe mit Symcon-Stubs. Zusätzlich werden die native Visualisierungsregistrierung, Anfragen/Antworten und die wiederholbare Migration alter Webhooks geprüft.

Browser: Playwright außerhalb des Checkouts installieren, z.B. `npm install --prefix /tmp/wallbox-tools --cache /tmp/wallbox-npm-cache playwright`. Mit laufendem Demoserver `NODE_PATH=/tmp/wallbox-tools/node_modules node tests/browser-test.cjs` ausführen. Verwendet `/usr/bin/chromium`, alternativ `CHROMIUM_PATH` setzen. Prüft vier Größen und Höhenanpassung bei 500/900/1400 px, E-Auto-Anzeigen/Sollwert-Dropdown und Netzverlauf-Dialog, Speicher-Laden/Entladen/Ruhe/fehlende Daten, Ladestand, Moduswechsel, Freigabe, Diagramme sowie die native Nachrichtenbrücke, Sollwertaktionen, Fehlerantworten und fremde Antwortkennungen mit einer simulierten Symcon-Brücke. Screenshots werden nach `/tmp` geschrieben.

PHP-Syntaxprüfung, Backend-Vertragstests und Chromium-Tests wurden in der Cloud ausgeführt. Die Browserprüfungen simulieren den nativen Symcon-Kanal; eine reale Symcon-8-Instanz samt Wallbox ist hier nicht angebunden. Die Einbettung der Instanz, Benutzerberechtigungen, Profile und physischen Schaltaktionen müssen dort abschließend geprüft werden. Die Tests schalten keine reale Wallbox.
