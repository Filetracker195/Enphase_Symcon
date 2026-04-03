<?php

declare(strict_types=1);

class EnphaseGateway extends IPSModule
{
    private $token = null;
    private $tokenExpiry = 0;

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Host', 'https://envoy.local');
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('UserID', '');
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyBoolean('AutoDeleteInverters', true);
        $this->RegisterPropertyBoolean('Debug', false);

        $this->RegisterTimer('UpdateTimer', 0, 'EnphaseGateway_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('UpdateTimer', max(10, $interval) * 1000);

        // Create folders
        $this->EnsureFolder('10 Produktion', 0);
        $this->EnsureFolder('20 Batterie', 0);
        $this->EnsureFolder('30 Meter', 0);
        $this->EnsureFolder('40 Inverter', 0);

        // Ensure children modules are available (user must import submodules)
    }

    public function GetConfigurationForm()
    {
        $form = json_decode('{
            "elements": [
                {"type":"Label","caption":"Enphase Gateway Configuration"},
                {"type":"ValidationTextBox","name":"Host","caption":"Envoy Host"},
                {"type":"ValidationTextBox","name":"ClientID","caption":"Client ID"},
                {"type":"PasswordBox","name":"ClientSecret","caption":"Client Secret"},
                {"type":"ValidationTextBox","name":"UserID","caption":"Enphase User ID"},
                {"type":"NumberSpinner","name":"UpdateInterval","caption":"Update Interval (s)","minimum":10,"maximum":3600},
                {"type":"CheckBox","name":"AutoDeleteInverters","caption":"Auto delete missing inverters"},
                {"type":"CheckBox","name":"Debug","caption":"Enable Debug"}
            ],
            "actions": []
        }', true);
        return json_encode($form);
    }

    public function Update()
    {
        try {
            $this->Log('Update started', 0);
            $this->EnsureToken();
            $this->FetchAndDistributeData();
        } catch (Exception $e) {
            $this->Log('Update error: ' . $e->getMessage(), 1);
        }
    }

    private function EnsureToken()
    {
        $now = time();
        if ($this->token && $now < $this->tokenExpiry - 30) {
            return;
        }
        $clientId = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $userId = $this->ReadPropertyString('UserID');

        if (empty($clientId) || empty($clientSecret) || empty($userId)) {
            throw new Exception('ClientID, ClientSecret or UserID not configured.');
        }

        $url = 'https://api.enphaseenergy.com/oauth/token';
        $data = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'user_id' => $userId
        ]);

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $data,
                'timeout' => 10
            ]
        ];
        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            throw new Exception('Token request failed.');
        }
        $json = json_decode($result, true);
        if (!isset($json['access_token'])) {
            throw new Exception('Token response invalid: ' . $result);
        }
        $this->token = $json['access_token'];
        $this->tokenExpiry = time() + ($json['expires_in'] ?? 3600);
        $this->Log('Token acquired, expires in ' . ($json['expires_in'] ?? 3600) . 's', 0);
    }

    private function FetchAndDistributeData()
    {
        $host = rtrim($this->ReadPropertyString('Host'), '/');
        $token = $this->token;
        if (empty($token)) {
            throw new Exception('No token available.');
        }

        // Example endpoints - adjust to actual Envoy endpoints if needed
        $endpoints = [
            'production' => $host . '/api/v1/production',
            'inventory' => $host . '/api/v1/production/inventory',
            'meters' => $host . '/api/v1/meters',
            'battery' => $host . '/api/v1/battery'
        ];

        $responses = [];
        foreach ($endpoints as $key => $url) {
            $responses[$key] = $this->GetJson($url, $token);
        }

        // Create/Update production instance
        $this->EnsureChildInstance('EnphaseProduction', '10 Produktion', 'Enphase Production', $responses['production'] ?? null);

        // Create/Update battery instance
        $this->EnsureChildInstance('EnphaseBattery', '20 Batterie', 'Enphase Battery', $responses['battery'] ?? null);

        // Create/Update meters instance
        $this->EnsureChildInstance('EnphaseMeters', '30 Meter', 'Enphase Meters', $responses['meters'] ?? null);

        // Inverters: inventory contains microinverter list
        $inventory = $responses['inventory'] ?? [];
        $serials = [];
        if (isset($inventory['microinverters']) && is_array($inventory['microinverters'])) {
            foreach ($inventory['microinverters'] as $inv) {
                $serial = $inv['serial'] ?? null;
                if ($serial) {
                    $serials[] = $serial;
                    $this->EnsureInverterInstance($serial, $inv);
                }
            }
        }

        // Auto delete missing inverters
        if ($this->ReadPropertyBoolean('AutoDeleteInverters')) {
            $this->CleanupMissingInverters($serials);
        }
    }

    private function GetJson(string $url, string $token)
    {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
                'timeout' => 10
            ]
        ];
        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            $this->Log("GET {$url} failed", 1);
            return null;
        }
        return json_decode($result, true);
    }

    private function EnsureFolder(string $name, int $parentId = 0)
    {
        $root = IPS_GetObjectIDByName($name, $this->InstanceID);
        if ($root === false) {
            $root = IPS_CreateCategory();
            IPS_SetName($root, $name);
            IPS_SetParent($root, $this->InstanceID);
        }
        return $root;
    }

    private function EnsureChildInstance(string $moduleName, string $folderName, string $instanceName, $data)
    {
        // Find folder
        $folder = IPS_GetObjectIDByName($folderName, $this->InstanceID);
        if ($folder === false) {
            $folder = $this->EnsureFolder($folderName, $this->InstanceID);
        }
        // Find existing instance by module name
        $children = IPS_GetChildrenIDs($folder);
        foreach ($children as $child) {
            $info = IPS_GetObject($child);
            if ($info['ObjectType'] == 1) {
                // Instance
                $inst = IPS_GetInstance($child);
                if ($inst['ModuleInfo']['ModuleName'] == $moduleName) {
                    // Update via message
                    $this->SendDataToChild($child, $data);
                    return;
                }
            }
        }
        // Create instance
        $moduleId = $this->FindModuleIdByName($moduleName);
        if ($moduleId === null) {
            $this->Log("Module {$moduleName} not found. Please import submodule.", 1);
            return;
        }
        $iid = IPS_CreateInstance($moduleId);
        IPS_SetName($iid, $instanceName);
        IPS_SetParent($iid, $folder);
        IPS_ApplyChanges($iid);
        $this->SendDataToChild($iid, $data);
    }

    private function EnsureInverterInstance(string $serial, array $invData)
    {
        $folder = IPS_GetObjectIDByName('40 Inverter', $this->InstanceID);
        if ($folder === false) {
            $folder = $this->EnsureFolder('40 Inverter', $this->InstanceID);
        }
        $name = 'Inverter ' . $serial;
        $existing = $this->FindInstanceByNameInFolder($name, $folder);
        if ($existing !== null) {
            $this->SendDataToChild($existing, $invData);
            return;
        }
        $moduleId = $this->FindModuleIdByName('EnphaseInverter');
        if ($moduleId === null) {
            $this->Log('EnphaseInverter module not found. Please import submodule.', 1);
            return;
        }
        $iid = IPS_CreateInstance($moduleId);
        IPS_SetName($iid, $name);
        IPS_SetParent($iid, $folder);
        IPS_ApplyChanges($iid);
        // Set serial property if available
        if (method_exists($this, 'SendDataToChild')) {
            $this->SendDataToChild($iid, $invData);
        }
    }

    private function FindModuleIdByName(string $name)
    {
        $modules = IPS_GetModuleList();
        foreach ($modules as $mid) {
            $info = IPS_GetModule($mid);
            if ($info['ModuleName'] == $name) {
                return $mid;
            }
        }
        return null;
    }

    private function FindInstanceByNameInFolder(string $name, int $folderId)
    {
        $children = IPS_GetChildrenIDs($folderId);
        foreach ($children as $child) {
            $obj = IPS_GetObject($child);
            if ($obj['ObjectType'] == 1) {
                if ($obj['ObjectName'] == $name) {
                    return $child;
                }
            }
        }
        return null;
    }

    private function CleanupMissingInverters(array $currentSerials)
    {
        $folder = IPS_GetObjectIDByName('40 Inverter', $this->InstanceID);
        if ($folder === false) {
            return;
        }
        $children = IPS_GetChildrenIDs($folder);
        foreach ($children as $child) {
            $obj = IPS_GetObject($child);
            if ($obj['ObjectType'] == 1) {
                $name = $obj['ObjectName'];
                if (strpos($name, 'Inverter ') === 0) {
                    $serial = trim(substr($name, 8));
                    if (!in_array($serial, $currentSerials)) {
                        // Delete instance
                        IPS_DeleteInstance($child);
                        $this->Log("Deleted inverter instance {$name}", 0);
                    }
                }
            }
        }
    }

    private function SendDataToChild(int $instanceId, $data)
    {
        if ($data === null) {
            return;
        }
        $payload = json_encode($data);
        // Use a custom message to child
        $this->SendDataToChildren($instanceId, $payload);
    }

    private function SendDataToChildren(int $instanceId, string $payload)
    {
        // If child implements ReceiveData, call it via message
        $this->SendDataToParent($payload);
        // Fallback: set a variable on child if needed
    }

    private function Log(string $message, int $level = 0)
    {
        if ($this->ReadPropertyBoolean('Debug') || $level > 0) {
            IPS_LogMessage('EnphaseGateway', $message);
        }
    }

    // Expose Update for timer
    public function UpdateTimer()
    {
        $this->Update();
    }
}
?>