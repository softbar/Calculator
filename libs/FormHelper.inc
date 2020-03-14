<?php
trait FormHelper {
	protected function FormHelperLoadForm($Assoc=false, $ThrowOnError=true){
		$filename = __DIR__.'/../'.__CLASS__ .'/form.json';
		if(!file_exists($filename)){
			$err="Form file '$filename' not found at ";
		}elseif(!($form=json_decode ( file_get_contents ($filename) ,$Assoc))){
			$err = 'JSON Parse Formfile Error => '.json_last_error_msg();
		}else return $form;
		if($err && $ThrowOnError){
			throw new Exception($err,E_USER_ERROR);
		}
		else $this->SendDebug(__FUNCTION__, $err,0);
	}
	protected function FormHelperFindFormItems($FormItems, array $ItemNames){
		$findItems=null; $found=[];
		$subTables=['ExpansionPanel'=>'items','RowLayout'=>'items','PopupButton'=>'popup','PopupAlert'=>'popup'];
		$findItems = function ($items)use($ItemNames,$subTables, &$findItems, &$found){
			foreach($items as $item){
				if(array_key_exists($item->type,$subTables )){
					// fix for Popup
					$sub = $item->{$subTables[$item->type]};
					if(!empty($sub->items))$sub=$sub->items;
					$findItems($sub);
					continue;				
				}
				if(!empty($item->name) && in_array($item->name,$ItemNames)){
					if(empty($found[$item->name]))
						$found[$item->name]=[$item];
					else $found[$item->name][]=$item;
				}
			}
		};
		if(is_object($FormItems)){
// 			$hasStatus = !empty($FormItems->status);
	    	if(!empty($FormItems->elements))$findItems($FormItems->elements);
	    	if(!empty($FormItems->actions))$findItems($FormItems->actions);
		} else $findItems($FormItems);
		return 	$found;	
	}
	
	protected function FormHelperSetFormItem(string $Name, $Caption=null, $Visible=null, $OnClick=null, $Confirm=null,  $Form = null ){
		if($Form){
			$found = $this->FormHelperFindFormItems($Form, [$Name]);
			if(!empty($found[0])){
				if(!is_null($Caption))$found[0]->caption=$Caption;
				if(!is_null($Visible))$found[0]->visible=(bool)$Visible;
				if(is_null($OnClick))$found[0]->onClick=$OnClick;
				if(is_null($Confirm))$found[0]->confirm=$Confirm;
			}
		} else {
			if(!is_null($Caption))$this->UpdateFormField($Name, 'caption', $Caption);
			if(!is_null($Visible))$this->UpdateFormField($Name, 'visible', (bool)$Visible);
			if(!is_null($OnClick))$this->UpdateFormField($Name, 'onClick', $OnClick);
			if(!is_null($Confirm))$this->UpdateFormField($Name, 'confirm', $Confirm);
		}
	}
	protected function FormHelperSetSelectOptions(string $Name, $Options, $Form = null){
		if($Form){
			$found = $this->FormHelperFindFormItems($Form, [$Name]);
			$this->SendDebug(__FUNCTION__,"$Name Data => ".json_encode($Options),0);
			if(!empty($found[$Name][0]))$found[$Name][0]->options=$Options;
		}else{
			if(!is_string($Options))$Options=json_encode($Options);
			$this->UpdateFormField($Name, 'options', $Options);
		}
	}
	
	protected function FormHelperSetList(string $Name, string $Caption, $Values, $Visible=null, $Form=null){
		if($Form){
			$found = $this->FormHelperFindFormItems($Form, [$Name]);
			if(!empty($found[$Name][0])){
				if($Caption)$found[$Name][0]->caption=$Caption;
				if(!is_null($Visible))$found[$Name][0]->visible=(bool)$Visible;
				if(is_array($Values)||is_object($Values))$found[$Name][0]->values=$Values;
			}
		}else{
			if($Caption)$this->UpdateFormField($Name, 'caption', $Caption);
			if(!is_null($Visible))$this->UpdateFormField($Name, 'visible', (bool)$Visible);
			if(!is_null($Values)){
				if(!is_string($Values))$Values=json_encode($Values);
				if($Values)$this->UpdateFormField($Name, 'values', $Values);
			}
		}
	}
	protected function FormHelperUpdateValues(array $KeyValueArray, $Form=null){
		if($Form){
			$items = $this->FormHelperFindFormItems($Form, array_keys($KeyValueArray));
			foreach($KeyValueArray as $key=>$value){
				if(!empty($items[$key][0])){
					if(!empty($value['key'])){
						$valuekey=$value['key'];
						$value=$value['value'];
					}elseif(is_array($value)){
						$valuekey=$items[$key][0]->type=='Select' ? 'options' : 'values';
					}
					$items[$key][0]->{$valuekey}=$value;
				}
			}
		} else foreach($KeyValueArray as $name=>$value){
			if(!empty($value['key'])){
				$key=$value['key'];
				$value=$value['value'];
			}
			elseif(is_array($value)){
				$key='values';
				$value=json_encode($value);
			} else $key='value';
			$this->UpdateFormField($name, $key, $value);
		}
	}

	protected function FormHelperAddPopupAlert(stdClass $Form, string $Name, string $Info='',bool $Visible=false,string $CloseCaption='Close'){
		$item=["type"=> "PopupAlert", "name"=>$Name ,"visible"=>$Visible, "popup"=> ["closeCaption"=> $CloseCaption,"items"=> [["type"=> "Label", "name"=>"AlertLabel$Name", "caption"=> $Info]]]];
		$item=json_decode(json_encode($item));
		if(empty($Form->actions))
			$Form->actions=[$item];
		else $Form->actions[]=$item;
	}
	protected function FormHelperShowAlert(string $Name, string $Info='', stdClass $Form = null){
		if($Form){
			$alert=$this->FormHelperFindFormItems($Form->actions,[$Name]);
			if(!empty($alert)){
				$alert = $alert[$Name][0];
				$alert->visible=true;
				if($Info)$alert->popup->items[0]->caption=$Info;
			}
		}else {
			if($Info)$this->UpdateFormField('AlertLabel'.$Name, 'caption', $Info);
			$this->UpdateFormField($Name, 'visible', true);
		}
	}

	
}

?>