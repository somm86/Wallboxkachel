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
        'batterySoc' => [45769, 'PV Speicher Ladestand'],
        'batteryPower' => [18192, 'PV Speicher Leistung'],
        'chargeTime' => [50934, 'Ladezeit'], 'active' => [23338, 'Modul aktiv']
    ];
    private const CONTROLS = ['enabled', 'mode', 'pvShare', 'current', 'phases', 'gridEnabled', 'gridLimit', 'active'];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('BaseURL', '');
        $this->RegisterAttributeString('Token', '');
        foreach (self::FIELDS as $key => $field) {
            $this->RegisterPropertyInteger($key, $field[0]);
        }
        $this->RegisterVariableString('Tile', 'Wallbox', '~HTMLBox', 0);
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if ($this->ReadAttributeString('Token') === '') {
            $this->WriteAttributeString('Token', bin2hex(random_bytes(32)));
        }
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->RegisterHook();
        }
        $base = rtrim($this->ReadPropertyString('BaseURL'), '/');
        if ($base !== '' && !preg_match('~^https?://[^\s<>"\x27]+$~i', $base)) {
            $this->SetStatus(200);
            return;
        }
        $url = htmlspecialchars($base . $this->Hook() . '#' . $this->ReadAttributeString('Token'), ENT_QUOTES, 'UTF-8');
        $this->SetValue('Tile', '<iframe title="Wallbox" src="' . $url . '" style="display:block;width:100%;height:100%;min-height:280px;border:0" referrerpolicy="no-referrer"></iframe>');
        $this->SetStatus(102);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
        if ($Message === IPS_KERNELMESSAGE && $Data[0] === KR_READY) {
            $this->RegisterHook();
        }
    }

    public function GetConfigurationForm()
    {
        $elements = [
            ['type' => 'Label', 'caption' => 'HTMLBox „Wallbox“ in der Kachelvisualisierung einbinden. Aktionen und Grenzen werden aus Variablenprofilen gelesen.'],
            ['type' => 'ValidationTextBox', 'name' => 'BaseURL', 'caption' => 'Symcon-Webserver URL (leer = gleicher Ursprung, sonst z.B. https://symcon.example.de)']
        ];
        foreach (self::FIELDS as $key => $field) {
            $elements[] = ['type' => 'SelectVariable', 'name' => $key, 'caption' => $field[1]];
        }
        return json_encode(['elements' => $elements, 'actions' => [], 'status' => [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
            ['code' => 200, 'icon' => 'error', 'caption' => 'Ungültige Webserver-URL']
        ]], JSON_UNESCAPED_UNICODE);
    }

    private function Hook(): string
    {
        return '/hook/wallboxkachel-' . $this->InstanceID;
    }

    private function RegisterHook(): void
    {
        $instances = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (!$instances) { return; }
        $id = $instances[0];
        $hooks = json_decode(IPS_GetProperty($id, 'Hooks'), true) ?: [];
        foreach ($hooks as $hook) {
            if ($hook['Hook'] === $this->Hook()) {
                if ($hook['TargetID'] !== $this->InstanceID) { $this->SetStatus(201); }
                return;
            }
        }
        $hooks[] = ['Hook' => $this->Hook(), 'TargetID' => $this->InstanceID];
        IPS_SetProperty($id, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($id);
    }

    private function Field(string $key): array
    {
        $id = $this->ReadPropertyInteger($key);
        if (!$id || !IPS_VariableExists($id)) { return ['missing' => true]; }
        $variable = IPS_GetVariable($id);
        $name = $variable['VariableCustomProfile'] ?: $variable['VariableProfile'];
        $profile = $name !== '' && IPS_VariableProfileExists($name) ? IPS_GetVariableProfile($name) : null;
        $action = $variable['VariableCustomAction'] ?: $variable['VariableAction'];
        return ['value' => GetValue($id), 'text' => GetValueFormatted($id),
            'type' => $variable['VariableType'], 'writable' => in_array($key, self::CONTROLS, true) && $action > 0,
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
            $profile = $field['profile'];
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

    public function History(): array
    {
        $archives = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        $result = [];
        foreach (['power', 'pv', 'house'] as $key) {
            $id = $this->ReadPropertyInteger($key);
            $entry = ['points' => [], 'message' => 'Archivierung nicht aktiv'];
            if ($archives && $id && IPS_VariableExists($id) && AC_GetLoggingStatus($archives[0], $id)) {
                try {
                    // Hourly aggregates bound the payload without truncating busy raw logs.
                    $values = AC_GetAggregatedValues($archives[0], $id, 0, time() - 86400, time(), 0);
                    $entry['points'] = array_map(static function ($row) {
                        return [$row['TimeStamp'], $row['Avg']];
                    }, $values);
                    usort($entry['points'], static function ($a, $b) { return $a[0] <=> $b[0]; });
                    $entry['message'] = $values ? '' : 'Noch keine Archivdaten';
                } catch (Throwable $e) { $entry['message'] = 'Archivdaten nicht verfügbar'; }
            }
            $result[$key] = $entry;
        }
        return $result;
    }

    protected function ProcessHookData()
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            header('Content-Type: text/html; charset=utf-8');
            readfile(__DIR__ . '/tile.html');
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        if ($method !== 'POST') { http_response_code(405); echo '{}'; return; }
        $token = $this->ReadAttributeString('Token');
        if ($token === '' || !hash_equals($token, $_SERVER['HTTP_X_WALLBOX_TOKEN'] ?? '')) {
            http_response_code(401); echo '{"error":"Nicht autorisiert"}'; return;
        }
        try {
            $body = file_get_contents('php://input', false, null, 0, 8193);
            if (strlen($body) > 8192) { throw new InvalidArgumentException('Anfrage zu groß'); }
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            switch ($data['op'] ?? '') {
                case 'state': $result = $this->Snapshot(); break;
                case 'history': $result = $this->History(); break;
                case 'action': $this->Control($data['key'] ?? '', $data['value'] ?? null); $result = $this->Snapshot(); break;
                default: throw new InvalidArgumentException('Unbekannte Anfrage');
            }
            echo json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Anfrage fehlgeschlagen: ' . $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }
}
