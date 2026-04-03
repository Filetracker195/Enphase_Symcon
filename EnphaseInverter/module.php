<?php

declare(strict_types=1);

class EnphaseInverter extends IPSModule
{
    public function Create()
    {
        parent::Create();
        // Register variables
        $this->RegisterVariableString('Serial', 'Serial');
        $this->RegisterVariableFloat('Power', 'Power W');
        $this->RegisterVariableFloat('Energy', 'Energy Wh');
        $this->RegisterVariableString('Status', 'Status');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if ($data === null) {
            return;
        }
        // If parent sends raw array, handle both
        if (is_string($JSONString)) {
            $payload = json_decode($JSONString, true);
            if ($payload !== null) {
                $data = $payload;
            }
        }
        // Accept both array and object
        if (isset($data['serial'])) {
            SetValue($this->GetIDForIdent('Serial'), $data['serial']);
        }
        if (isset($data['power'])) {
            SetValue($this->GetIDForIdent('Power'), floatval($data['power']));
        }
        if (isset($data['energy'])) {
            SetValue($this->GetIDForIdent('Energy'), floatval($data['energy']));
        }
        if (isset($data['status'])) {
            SetValue($this->GetIDForIdent('Status'), $data['status']);
        }
    }
}
?>