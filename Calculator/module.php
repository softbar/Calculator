<?php
require_once __DIR__ . '/../libs/loader.inc';
class Calculator extends IPSModule {
	use FormHelper,
		KernelHelper,
		TimerHelper,
		DebugHelper;
	function Create() {
		parent::Create ();
		// Options
		$this->RegisterPropertyBoolean ( 'DeleteUnusedVars', false );
		$this->RegisterPropertyBoolean ( 'SaveDelete', true );
		$this->RegisterPropertyBoolean ( 'ShowActivity', true );
		$this->RegisterPropertyBoolean ( 'CreateActivityVar', false );
		// Data
		$this->RegisterPropertyString ( 'ValueItems', '[]' );
		$this->RegisterPropertyString ( 'OutputItems', '[]' );
		$this->RegisterAttributeString ( 'RunTable', '[]' );
		$this->RegisterAttributeString ( 'ValueTable', '[]' );
		$this->TimerHelperCreate ();
		$this->DebugHelperCreate ();
	}
	function ApplyChanges() {
		parent::ApplyChanges ();
		$this->KernelHelperApplyChanges ();
		if (IPS_GetKernelRunlevel () != KR_READY) {return;}
		if (intval ( $this->GetBuffer ( 'SkipApply' ) )) {
			$this->SetBuffer ( 'SkipApply', 0 );
			return;
		}
		// Validate Variables and Update valueTable
		$this->StopTimer ();
		$inputs = $this->LoadInputs ();
		$changed = false;
		$newReferences = [ ];
		$valueTable = [ ];
		foreach ( $inputs as $input ) {
			if ($input->variableID) {
				$newReferences [] = $input->variableID;
				if (IPS_VariableExists ( $input->variableID )) {
					$valueTable [$input->variableID] = GetValue ( $input->variableID );
					if (empty ( $input->ident )) {
						$input->ident = $input->variableID . ' ' . explode ( ' ', IPS_GetName ( $input->variableID ) ) [0];
						$changed = true;
					}
					$this->SendDebug ( __FUNCTION__, "Variable $input->ident => " . $valueTable [$input->variableID], 2 );
				} else {
					$valueTable [$input->variableID] = 0;
					$this->SendDebug ( __FUNCTION__, "Variable Missing", 1 );
				}
			}
		}
		if ($changed) IPS_SetProperty ( $this->InstanceID, 'ValueItems', json_encode ( $this->InputCache = $inputs ) );
		$this->SaveValueTable ( $valueTable );

		// Update References
		$references = $this->GetReferenceList ();
		foreach ( $newReferences as $id )
			$this->RegisterReference ( $id );
		$unRegister = array_diff ( $references, $newReferences );
		foreach ( $unRegister as $id )
			$this->UnRegisterReference ( $id );

		// Validate Outputs
		$outputs = $this->LoadOutputs ();
		$runTable = $this->LoadRunTable ();
		$changed = false;
		if (! empty ( $outputs )) {
			foreach ( array_keys ( $runTable ) as $ident )
				$runTable [$ident] ['remove'] = true;
			$now = time ();
			foreach ( $outputs as $output ) {
				if (empty ( $output->ident )) {
					$output->ident = "OUTPUT" . random_int ( 10000, 99999 );
					$changed = true;
				}
				if (empty ( $runTable [$output->ident] )) {
					$runTable [$output->ident] = [ 
						'nextRun' => 0,
						'lastRun' => 0,
						'result' => null
					];
				}
				unset ( $runTable [$output->ident] ['remove'] );
				if ($output->interval > 0) {
					$runTable [$output->ident] ['nextRun'] = $now + ($output->interval * 60);
				} else
					$runTable [$output->ident] ['nextRun'] = $output->interval;
			}
			if ($changed) IPS_SetProperty ( $this->InstanceID, 'OutputItems', json_encode ( $this->OutputCache = $outputs ) );
			// remove deleted outputs from runTable
			foreach ( array_keys ( $runTable ) as $ident ) {
				if (! empty ( $runTable [$ident] ['remove'] )) {
					if ($this->ReadPropertyBoolean ( 'DeleteUnusedVars' )) {
						$this->DeleteVariable ( $ident );
					}
					unset ( $runTable [$ident] );
				}
			}
		} else
			$runTable = [ ];
		// Save Changes
		if (IPS_HasChanges ( $this->InstanceID )) {
			$this->SetBuffer ( 'SkipApply', 1 );
			IPS_ApplyChanges ( $this->InstanceID );
		}
		// Update
		$this->SaveRunTable ( $runTable );
		$this->UpdateVariables ();
		$this->StartTimerByRunTable ( $runTable );
	}
	function GetConfigurationForm() {
		$form = $this->FormHelperLoadForm ();
		$outputs = $this->GetFormOutputs ();
		$options = [ 
			[ 
				"label" => "Select Output Varibles",
				"value" => ""
			]
		];
		foreach ( $outputs as $o )
			$options [] = [ 
				"label" => $o->name,
				"value" => $o->ident
			];
		$form->elements [1]->items [0]->values = $this->GetFormInputs ();
		$form->elements [1]->items [1]->values = $outputs;
		if ($this->ReadPropertyBoolean ( 'ShowActivity' )) {
// $list = end ( $form->actions );
// $list->values = $this->GetFormActivitys ();
			$form->actions [1]->values = $this->GetFormActivitys ();
		} else
			array_pop ( $form->actions );
		$this->FormHelperSetSelectOptions ( 'OUTPUT', $options, $form->actions );
		return json_encode ( $form );
	}
	function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
		$this->KernelHelperMessageSink ( $TimeStamp, $SenderID, $Message, $Data );
		switch ($Message) {
			case VM_DELETE :
				$this->SendDebug ( __FUNCTION__, "TimeStamp $TimeStamp, SenderID $SenderID, Message $Message, Data => " . json_encode ( $Data ), 0 );
				$values = $this->LoadValueTable ();
				if (! empty ( $values [$SenderID] )) {
					$values [$SenderID] = 0;
					$this->SaveValueTable ( $values );
					$this->OnInputValueChanged ( $SenderID );
				}
				break;

			case VM_UPDATE :
				$this->SendDebug ( __FUNCTION__, "TimeStamp $TimeStamp, SenderID $SenderID, Message $Message, Data => " . json_encode ( $Data ), 0 );
				$values = $this->LoadValueTable ();
				if (! empty ( $values [$SenderID] )) {
					if (is_numeric ( $Data [0] ) && $Data [0] != $values [$SenderID]) {
						$values [$SenderID] = $Data [0];
						$this->SaveValueTable ( $values );
						$this->OnInputValueChanged ( $SenderID );
					}
				}
				break;
		}
	}
	function RequestAction($Ident, $Value) {
		$this->SendDebug ( __FUNCTION__, "$Ident => $Value", 0 );
		if ($Ident == "ASSIGN_COND") {
			$outputs = $this->LoadOutputs ();
			$changed = false;
			list ( $itemIdent, $itemCond ) = explode ( '|', $Value );
			$found = array_filter ( $outputs, function ($i) use ($itemIdent) {
				return $i->ident == $itemIdent;
			} );
			if (! empty ( $output = array_shift ( $found ) )) {
				if (empty ( $itemCond ) || strlen ( $itemCond ) < 5) {
					$output->function = 1;
				} else
					$output->function = 0;
				$changed = $output->condition != $itemCond;
				$output->condition = $itemCond;
			}
			if ($changed) {
				IPS_SetProperty ( $this->InstanceID, 'OutputItems', json_encode ( $outputs ) );
				IPS_ApplyChanges ( $this->InstanceID );
			}
		} else
			$this->TimerHelperRequestAction ( $Ident, $Value );
	}
	public function Calc(string $Expression) {
		return $this->EvalExpression ( $Expression );
	}
	public function Sum() {
		return array_sum ( $this->LoadValueTable ( true ) );
	}
	public function Min() {
		return min ( $this->LoadValueTable ( true ) );
	}
	public function Max() {
		return max ( $this->LoadValueTable ( true ) );
	}
	public function Middle() {
		$max = $this->Max ();
		$min = $this->Min ();
		if ($max != $min || $max != 0) {
			$v = ($max - $min) / 2;
		} else
			$v = 0;
		return $v ? round ( $v, 5 ) : 0;
	}
	public function OutputValue(string $OutputName) {
		$return = [ ];
		$outputs = $this->LoadOutputs ();
		$runTable = $this->LoadRunTable ();
		$found = array_filter ( $outputs, function ($i) use ($OutputName) {
			return preg_match ( '/.*' . $OutputName . '.*/i', $i->name );
		} );
		foreach ( $found as $output ) {
			$return [$output->name] = $runTable [$output->ident] ['result'];
		}
		return (count ( $return ) == 1) ? array_shift ( $return ) : $return;
	}
	protected function RegisterReference($ID) {
		parent::RegisterReference ( $ID );
		$this->RegisterMessage ( $ID, VM_DELETE );
		$this->RegisterMessage ( $ID, VM_UPDATE );
	}
	protected function UnregisterReference($ID) {
		parent::UnregisterReference ( $ID );
		$this->UnregisterMessage ( $ID, VM_DELETE );
		$this->UnregisterMessage ( $ID, VM_UPDATE );
	}
	// *********** Load / Save Data **************
	private $InputCache = null;
	private function LoadInputs() {
		return $this->InputCache ?? $this->InputCache = json_decode ( $this->ReadPropertyString ( 'ValueItems' ) );
	}
	private $OutputCache = null;
	private function LoadOutputs() {
		return $this->OutputCache ?? $this->OutputCache = json_decode ( $this->ReadPropertyString ( 'OutputItems' ) );
	}
	private $ValueCache = null;
	private function LoadValueTable($ValuesOnly = false) {
		$r = $this->ValueCache ?? $this->ValueCache = json_decode ( $this->ReadAttributeString ( 'ValueTable' ), true );
		if ($this->FilterString) $this->FilterValueTable ( $r );
		return $ValuesOnly ? array_values ( $r ) : $r;
	}
	private function SaveValueTable(array $Values) {
		$this->WriteAttributeString ( 'ValueTable', json_encode ( $this->ValueCache = $Values ) );
	}
	private $RunCache = null;
	private function LoadRunTable() {
		return $this->RunCache ?? $this->RunCache = json_decode ( $this->ReadAttributeString ( 'RunTable' ), true );
	}
	private function SaveRunTable(array $Items, $SkipUpdate = false) {
		$this->SendDebug ( __FUNCTION__, "Data => " . json_encode ( $Items ), 0 );
		$changed = $this->ReadAttributeString ( 'RunTable' ) != json_encode ( $Items );
		if (! $changed) return;
		$this->WriteAttributeString ( 'RunTable', json_encode ( $this->RunCache = $Items ) );
		if (! $SkipUpdate) $this->UpdateOutputActivityVariables ();
	}
	// *********** Formular lists **************
	private function GetFormInputs() {
		$inputs = $this->LoadInputs ();
		$values = $this->LoadValueTable ();
		$changed = false;
		$type = 0;
		foreach ( $inputs as $input ) {
			$input->value = $this->GetInputValue ( $input->variableID, $type );
			$input->id = $input->variableID;
			if (is_string ( $input->value )) {
				$inputs->rowColor = '#FFC0C0';
				if ($values [$input->variableID] != 0) {
					$values [$input->variableID] = 0;
					$changed = true;
				}
				continue;
			}
			if ($values [$input->variableID] != $input->value) {
				$values [$input->variableID] = $input->value;
				$changed = true;
			}
			$input->value .= ' (' . [ 
				'bool',
				'int',
				'float'
			] [$type] . ')';
		}
		if ($changed) $this->SaveValueTable ( $values );
		return $inputs;
	}
	private function GetFormOutputs() {
		$outputs = $this->LoadOutputs ();
		$runTable = $this->LoadRunTable ();
		foreach ( $outputs as $output ) {
			$output->value = $this->GetOutputValue ( $output );
			if (is_string ( $output->value )) {
				$output->rowColor = '#FFC0C0';
			}
			$runTable [$output->ident] ['lastRun'] = time ();
			$runTable [$output->ident] ['result'] = $output->value;
		}
		$this->SaveRunTable ( $runTable, true );
		return $outputs;
	}
	private function GetFormActivitys() {
		$outputs = $this->LoadOutputs ();
		$OutputName = function ($Ident) use ($outputs) {
			$f = array_filter ( $outputs, function ($i) use ($Ident) {
				return $i->ident == $Ident;
			} );
			return empty ( $f ) ? 'Unknown' : (array_shift ( $f ))->name;
		};
		$runTable = $this->LoadRunTable ();
		$returns = [ ];
		$never = $this->Translate ( "never" );
		$onchanges = $this->Translate ( "On Changes" );
		$nodata = $this->Translate ( "no Data" );
		$datefmt = $this->Translate ( "H:i:s - d.m.Y" );
		foreach ( $runTable as $outputIdent => $task ) {
			$item = [ 
				'name' => $OutputName ( $outputIdent ),
				'lastRun' => empty ( $task ['lastRun'] > 0 ) ? $never : Date ( $datefmt, $task ['lastRun'] ),
				'nextRun' => empty ( $task ['nextRun'] > 0 ) ? ($task ['nextRun'] < 0 ? $onchanges : $never) : Date ( $datefmt, $task ['nextRun'] ),
				'result' => $task ['result'] ?? $nodata
			];
			if (is_string ( $task ['result'] )) $item ['rowColor'] = '#FFC0C0';
			elseif (is_float ( $task ['result'] )) $item ['result'] = round ( $task ['result'], 3 );
			$returns [] = $item;
		}
		$this->SendDebug ( __FUNCTION__, "Data => " . json_encode ( $returns ), 2 );
		return $returns;
	}
	// *********** Input/Output Values **************
	private function GetInputValue(int $VariableID, int &$Type = null) {
		if (empty ( $VariableID )) {return $this->Translate ( "VariableID missing!" );}
		if (! IPS_VariableExists ( $VariableID )) {return $this->Translate ( "Variable exist!" );}
		$variable = IPS_GetVariable ( $VariableID );
		switch ($Type = $variable ['VariableType']) {
			case 0 :
				return intval ( $variable ['VariableValue'] );
				break;
			case 1 :
				return intval ( $variable ['VariableValue'] );
				break;
			case 2 :
				return floatval ( $variable ['VariableValue'] );
				break;
			case 3 :
				return $this->Translate ( "Strings not supported" );
				break;
			default :
				return $this->Translate ( "Unknow Type" ) . ' => ' . $Type;
		}
	}
	private function GetOutputValue($Output) {
		if (empty ( $Output->name )) {
			return $this->Translate ( "Name not set" );
		} elseif ($Output->function == 0 && empty ( $Output->condition )) {return $this->Translate ( "No Condition set" );}
		$this->SetValueTableFilter ( $Output->filter );
		switch ($Output->function) {
			case 0 :
				$r = $this->EvalExpression ( $Output->condition, [ 
					$Output->ident
				] );
				break;
			case 1 :
				$r = $this->Sum ();
				break;
			case 2 :
				$r = $this->Min ();
				break;
			case 3 :
				$r = $this->Max ();
				break;
			case 4 :
				$r = $this->Middle ();
				break;
			default :
				$r = $this->Translate ( "Unknown Function" );
				break;
		}
		$this->SetValueTableFilter ( '' );
		return $r;
	}
	// *********** Output Variables Update **************
	private function UpdateOutputVariable($Output) {
		if (is_string ( $Output->value )) {
			$this->SendDebug ( __FUNCTION__, "Error in Output '$Output->name' => " . $Output->value, 1 );
			return;
		}
		if (! $Output->create) return;
		$var_type_v = null;
		if ($var_id = @$this->GetIDForIdent ( $Output->ident )) {
			$var = IPS_GetVariable ( $var_id );
			$var_type_v = $var ['VariableType'];
		}
		$var_type = $var_type_v ?? is_float ( $Output->value )? 2 : 1;
		if ($var_id && empty ( $var ['VariableCustomProfile'] ) && is_float ( $Output->value ) && $var_type != 2) {
			if ($this->DeleteVariable ( $var_id )) {
				$var_id = null;
			}
		}
		switch ($var_type) {
			case 0 :
				if (! $var_id) $var_id = $this->RegisterVariableBoolean ( $Output->ident, $Output->name );
				SetValue ( $var_id, boolval ( $Output->value ) );
				break;
			case 1 :
				if (! $var_id) $var_id = $this->RegisterVariableInteger ( $Output->ident, $Output->name );
				SetValue ( $var_id, intval ( $Output->value ) );
				break;
			case 2 :
				if (! $var_id) $var_id = $this->RegisterVariableFloat ( $Output->ident, $Output->name );
				SetValue ( $var_id, floatval ( $Output->value ) );
				break;
		}
	}
	private function UpdateVariables(string $Ident = '') {
		$outputs = $this->LoadOutputs ();
		foreach ( $outputs as $output ) {
			$output->value = $this->GetOutputValue ( $output );
			$this->UpdateOutputVariable ( $output );
		}
	}
	private function DeleteVariable($VarID, $Save = true) {
		if (! IPS_VariableExists ( $VarID )) return true;
		if ($this->ReadPropertyBoolean ( 'SaveDelete' )) {
			$utilsID = IPS_GetInstanceListByModuleID ( '{B69010EA-96D5-46DF-B885-24821B8C8DBD}' ) [0] ?? 0;
			$ref = $utilsID ? UC_FindReferences ( $utilsID, $VarID ) : [ ];
			if (count ( $ref ) > 0) {
				$this->SendDebug ( __FUNCTION__, "Skip Delete! References Exists", 1 );
				return false;
			}
		}
		return IPS_DeleteVariable ( $VarID );
	}
	private function UpdateOutputActivityVariables() {
		$fl = null;
		if ($this->ReadPropertyBoolean ( 'ShowActivity' )) {
			$fl = $this->GetFormActivitys ();
			$this->UpdateFormField ( 'ValuesActivity', 'values', json_encode ( $fl ) );
		}
		if ($this->ReadPropertyBoolean ( 'CreateActivityVar' )) {
			$header = [ 
				$this->Translate ( "Last Run" ),
				$this->Translate ( "Next Run" ),
				$this->Translate ( "Last Result" )
			];
			$table = '<table><td><tr>' . implode ( '</tr><tr>', $header ) . '</tr></td>';
			if (! $fl) $fl = $this->GetFormActivitys ();
			foreach ( $fl as $task ) {
				$color = empty ( $task ['rowColor'] ) ? '' : " style=\"background-color:{$task['rowColor']};\"";
				$table .= sprintf ( "<td%s><tr>%s</tr><tr>%s</tr><tr>%s</tr></td>", $color, $task ['lastRun'], $task ['nextRun'], $task ['result'] );
			}
			$table .= '</table>';
			if (! ($var_id = @$this->GetIDForIdent ( 'ACTIVITY' ))) {
				$var_id = $this->RegisterVariableString ( 'ACTIVITY', $this->Translate ( "Activity" ), '~HTMLBox' );
			}
			SetValue ( $var_id, $table );
		}
	}
	// *********** Events **************
	private function OnInputValueChanged(int $VariableID) {
		$outputs = $this->LoadOutputs ();
		$runTable = $this->LoadRunTable ();
		foreach ( $outputs as $output ) {
			if ($output->interval == - 1) {
				$runTable [$output->ident] ['result'] = $output->value = $this->GetOutputValue ( $output );
				$runTable [$output->ident] ['lastRun'] = time ();
				$this->UpdateOutputVariable ( $output );
			}
		}
		$this->SaveRunTable ( $runTable );
	}
	// called from KernelHelper
	private function OnKernelReady() {
		$this->ApplyChanges ();
	}
	// called from TimerHelper
	private function OnTimer($Value) {
		$this->StopTimer ();
		$runTable = $this->LoadRunTable ();
		if (empty ( $runTable )) return;
		$now = time ();
		$changed = false;
		$outputs = $this->LoadOutputs ();
		foreach ( $outputs as $output ) {
			if (array_key_exists ( $output->ident, $runTable )) {
				$nextRun = $runTable [$output->ident] ['nextRun'];
				if ($nextRun > 0 && $now >= $nextRun) {
					$runTable [$output->ident] ['nextRun'] = $now + ($output->interval * 60);
					$runTable [$output->ident] ['result'] = $output->value = $this->GetOutputValue ( $output );
					$this->UpdateOutputVariable ( $output );
					$changed = true;
				}
			}
		}
		if ($changed) $this->SaveRunTable ( $runTable );
		$this->StartTimerByRunTable ( $runTable );
	}

	// *********** Calculate ***********
	private const MATH_OPERATORS = [ 
		'+',
		'-',
		'*',
		'\\',
		':',
		'(',
		')'
	];
	private const MATH_FUNCTIONS = [ 
		'round',
		'floor',
		'abs',
		'sqrt',
		'ceil',
		'fmod',
		'sqrt',
		'dechex',
		'hexdec',
		'intdiv'
	];
	private function EvalExpression(string $Expression, array $Owner = [ ]) {
		$found = $r = null;
		$Expression = trim ( $Expression );
		if (empty ( $Expression )) {return $this->Translate ( "no Expression given!" );}
		switch ($Expression) {
			case '*' :
				return $this->Sum ();
			case '+' :
				return $this->Max ();
			case '-' :
				return $this->Min ();
		}
		$varValues = $this->LoadValueTable ();
		$values = [ ];
		foreach ( $this->LoadInputs () as $input ) {
			$values [$input->ident] = $varValues [$input->variableID];
		}
		$outputs = [ ];
		foreach ( $this->LoadOutputs () as $output ) {
			if (! in_array ( $output->ident, $Owner )) {
				$outputs [$output->name] = $output;
			}
		}
		if (preg_match_all ( '/([#\wäöüÄÖÜß]+|\d+|[\(\)\+\-\*\/\:])[ ]*/', $Expression, $found )) {
			$found = $found [0];
			foreach ( $found as $index => $token ) {
				$token = trim ( $token );
				if (empty ( $token )) continue;
				if (is_numeric ( $token )) {
					if (strlen ( $token ) == 5) {
						foreach ( $values as $text => $value ) {
							if (strpos ( $text, $token . ' ' ) !== false) {
								$found [$index] = $value;
							}
						}
					}
					continue;
				}
				if (in_array ( $lt = strtolower ( $token ), [ 
					'sum',
					'min',
					'max',
					'mid',
					'middle'
				] )) {
					switch ($lt) {
						case 'sum' :
							$found [$index] = $this->Sum ();
							break;
						case 'min' :
							$found [$index] = $this->Min ();
							break;
						case 'max' :
							$found [$index] = $this->Max ();
							break;
						case 'mid' :
						case 'middle' :
							$found [$index] = $this->Middle ();
							break;
					}
					continue;
				}
				if (in_array ( $token, self::MATH_FUNCTIONS )) {
					continue;
				}
				if (in_array ( $token, self::MATH_OPERATORS )) {
					if ($token == ':') $found [$index] = '/';
					continue;
				}

				// Check if Token is a Input Item
				$ok = false;
				foreach ( $values as $text => $value ) {
					if (preg_match ( '/' . $token . '/i', $text )) {
						$found [$index] = $value;
						$ok = true;
					}
				}
				// Check if Token is a Output Item
				if (! $ok) {
					foreach ( $outputs as $text => $output ) {
						if (strcasecmp ( $text, $token ) == 0) {
							if ($output->function > 0) $this->SetValueTableFilter ( $output->filter );
							switch ($output->function) {
								case 0 :
									$Owner [] = $output->ident;
									$found [$index] = $this->EvalExpression ( $output->condition, $Owner );
									break;
								case 1 :
									$found [$index] = $this->Sum ();
									break;
								case 2 :
									$found [$index] = $this->Min ();
									break;
								case 3 :
									$found [$index] = $this->Max ();
									break;
								case 4 :
									$found [$index] = $this->Middle ();
									break;
								default :
									$found [$index] = 0;
							}
							if ($output->function > 0) $this->SetValueTableFilter ( '' );
							$ok = true;
						}
					}
				}

				if (! $ok) {
					$err = sprintf ( $this->Translate ( "Token '%s' not found!" ), $token );
					$this->SendDebug ( __FUNCTION__, $err, 0 );
					return $err;
				}
			}
		}
		// Check off only numeric values;
		foreach ( $found as &$token ) {
			if (! is_numeric ( $token ) && ! in_array ( $token, self::MATH_FUNCTIONS ) && ! in_array ( $token, self::MATH_OPERATORS )) {
				$err = sprintf ( $this->Translate ( "Token '%s' not found!" ), $token );
				$this->SendDebug ( __FUNCTION__, $err, 0 );
				return $err;
			}
		}
		$eval = '$r=' . implode ( $found ) . ';';
		$decimal_point = localeconv () ['decimal_point'];
		if ($decimal_point != '.') $eval = str_replace ( $decimal_point, '.', $eval );
		$r = $e = null;
		$eval = 'try {' . $eval . '} catch (Exception $ex) {$e=$ex->getMessage();};';
		$this->SendDebug ( __FUNCTION__, "Eval => $eval", 0 );
		eval ( $eval );
		$this->SendDebug ( __FUNCTION__, "Result => " . ($r ?? $e), 2 );
		return $r;
	}
	// *********** Filter **************
	private $FilterString = null;
	private function SetValueTableFilter(string $Filter) {
		$Filter = trim ( $Filter );
		$this->FilterString = strlen ( $Filter ) < 5 ? '' : $Filter;
	}
	private function FilterValueTable(array &$Values) {
		if (empty ( $this->FilterString )) return;
		$filters = explode ( ';', $this->FilterString );
		$found = null;
		$filterIDs = [ ];
		$result = true;
		if ((is_numeric ( $filters [0] ) || strpos ( $filters [0], ',' ) > 0) && preg_match_all ( '/(\d{5})/', $filters [0], $found )) {
			$filterIDs = $found [1];
			array_shift ( $filters );
		}
		foreach ( $filters as $filter ) {
			if (empty ( $filter )) continue;
			if (is_numeric ( $filter ) && array_key_exists ( $filter, $Values )) {
				if (! in_array ( $filter, $filterIDs )) $filterIDs [] = $filter;
				continue;
			}
			if (preg_match_all ( '/([#\wäöüÄÖÜß]+|\d+|[\>\<\=|&\(\)]+)/', $filter, $found )) {
				$found = $found [0];
				foreach ( $found as $index => $token ) {
					$token = trim ( $token );
					if (empty ( $token )) continue;
					if (is_numeric ( $token )) {
						if (strlen ( $token ) == 5) {
							if (array_key_exists ( $token, $Values )) {
								$found [$index] = $Values [$token];
							} else {
								$this->SendDebug ( __FUNCTION__, "Value for Object $token not found", 0 );
								return false;
							}
						}
						continue;
					}
					if ($token == '=') $token = '==';
				}
				$eval = '$r=' . implode ( $found ) . ';';
				$decimal_point = localeconv () ['decimal_point'];
				if ($decimal_point != '.') $eval = str_replace ( $decimal_point, '.', $eval );
				$r = $e = null;
				$eval = 'try {' . $eval . '} catch (Exception $ex) {$e=$ex->getMessage();};';
// echo "eval: $eval;\n";
				eval ( $eval );
				if ($e || ! $r) {
					if (! $r) {
						$result = false;
						break;
					}
					$this->SendDebug ( __FUNCTION__, 'EVAL Error => ' . $e, 0 );
				}
			}
		}
		if (! $result) {
			$Values = [ ];
		} elseif (! empty ( $filterIDs )) {
			foreach ( array_keys ( $Values ) as $id ) {
				if (! in_array ( $id, $filterIDs )) {
					unset ( $Values [$id] );
				}
			}
		}
		return $result;
	}
	// *********** Helpers *************
	private function StartTimerByRunTable(array $RunTable) {
		$found = array_filter ( $RunTable, function ($i) {
			return $i ['nextRun'] > 0;
		} );
		$allTimes = array_map ( function ($i) {
			return $i ['nextRun'];
		}, $found );
		$this->StartTimerByNext ( $allTimes );
	}
}
?>