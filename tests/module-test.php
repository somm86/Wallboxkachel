<?php
// Standalone contract tests. These stubs are not a Symcon runtime.
const IPS_KERNELMESSAGE = 10100;
const KR_READY = 10103;
class IPSModule {
    public $InstanceID = 12345;
    public array $properties = [];
    public array $attributes = [];
    public function Create() {}
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
function AC_GetAggregatedValues(...$args) { return [['TimeStamp' => 200, 'Avg' => 100], ['TimeStamp' => 100, 'Avg' => 50]]; }
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
check(count(json_decode($module->GetConfigurationForm(), true)['elements']) === 29, 'All configured variables in form');
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
check($module->History()['gridPower']['points'] === [[100, 50], [200, 100]], 'Grid history available');
rejects($module, 'gridPower', 100);
echo "PASS: $checks backend contract checks\n";
