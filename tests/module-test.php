<?php
// Standalone contract tests. These stubs are not a Symcon runtime.
const IPS_KERNELMESSAGE = 10100;
const KR_READY = 10103;
class IPSModule {
    public $InstanceID = 12345;
    public array $properties = [];
    public array $attributes = [];
    public int $visualizationType = 0;
    public array $visualizationMessages = [];
    public array $values = [];
    public array $buffers = [];
    public function GetBuffer($name) { return $this->buffers[$name] ?? ''; }
    public function SetBuffer($name, $value) { $this->buffers[$name] = $value; }
    public array $timers = [];
    public array $debug = [];
    public function RegisterTimer($name, $interval, $script) { $this->timers[$name] = $interval; }
    public function SetTimerInterval($name, $interval) { $this->timers[$name] = $interval; }
    public function SendDebug($name, $message, $format) { $this->debug[] = [$name, $message]; }
    public function Create() {}
    public function ApplyChanges() {}
    public function SetVisualizationType($type) { $this->visualizationType = $type; }
    public function UpdateVisualizationValue($value) { $this->visualizationMessages[] = json_decode($value, true); }
    public function GetIDForIdent($ident) { global $legacyId; return $legacyId; }
    public function SetValue($ident, $value) { $this->values[$ident] = $value; }
    public function SetStatus($status) {}
    public function RegisterPropertyString($name, $value) { $this->properties[$name] = $value; }
    public function RegisterPropertyInteger($name, $value) { $this->properties[$name] = $value; }
    public function RegisterAttributeString($name, $value) { $this->attributes[$name] = $value; }
    public function ReadPropertyInteger($name) { return $this->properties[$name]; }
    public function ReadAttributeString($name) { return $this->attributes[$name]; }
    public function RegisterVariableString(...$args) {}
    public function RegisterMessage(...$args) {}
}
$variables = [];
$actions = [];
$legacyId = false;
$hooks = [];
$applied = 0;
$archiveReads = 0;
$loggedValues = null;
function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_GetProperty($id, $name) { global $hooks; return json_encode($hooks); }
function IPS_SetProperty($id, $name, $value) { global $hooks; $hooks = json_decode($value, true); }
function IPS_ApplyChanges($id) { global $applied; $applied++; }
function IPS_VariableExists($id) { global $variables; return isset($variables[$id]); }
function IPS_GetVariable($id) { global $variables; return $variables[$id]; }
function IPS_VariableProfileExists($name) { return in_array($name, ['range', 'enum']); }
function IPS_GetVariableProfile($name) {
    return ['MinValue' => 6, 'MaxValue' => 32, 'StepSize' => 1,
        'Associations' => $name === 'enum' ? [['Value' => 10, 'Name' => 'Nur PV'], ['Value' => 40, 'Name' => 'Manuell']] : []];
}
function GetValue($id) { global $variables; return $variables[$id]['value']; }
function GetValueFormatted($id) { return 'formatted-' . $id; }
function RequestAction($id, $value) { global $actions; $actions[] = [$id, $value]; }
function IPS_GetInstanceListByModuleID($id) { return [1]; }
function AC_GetLoggingStatus($archive, $id) { return true; }
function AC_GetLoggedValues($archive, $id, $start, $end, $limit) {
    global $archiveReads, $loggedValues;
    $archiveReads++;
    if ($loggedValues === null) {
        return $limit === 1 ? [['TimeStamp' => $end - 30, 'Value' => 50]]
            : [['TimeStamp' => $end - 29, 'Value' => 200], ['TimeStamp' => $end - 59, 'Value' => 100]];
    }
    $rows = array_values(array_filter($loggedValues, static function ($row) use ($start, $end) {
        return $row['TimeStamp'] >= $start && $row['TimeStamp'] <= $end;
    }));
    usort($rows, static function ($a, $b) { return $b['TimeStamp'] <=> $a['TimeStamp']; });
    return array_slice($rows, 0, $limit);
}
require __DIR__ . '/../WallboxTile/module.php';
$module = new WallboxTile();
$module->Create();
$checks = 0;
function check($condition, $label) { global $checks; if (!$condition) { throw new Exception($label); } $checks++; }
function rejects($module, $key, $value) {
    try { $module->Control($key, $value); } catch (InvalidArgumentException $e) { check(true, 'rejected'); return; }
    throw new Exception('Unexpected action accepted: ' . $key);
}
$base = ['VariableType' => 1, 'VariableCustomProfile' => '', 'VariableProfile' => 'range', 'VariableCustomAction' => 0, 'VariableAction' => 10, 'value' => 7];
$variables[12961] = $base;
$variables[25784] = array_replace($base, ['VariableProfile' => 'enum', 'value' => 10]);
$variables[23338] = array_replace($base, ['VariableType' => 0, 'VariableProfile' => '', 'value' => true]);
$variables[16098] = array_replace($base, ['VariableType' => 0, 'VariableProfile' => '', 'value' => true]);
$module->Control('current', 16);
check($actions === [[12961, 16]], 'Numeric action forwards original ID');
$module->Control('mode', 40);
check(end($actions) === [25784, 40], 'Profile values used instead of UI indices');
$module->Control('enabled', false);
check(end($actions) === [16098, false], 'Boolean action');
$module->Control('active', false);
check(end($actions) === [23338, false], 'Module enable switch forwards boolean action');
rejects($module, 'active', 1);
check(!isset($module->Snapshot()['fields']['price']) && !isset($module->Snapshot()['fields']['pricesValid']), 'Price fields removed');
check($module->Snapshot()['fields']['current']['profile']['max'] === 16, 'Manual current profile capped at 16 A');
foreach ([5, 17, 32, 33, 6.5, '16', null, INF] as $value) { rejects($module, 'current', $value); }
rejects($module, 'mode', 1);
rejects($module, 'enabled', 1);
rejects($module, 'power', 100);
rejects($module, 'unknown', 1);
$variables[12961]['VariableAction'] = 0;
rejects($module, 'current', 16);
$variables[12961]['VariableCustomAction'] = 99;
check($module->Snapshot()['fields']['current']['writable'], 'Custom action enables control');
check($module->Snapshot()['fields']['power']['missing'], 'Missing variable explicit');
$variables[50177] = $base;
$history = $module->History()['power']['points'];
check(count($history) === 1440 && $history[0][0] < $history[1439][0] && $history[1439][1] === 150.0, 'History uses chronological time-weighted minutes');
check($module->History()['pv']['message'] === 'Archivierung nicht aktiv', 'Missing archive series explained');
check(count(json_decode($module->GetConfigurationForm(), true)['elements']) === 28, 'All configured variables in form');
$variables[45769] = array_replace($base, ['VariableType' => 2, 'value' => 4.0]);
$variables[18192] = array_replace($base, ['VariableType' => 2, 'value' => -704.0]);
check($module->Snapshot()['fields']['batterySoc']['value'] === 4.0, 'Battery state of charge uses 45769');
check($module->Snapshot()['fields']['batteryPower']['value'] === -704.0, 'Battery discharge sign preserved');
check(!$module->Snapshot()['fields']['batteryPower']['writable'], 'Battery power is read only');
rejects($module, 'batteryPower', 500);
$variables[29716] = array_replace($base, ['VariableType' => 2, 'value' => 0.55]);
check($module->Snapshot()['fields']['batteryRemaining']['text'] === 'formatted-29716', 'Remaining time uses original Symcon formatting');
check(!$module->Snapshot()['fields']['batteryRemaining']['writable'], 'Remaining time is read only');
$variables[48784] = array_replace($base, ['value' => 62]);
$variables[59274] = array_replace($base, ['value' => 200]);
$variables[42679] = array_replace($base, ['VariableType' => 2, 'value' => 16.0]);
check($module->Snapshot()['fields']['carSoc']['value'] === 62, 'Car SOC uses 48784');
check($module->Snapshot()['fields']['carRange']['value'] === 200, 'Car range uses 59274');
check(!$module->Snapshot()['fields']['carSoc']['writable'], 'Car SOC read only');
$module->Control('carTarget', 20);
check(end($actions) === [42679, 20.0], 'Car target forwards float action to 42679');
foreach ([0, 30, 100] as $target) {
    $module->Control('carTarget', $target);
    check(end($actions) === [42679, (float) $target], 'Car target supports full 0-100 range');
}
foreach ([-1, 101, 80.5, 33, '80'] as $target) { rejects($module, 'carTarget', $target); }
$variables[42679]['VariableAction'] = 0;
rejects($module, 'carTarget', 20);
$variables[13288] = array_replace($base, ['VariableType' => 2, 'value' => -770.0]);
check($module->Snapshot()['fields']['gridPower']['value'] === -770.0, 'Grid power sign and ID preserved');
$module->SetBuffer('HistoryCache', '');
check(count($module->History()['gridPower']['points']) === 1440, 'Grid minute history available');
rejects($module, 'gridPower', 100);
check($module->visualizationType === 1, 'Native visualization enabled on creation');
check(strpos($module->GetVisualizationTile(), 'function handleMessage(') !== false, 'Tile provides native message callback');
check(strpos($module->GetVisualizationTile(), 'X-Wallbox-Token') === false, 'Tile has no legacy HTTP transport');
$module->RequestAction('TileRequest', json_encode(['id' => 'client-1', 'op' => 'state']));
check(end($module->visualizationMessages)['id'] === 'client-1', 'Reply preserves client request identity');
check(isset(end($module->visualizationMessages)['result']['fields']), 'Native bridge returns state');
$variables[42679]['VariableAction'] = 10;
$module->RequestAction('TileRequest', json_encode(['id' => 'client-2', 'op' => 'action', 'key' => 'carTarget', 'value' => 80]));
check(end($actions) === [42679, 80.0], 'Native action reaches configured variable');
$module->RequestAction('TileRequest', json_encode(['id' => 'client-3', 'op' => 'action', 'key' => 'gridPower', 'value' => 80]));
check(isset(end($module->visualizationMessages)['error']), 'Rejected action returned to originating client');
$hooks = [
    ['Hook' => '/hook/wallboxkachel-12345', 'TargetID' => 12345],
    ['Hook' => '/hook/other', 'TargetID' => 54321],
    ['Hook' => '/hook/wallboxkachel-12345', 'TargetID' => 54321]
];
$legacyId = 999;
$module->ApplyChanges();
check(count($hooks) === 2 && $hooks[0]['Hook'] === '/hook/other', 'Migration removes only owned legacy endpoint');
check(isset($module->values['Tile']), 'Old HTMLBox preserved with migration help');
$module->ApplyChanges();
check($applied === 1, 'Hook migration idempotent');
$module->SetBuffer('HistoryCache', '');
$module->History();
$reads = $archiveReads;
$module->History();
check($archiveReads === $reads, 'Repeated archive requests share cached data');
$cache = json_decode($module->GetBuffer('HistoryCache'), true);
$cache['time'] = time() - 61;
$module->SetBuffer('HistoryCache', json_encode($cache));
$module->History();
check($archiveReads > $reads, 'Archive cache expires after 60 seconds');
$reads = $archiveReads;
$module->properties['house'] = 50177;
$module->History();
check($archiveReads > $reads, 'Changed variable mapping invalidates archive cache');
check($module->Snapshot()['fields']['gridPower']['profile'] === null, 'Display-only fields omit unused profile metadata');
$reads = $archiveReads;
$module->RequestAction('TileRequest', json_encode(['id' => 'legacy-history', 'op' => 'history']));
$legacyReply = end($module->visualizationMessages);
check(!isset($legacyReply['error']) && count($legacyReply['result']) === 4, 'Legacy request without series accepted');
check($archiveReads === $reads && strlen(json_encode($legacyReply)) < 42000, 'Legacy response bounded with no archive I/O');
$cacheForLegacy = json_decode($module->GetBuffer('HistoryCache'), true);
$cacheForLegacy['data'] = array_fill_keys(['power', 'pv', 'house', 'gridPower'], ['points' => [], 'message' => 'Noch keine Archivdaten']);
$module->SetBuffer('HistoryCache', json_encode($cacheForLegacy));
$module->RequestAction('TileRequest', json_encode(['id' => 'legacy-small', 'op' => 'history']));
check(end($module->visualizationMessages)['result']['power']['message'] === 'Noch keine Archivdaten', 'Small legacy cache keeps its original response shape');
$cacheForLegacy['data']['power']['points'] = array_fill(0, 4000, [1793700000, 12345.67]);
$module->SetBuffer('HistoryCache', json_encode($cacheForLegacy));
$module->RequestAction('TileRequest', json_encode(['id' => 'legacy-large', 'op' => 'history']));
check(strlen(json_encode(end($module->visualizationMessages))) < 42000 && !isset(end($module->visualizationMessages)['error']), 'Large legacy cache returns a bounded refresh hint without an error');
$module->SetBuffer('HistoryCache', '');
$module->RequestAction('TileRequest', json_encode(['id' => 'legacy-cold', 'op' => 'history']));
$legacyReply = end($module->visualizationMessages);
check(!isset($legacyReply['error']) && strpos($legacyReply['result']['power']['message'], 'Kachel schließen') !== false, 'Cold legacy request explains how to load the current tile');
check($archiveReads === $reads, 'Cold legacy request performs no archive work');
$module->SetBuffer('HistoryCache', '');
$module->SetBuffer('LastHistoryRequest', '0');
$reads = $archiveReads;
$module->RequestAction('TileRequest', json_encode(['id' => 'cold-history', 'op' => 'history', 'series' => 'power']));
check($archiveReads === $reads, 'Cold native history request performs no archive I/O');
check(end($module->visualizationMessages)['result']['power']['loading'] === true, 'Cold history returns loading status immediately');
check($module->timers['RefreshHistory'] === 1000, 'First history client schedules background refresh');
$module->RequestAction('TileRequest', json_encode(['id' => 'live-values', 'op' => 'state']));
check($archiveReads === $reads && isset(end($module->visualizationMessages)['result']['fields']), 'Live state remains independent of archive initialization');
$module->RefreshHistory();
check($archiveReads > $reads && $module->timers['RefreshHistory'] === 60000, 'Background timer builds shared archive cache');
$reads = $archiveReads;
$module->RequestAction('TileRequest', json_encode(['id' => 'cached-power', 'op' => 'history', 'series' => 'power']));
$reply = end($module->visualizationMessages);
check($archiveReads === $reads, 'Warm native history request only reads cache');
check(array_keys($reply['result']) === ['power'] && count($reply['result']['power']['points']) === 1440, 'Native history returns one full minute curve per response');
check(strlen(json_encode($reply)) < 60000, 'Individual native response stays below 60 KB for test curve');
$module->RequestAction('TileRequest', json_encode(['id' => 'invalid-series', 'op' => 'history', 'series' => 'unknown']));
check(isset(end($module->visualizationMessages)['error']) && $archiveReads === $reads, 'Invalid curve rejected without archive I/O');
$module->SetBuffer('LastHistoryRequest', (string) (time() - 121));
$module->SetBuffer('HistoryCache', '');
$module->RefreshHistory();
check($archiveReads === $reads, 'No archive work when no recent visualization client');
check(count($module->debug) > 0 && end($module->debug)[0] === 'Kachelausgabe', 'Native requests expose timing and size diagnostics');
$module->RequestAction('TileRequest', json_encode(['id' => 'client-diagnostics', 'op' => 'state',
    'clientTrace' => ['1793734500000 timeout-reply prior-id state']]));
$clientDebug = array_values(array_filter($module->debug, static function ($entry) { return $entry[0] === 'Kachelclient'; }));
check(strpos(end($clientDebug)[1], 'timeout-reply') !== false, 'Browser-side timeouts reach the instance debug log on the next request');
check(isset(end($module->visualizationMessages)['result']['fields']), 'Client diagnostics preserve normal state replies');
check(end($module->debug)[0] === 'Kachelausgabe', 'Debug records completion after visualization output');
$minuteHistory = new ReflectionMethod(WallboxTile::class, 'MinuteHistory');
$loggedValues = [['TimeStamp' => 120, 'Value' => 0], ['TimeStamp' => 150, 'Value' => 60]];
check($minuteHistory->invoke($module, 1, 50177, 120, 240) === [[120, 30.0], [180, 60.0]], 'Uneven samples weighted by duration');
$loggedValues = [['TimeStamp' => 90, 'Value' => -770]];
check($minuteHistory->invoke($module, 1, 50177, 120, 300) === [[120, -770.0], [180, -770.0], [240, -770.0]], 'Carry previous value through sparse archive minutes');
$loggedValues = [];
check($minuteHistory->invoke($module, 1, 50177, 120, 240) === [], 'No samples does not fabricate values');
$loggedValues = [['TimeStamp' => 240, 'Value' => 100]];
check($minuteHistory->invoke($module, 1, 50177, 120, 240) === [], 'Incomplete current minute excluded');
$loggedValues = [];
for ($i = 120; $i < 10121; $i++) { $loggedValues[] = ['TimeStamp' => $i, 'Value' => 1]; }
$before = $archiveReads;
$minutes = $minuteHistory->invoke($module, 1, 50177, 120, 10140);
check($archiveReads - $before === 2 && count($minutes) === 167 && $minutes[0][1] === 1.0 && end($minutes)[1] === 1.0, 'More than 10000 raw samples paginated without truncation');
echo "PASS: $checks backend contract checks\n";
