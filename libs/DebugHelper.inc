<?php
trait DebugHelper {
	protected function DebugHelperCreate() {
		$this->RegisterPropertyInteger("LogLevel", 1);
	}
	protected function SendDebug($Message, $Data, $LogLevel){
		if($LogLevel > 0){
//			$myLevel=@$this->ReadPropertyInteger("LogLevel") ?? 999;
			if($LogLevel > (@$this->ReadPropertyInteger("LogLevel") ?? 999))return;
			$LogLevel=0;
		}elseif($LogLevel<0){
			$LogLevel=1;
		}
		parent::SendDebug($Message, $Data,$LogLevel);
	}
	protected function SendMessage(string $Data, int $LogLevel=0){
		if($LogLevel>0){
			if($LogLevel > (@$this->ReadPropertyInteger("LogLevel") ?? 999))return;
		}
		IPS_LogMessage(IPS_GetName($this->InstanceID),$Data);
	}
	
}
?>