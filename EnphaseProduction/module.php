<?php

declare(strict_types=1);

class EnphaseProduction extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterVariableFloat('PVPower', 'PV Power W');
        $this->RegisterVariableFloat('Consumption', 'Consumption W');
        $this->RegisterVariableFloat('Grid', 'Grid Power W');
        $this->RegisterVariableFloat('DailyEnergy', 'Daily Energy Wh');
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
        if (isset($data['pv_power'])) {
            SetValue($this->GetIDForIdent('PVPower'), floatval($data['pv_power']));
        }
        if (isset($data['consumption'])) {
            SetValue($this->GetIDForIdent('Consumption'), floatval($data['consumption']));
        }
        if (isset($data['grid'])) {
            SetValue($this->GetIDForIdent('Grid'), floatval($data['grid']));
        }
        if (isset($data['daily_energy'])) {
            SetValue($this->GetIDForIdent('DailyEnergy'), floatval($data['daily_energy']));
        }
    }
}
?>