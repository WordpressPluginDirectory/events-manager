<?php
namespace EM;

class WP_Screen {
	public function __get($prop){
		return '';
	}
	
	public static function __callStatic( $string, $args ){
		return '';
	}
	
	public function __call( $string, $args ){
		return '';
	}
}