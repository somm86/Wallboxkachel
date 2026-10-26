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
check(count(json_decode($module->GetConfigurationForm(), true)['elements']) === 22, 'All configured variables in form');
echo "PASS: $checks backend contract checks\n";
