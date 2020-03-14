<?php

trait TimerHelper {
	// Use function OnTimer($Value) and must defined in main Class 
	
	protected function TimerHelperCreate() {
		$this->RegisterTimer("UpdateTimer", 0, "IPS_RequestAction($this->InstanceID,'UPDATE_DATA','');");
	}
	protected function TimerHelperRequestAction($Ident, $Value){
		if($Ident=="UPDATE_DATA"){
			$this->OnTimer($Value);
			return true;
		}
	}
	protected function StartTimer(int $MilliSeconds) {
		$this->SetTimerInterval("UpdateTimer",$MilliSeconds);
	}
	protected function StartTimerByNext(array $Times){
		$nextRun = empty($Times) ? 0: min($Times);
		$secs = $nextRun - time();
		if($secs>0){
			$this->SendDebug(__FUNCTION__,sprintf($this->Translate("Next Event in %s seconds"),$secs),0);
			$this->StartTimer($secs*1000);
		} else $this->SendDebug(__FUNCTION__,$this->Translate("No more Events. Timer disabled"),0);
	}
	protected function StopTimer() {
		$this->SetTimerInterval("UpdateTimer",0);
	}

}
?>