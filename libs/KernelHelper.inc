<?php
trait KernelHelper {
	/* Exports / must declared in Module
	 *   protected function OnKernelReady(){}
	 */
/* Use
 * 	use KernelHelper {
        KernelHelper::ApplyChanges as KernelApplyChanges;
        KernelHelper::MessageSink as KernelMessageSink; 
    }
 */	
	protected function KernelHelperApplyChanges(){
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
 	}
	
	protected function KernelHelperMessageSink($TimeStamp, $SenderID, $Message, $Data){
        switch ($Message) {
        	case IPS_KERNELSTARTED:
                $this->OnKernelReady();
                break;
         }
    }
}
	
?>