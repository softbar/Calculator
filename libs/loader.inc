<?php
spl_autoload_register(function($Class){
	if(file_exists(__DIR__."/$Class.inc")){
		require_once __DIR__."/$Class.inc";;
	}
});
?>