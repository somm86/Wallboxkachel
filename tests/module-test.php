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
function AC_GetAggregatedValues(...$args) { global $archiveReads; $archiveReads++; return [['TimeStamp' => 200, 'Avg' => 100], ['TimeStamp' => 100, 'Avg' => 50]]; }
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
foreach ([5, 33, 6.5, '16', null, INF] as $value) { rejects($module, 'current', $value); }
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
check($module->History()['power']['points'] === [[100, 50], [200, 100]], 'History ordered by time');
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
check($module->History()['gridPower']['points'] === [[100, 50], [200, 100]], 'Grid history available');
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
echo "PASS: $checks backend contract checks\n";
