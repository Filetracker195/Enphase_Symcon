<?php

declare(strict_types=1);

class EnphaseMeters extends IPSModule
{
    public function Create()
    {
        parent::Create();
        // L1..L3 Voltage/Current/Power
        for ($i = 1; $i <= 3; $i++) {
            $this->RegisterVariableFloat("V{$i}", "L{$i} Voltage V");
            $this->RegisterVariableFloat("I{$i}", "L{$i} Current A");
            $this->RegisterVariableFloat("P{$i}", "L{$i} Power W");
        }
        $this->RegisterVariableFloat('Frequency', 'Frequency Hz');
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
        for ($i = 1; $i <= 3; $i++) {
            if (isset($data["v{$i}"])) {
                SetValue($this->GetIDForIdent("V{$i}"), floatval($data["v{$i}"]));
            }
            if (isset($data["i{$i}"])) {
                SetValue($this->GetIDForIdent("I{$i}"), floatval($data["i{$i}"]));
            }
            if (isset($data["p{$i}"])) {
                SetValue($this->GetIDForIdent("P{$i}"), floatval($data["p{$i}"]));
            }
        }
        if (isset($data['frequency'])) {
            SetValue($this->GetIDForIdent('Frequency'), floatval($data['frequency']));
        }
    }
}
?>