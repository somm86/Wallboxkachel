<?php

declare(strict_types=1);

class WallboxTile extends IPSModule
{
    private const FIELDS = [
        'status' => [56865, 'Status'], 'wallboxMode' => [13130, 'Wallbox Modus'],
        'power' => [50177, 'Ladeleistung'], 'maxCurrent' => [51121, 'Max. Ladestrom'],
        'enabled' => [16098, 'Ladefreigabe'], 'energy' => [52260, 'Geladene Energie'],
        'error' => [54825, 'Fehlercode'], 'pv' => [18090, 'PV-Überschuss'],
        'house' => [31864, 'Hausverbrauch ohne Wallbox'], 'target' => [56785, 'Zielzeit'],
        'mode' => [25784, 'Lademodus'], 'pvShare' => [55870, 'PV-Anteil'],
        'current' => [12961, 'Ampere (manuell)'], 'phases' => [11959, 'Phasen (manuell)'],
        'gridEnabled' => [52763, 'Netzbegrenzung aktiv'], 'gridLimit' => [30984, 'Maximale Netzbelastung'],
        'desiredPhases' => [59374, 'Wallbox Phasen (Soll)'], 'actualPhases' => [41916, 'Fahrzeug Phasen (Ist)'],
        'gridPower' => [13288, 'Netzleistung'],
        'carSoc' => [48784, 'E-Auto Akkustand'],
        'carRange' => [59274, 'E-Auto Restreichweite (km)'],
        'carTarget' => [42679, 'E-Auto Soll-Ladestand'],
        'batterySoc' => [45769, 'PV Speicher Ladestand'],
        'batteryPower' => [18192, 'PV Speicher Leistung'],
        'batteryRemaining' => [29716, 'PV Speicher Restzeit (h:m)'],
        'chargeTime' => [50934, 'Ladezeit'], 'active' => [23338, 'Modul aktiv']
    ];
    private const CONTROLS = ['enabled', 'mode', 'pvShare', 'current', 'phases', 'gridEnabled', 'gridLimit', 'active', 'carTarget'];

    public function Create()
    {
        parent::Create();
        foreach (self::FIELDS as $key => $field) {
            $this->RegisterPropertyInteger($key, $field[0]);
        }
        $this->SetVisualizationType(1);
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Also activate for instances created with the old HTMLBox version.
        $this->SetVisualizationType(1);
        $this->SetBuffer('HistoryCache', '');
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->RemoveLegacyHook();
        }
        // Preserve the old variable and any visualization links; show migration help.
        $legacy = @$this->GetIDForIdent('Tile');
        if ($legacy !== false && $legacy > 0) {
            $this->SetValue('Tile', '<p>Wallbox: Bitte in der Kachelvisualisierung jetzt die Instanz „Wallbox Kachel“ statt dieser HTMLBox auswählen.</p>');
        }
        $this->SetStatus(102);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
        if ($Message === IPS_KERNELMESSAGE && $Data[0] === KR_READY) {
            $this->RemoveLegacyHook();
        }
    }

    public function GetVisualizationTile(): string
    {
        return file_get_contents(__DIR__ . '/tile.html');
    }

    // Native visualization bridge: Symcon authenticates and routes the request.
    public function RequestAction($Ident, $Value)
    {
        if ($Ident !== 'TileRequest') {
            throw new InvalidArgumentException('Unbekannte Kachelaktion');
        }
        if (!is_string($Value) || strlen($Value) > 8192) {
            throw new InvalidArgumentException('Ungültige Kachelanfrage');
        }
        $request = json_decode($Value, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($request) || !is_string($request['id'] ?? null)
            || !preg_match('/^[a-zA-Z0-9-]{1,80}$/', $request['id'])) {
            throw new InvalidArgumentException('Ungültige Anfragekennung');
        }
        $response = ['id' => $request['id']];
        try {
            switch ($request['op'] ?? '') {
                case 'state': $response['result'] = $this->Snapshot(); break;
                case 'history': $response['result'] = $this->History(); break;
                case 'action':
                    $this->Control($request['key'] ?? '', $request['value'] ?? null);
                    $response['result'] = $this->Snapshot();
                    break;
                default: throw new InvalidArgumentException('Unbekannte Anfrage');
            }
        } catch (Throwable $e) {
            $response['error'] = 'Anfrage fehlgeschlagen: ' . $e->getMessage();
        }
        $this->UpdateVisualizationValue(json_encode($response,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function GetConfigurationForm()
    {
        $elements = [
            ['type' => 'Label', 'caption' => 'Diese Instanz direkt zur Symcon-Kachelvisualisierung hinzufügen (ab Symcon 8.0). Die frühere HTMLBox wird nicht mehr benötigt.']
        ];
        foreach (self::FIELDS as $key => $field) {
            $elements[] = ['type' => 'SelectVariable', 'name' => $key, 'caption' => $field[1]];
        }
        return json_encode(['elements' => $elements, 'actions' => [], 'status' => [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv']
        ]], JSON_UNESCAPED_UNICODE);
    }

    private function RemoveLegacyHook(): void
    {
        $instances = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (!$instances) { return; }
        $id = $instances[0];
        $hooks = json_decode(IPS_GetProperty($id, 'Hooks'), true) ?: [];
        $kept = array_values(array_filter($hooks, function ($hook) {
            return !($hook['Hook'] === '/hook/wallboxkachel-' . $this->InstanceID
                && $hook['TargetID'] === $this->InstanceID);
        }));
        if (count($kept) !== count($hooks)) {
            IPS_SetProperty($id, 'Hooks', json_encode($kept));
            IPS_ApplyChanges($id);
        }
    }

    private function Field(string $key): array
    {
        $id = $this->ReadPropertyInteger($key);
        if (!$id || !IPS_VariableExists($id)) { return ['missing' => true]; }
        $variable = IPS_GetVariable($id);
        $name = $variable['VariableCustomProfile'] ?: $variable['VariableProfile'];
        $action = $variable['VariableCustomAction'] ?: $variable['VariableAction'];
        $writable = in_array($key, self::CONTROLS, true) && $action > 0;
        // Display-only fields need formatted text, not a second full profile lookup.
        $needsProfile = $writable && $variable['VariableType'] !== 0 && $key !== 'carTarget';
        $profile = $needsProfile && $name !== '' && IPS_VariableProfileExists($name) ? IPS_GetVariableProfile($name) : null;
        return ['value' => GetValue($id), 'text' => GetValueFormatted($id),
            'type' => $variable['VariableType'], 'writable' => $writable,
            'profile' => $profile ? ['min' => $profile['MinValue'], 'max' => $profile['MaxValue'],
                'step' => $profile['StepSize'], 'associations' => $profile['Associations']] : null];
    }

    public function Snapshot(): array
    {
        $result = [];
        foreach (self::FIELDS as $key => $field) { $result[$key] = $this->Field($key); }
        return ['fields' => $result, 'time' => time()];
    }

    public function Control(string $key, $value): void
    {
        if (!in_array($key, self::CONTROLS, true)) { throw new InvalidArgumentException('Aktion nicht erlaubt'); }
        $field = $this->Field($key);
        if (!($field['writable'] ?? false)) { throw new InvalidArgumentException('Variable hat keine Aktion'); }
        if ($field['type'] === 0) {
            if (!is_bool($value)) { throw new InvalidArgumentException('Boolescher Wert erwartet'); }
        } else {
            if (!is_int($value) && !is_float($value)) { throw new InvalidArgumentException('Zahl erwartet'); }
            if (!is_finite((float) $value) || ($field['type'] === 1 && floor($value) != $value)) {
                throw new InvalidArgumentException('Ungültiger Zahlenwert');
            }
            // The requested vehicle target range is explicit, independent of its display profile.
            $profile = $key === 'carTarget'
                ? ['min' => 0, 'max' => 100, 'step' => 10, 'associations' => []]
                : $field['profile'];
            if (!$profile) { throw new InvalidArgumentException('Variablenprofil mit Grenzen erforderlich'); }
            if ($profile['associations']) {
                if (!in_array($value, array_column($profile['associations'], 'Value'))) {
                    throw new InvalidArgumentException('Unbekannter Auswahlwert');
                }
            } else {
                if ($profile['max'] <= $profile['min'] || $value < $profile['min'] || $value > $profile['max']) {
                    throw new InvalidArgumentException('Wert außerhalb des Variablenprofils');
                }
                $step = $profile['step'];
                if ($step > 0 && abs(($value - $profile['min']) / $step - round(($value - $profile['min']) / $step)) > 0.000001) {
                    throw new InvalidArgumentException('Ungültige Schrittweite');
                }
            }
            $value = $field['type'] === 1 ? (int) $value : (float) $value;
        }
        RequestAction($this->ReadPropertyInteger($key), $value);
    }

    private function AddMinuteSegment(array &$sums, array &$seconds, int $start, int $end, float $value): void
    {
        while ($start < $end) {
            $minute = intdiv($start, 60) * 60;
            $stop = min($end, $minute + 60);
            $duration = $stop - $start;
            $sums[$minute] = ($sums[$minute] ?? 0.0) + $value * $duration;
            $seconds[$minute] = ($seconds[$minute] ?? 0) + $duration;
            $start = $stop;
        }
    }

    private function MinuteHistory(int $archive, int $id, int $start, int $end): array
    {
        $sums = [];
        $seconds = [];
        $right = $end;
        $cursor = $end - 1;
        // Stream newest-first pages instead of loading an entire busy day into memory.
        do {
            $rows = AC_GetLoggedValues($archive, $id, $start, $cursor, 10000);
            if (!is_array($rows)) { throw new RuntimeException('Rohdaten nicht verfügbar'); }
            foreach ($rows as $row) {
                $stamp = (int) $row['TimeStamp'];
                if ($stamp < $start || $stamp >= $right || !is_numeric($row['Value'])
                    || !is_finite((float) $row['Value'])) { continue; }
                $this->AddMinuteSegment($sums, $seconds, $stamp, $right, (float) $row['Value']);
                $right = $stamp;
            }
            if (count($rows) < 10000) { break; }
            $oldest = (int) $rows[count($rows) - 1]['TimeStamp'];
            if ($oldest > $cursor) { throw new RuntimeException('Ungültige Archivseite'); }
            $cursor = $oldest - 1;
        } while ($cursor >= $start);
        // The last known value remains valid until the next recorded change.
        if ($right > $start) {
            $previous = AC_GetLoggedValues($archive, $id, 0, $start - 1, 1);
            if ($previous && is_numeric($previous[0]['Value']) && is_finite((float) $previous[0]['Value'])) {
                $this->AddMinuteSegment($sums, $seconds, $start, $right, (float) $previous[0]['Value']);
            }
        }
        ksort($sums, SORT_NUMERIC);
        $points = [];
        foreach ($sums as $stamp => $sum) { $points[] = [$stamp, $sum / $seconds[$stamp]]; }
        return $points;
    }

    public function History(): array
    {
        // Shared by all open visualization clients of this instance.
        $cacheKey = 'minute-v1:' . json_encode(array_map(function ($name) {
            return $this->ReadPropertyInteger($name);
        }, ['power', 'pv', 'house', 'gridPower']));
        $cached = json_decode($this->GetBuffer('HistoryCache'), true);
        if (is_array($cached) && ($cached['key'] ?? null) === $cacheKey
            && time() >= ($cached['time'] ?? 0) && time() - ($cached['time'] ?? 0) < 60
            && isset($cached['data'])) {
            return $cached['data'];
        }
        $archives = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        $result = [];
        foreach (['power', 'pv', 'house', 'gridPower'] as $key) {
            $id = $this->ReadPropertyInteger($key);
            $entry = ['points' => [], 'message' => 'Archivierung nicht aktiv'];
            if ($archives && $id && IPS_VariableExists($id) && AC_GetLoggingStatus($archives[0], $id)) {
                try {
                    // Completed UTC minute buckets; labels use the display device's timezone.
                    $end = intdiv(time(), 60) * 60;
                    $entry['points'] = $this->MinuteHistory($archives[0], $id, $end - 86400, $end);
                    $entry['message'] = $entry['points'] ? '' : 'Noch keine Archivdaten';
                } catch (Throwable $e) { $entry['message'] = 'Archivdaten nicht verfügbar'; }
            }
            $result[$key] = $entry;
        }
        $this->SetBuffer('HistoryCache', json_encode(['key' => $cacheKey, 'time' => time(), 'data' => $result], JSON_THROW_ON_ERROR));
        return $result;
    }

}
