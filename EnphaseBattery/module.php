<?php

declare(strict_types=1);

class EnphaseBattery extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterVariableFloat('SOC', 'State of Charge %');
        $this->RegisterVariableFloat('Power', 'Battery Power W');
        $this->RegisterVariableString('Status', 'Battery Status');
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
        if (isset($data['soc'])) {
            SetValue($this->GetIDForIdent('SOC'), floatval($data['soc']));
        }
        if (isset($data['power'])) {
            SetValue($this->GetIDForIdent('Power'), floatval($data['power']));
        }
        if (isset($data['status'])) {
            SetValue($this->GetIDForIdent('Status'), $data['status']);
        }
    }
}
?>