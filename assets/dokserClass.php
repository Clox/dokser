<?php
class Dokser {
	private static $labelReg='[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*';
	public static function generate ($input,$output) {
		$inputFiles=Dokser::directoryToArray($input, true);
		$outputFileDescribers=[];
		foreach ($inputFiles as $inputFile) {
			$extension=strtolower(substr($inputFile,-4,4));
			if ($extension==".php") {
				$outputFileDescribers[]=Dokser::processFileContentsPHP(file_get_contents($inputFile));
			}
		}
	}
	private static function processFileContentsPHP($contents) {
		$tokens=token_get_all ($contents);
		$descriptor=['functions'=>[]];
		
		preg_match('/<\?php\s/',$contents,$match,PREG_OFFSET_CAPTURE);
		$pos=$match[0][1]+strlen($match[0][0]);
		$contentsLength=strlen($contents);
		while (preg_match('#/\*|//|\x27|\x22|function|class|\?>#i',$contents,$match,PREG_OFFSET_CAPTURE,$pos)) {
			$pos=$match[0][1];
			$str=strtolower($match[0][0]);
			if ($str=='/*'||$str=='//'||$str=='"'||$str=="'") {
				$pos=Dokser::skipWhitespaceAndComments($contents, $pos);
			} else if (strpos($str,'function')!==false) {
				$descriptor['functions'][]=Dokser::processFunction($contents, $pos);
			} else if (strpos($str,'class')!==false) {
				return;
			}
		}
	}
	
	/**
	 * Method that reads a function decleration and returns a descriptor structure of it
	 * @param string $contents Contents to read from
	 * @param type $pos Position of the first character of the function-keyword. This is also an out argument
	 *		and when the method has finishes it will point at the first character succeeding the function decleration.
	 * @return array An array of the following structure:<pre>
	 *		0=>
	 *		    'name'=> string Name of the function
	 *		    'params'=> array List of parameters
	 *		        0=>
	 *		            'name'=> string Name of the parameter
	 *		            'defaultValue'=> string|void String of its default value, or void if it has none
	 *		        ...
	 *		...
	 * </pre>*/
	private static function processFunction($contents,&$pos) {
		//do away with extra whitespace between it and the functionname
		$pos=Dokser::skipWhitespaceAndComments($contents, $pos+strlen('function'));
		preg_match('/'.Dokser::$labelReg.'/',$contents,$match,PREG_OFFSET_CAPTURE,$pos);//get functionname
		$functionName=$match[0][0];
		$pos+=strlen($functionName);
		$function=['name'=>$functionName,'params'=>[]];
		$pos=Dokser::skipWhitespaceAndComments($contents, $pos)+1;//skip to "(" then skip that as well
		$pos=Dokser::skipWhitespaceAndComments($contents, $pos);//skip whitespace right after "("
		while (preg_match($reg='#,?(&?\$'.Dokser::$labelReg.'|\))#',$contents,$match,0,$pos)
				&&($paramName=$match[1])!=')') {
			$param=['name'=>$paramName];
			$pos=Dokser::skipWhitespaceAndComments($contents, $pos+strlen($paramName));
			if ($contents[$pos]=='=') {
				$pos=Dokser::skipWhitespaceAndComments($contents, $pos+1);//skip whitespace after "="
				$pos=Dokser::consumeValue($contents, $pos, $valueString);
				$param['defaultValue']=$valueString;
			}
			$function['params'][]=$param;
		}
		++$pos;//after the loop $pos points at right before ")" after possible parameters
		return $function;
	}
	private static function skipWhitespaceAndComments($haystack,$pos) {
		while ($pos<strlen($haystack)){
			if (preg_match('/\s+/',$haystack,$match,PREG_OFFSET_CAPTURE,$pos)&&$match[0][1]==$pos) {
				$pos+=strlen($match[0][0]);
			} else if ($haystack[$pos]=='/'&&($haystack[$pos+1]=='/')) {
				$pos=strpos($haystack,'\n',$pos)+1;
			} else if ($haystack[$pos]=='/'&&($haystack[$pos+1]=='*')) {
				$pos=strpos($haystack,'*/',$pos)+2;
			} else 
				return $pos;
		}
	}
	
	/**
	 * Method that comsumes a *value*, e.g. string,null,array,etc.
	 * @param string $contents Contents to read from
	 * @param int $pos Position of the first character of the value
	 * @param string $matchString Out argument of the whole consumed value, including quotes if string*/
	private static function consumeValue($contents,$pos,&$matchString) {
		/* Sample values this method should consume
		123
		'foobar'
		-123
		null
		[[1,2,3],['a'=3,'b'=>5]]
		*/
		$startPos=$pos;
		$char=$contents[$pos];
		if (strpos('"\'', $char)!==false) {
			$pos=Dokser::consumeString($contents, $pos);
		} else if (strpos('+-0123456789"\'', $char)!==false) {
			$pos=Dokser::consumeNumber($contents, $pos);
		} else if ($char=='[') {
			$pos=Dokser::consumeArray($contents,$pos);
		} else {
		//if it's not string,number or array then it could be a $variable or a CONSTANT or null? etc
			preg_match('/\$?[^a-zA-Z0-9_\x7f-\xff]/',$contents,$match,0,$pos);
			$pos+=strlen($match[0]);
		}
		$pos=Dokser::skipWhitespaceAndComments($contents, $pos);
		$char=$contents[$pos];
		if (preg_match('#&&|\|\|==|!=|<=|>=|[+-.&*/%|]#',$contents,$match,PREG_OFFSET_CAPTURE,$pos)&&$match[0][1]==0) {
			$pos+=strlen($match[0][0]);
			$pos=Dokser::consumeValue($contents,$pos);
		}
		$matchString=substr($contents, $startPos,$pos-$startPos);//set the out argument to the number-string
		return $pos;
	}
	
	/**
	 * Method that consumes a number
	 * @param string $contents Contents to read from
	 * @param int $pos Position of the opening quote of the string
	 * @param string $matchString Out argument of the consumed string including its quotes
	 * @return int Position of the first character after the closing quote of the string*/
	private static function consumeNumber ($contents,$pos,&$matchString) {
		/*_Some valid samples:_
		[123],[.123],[123.],[123.123],[-123.123],
		[+123.123], //The plus is not adding but simply (redundantly)denoting that it's a positive value
		[0123], //octal number (equivalent to 83 decimal)
		[0x1A], //hexadecimal number (equivalent to 26 decimal)
		[0b11111111] //binary number (equivalent to 255 decimal)*/
		$startPos=$pos;//save initial value of $pos
		//first get leading +/- if present out of the way
		if (strpos('-+', $contents[$pos]!==false))
			++$pos;
		
		//if a decimal point is encountered when reading non decimal then that is not part of the number,
		//but when reading decimal then one dot may be tolerated
		
		//if first character (after leading +/- if present, since already skipped) is . or 1-9(not 0)
		//determine if the number is decimal. if so then one decimal point in the number can be tolerated
		$tolerateDecimalPoint=strpos('123456789',$contents[$pos])!==false;
		
		//get first non 0123456789abcdefABCDEF
		//if that character was "." and $tolerateDecimalPoint==true then condition falls true
		if (preg_match('/[^\da-fA-F]/',$contents,$match,0,$pos)&&$match[0]=='.'&&$tolerateDecimalPoint)
			preg_match('/[^\d]/',$contents,$match,0,$pos+=strlen($match[0]));//...and we read in the rest
		$pos+=strlen($match[0]);//now $pos should be at the first character after the number
		$matchString=substr($contents, $startPos,$pos-$startPos);//set the out argument to the number-string
		return $pos;//return the position of the first character after the number
	}
	
	/**
	 * Method that consumes a string
	 * @param string $contents Contents to read from
	 * @param int $pos Position of the opening quote of the string
	 * @param string $matchString Out argument of the consumed string including its quotes
	 * @return int Position of the first character after the closing quote of the string*/
	private static function consumeString($contents,$pos,&$matchString) {
		$token=$contents[$pos];//$token should now be either ' or " given the method is used correctly
		$startPos=$pos++;//save initial value of $pos to $startPos and also advance $pos to after the quote
		
		//We now want to find the closing quote. However it obviously can't be an escaped one, e.g. \" if " opened.
		//So we can't just look for the quote character. But we can't simply look for quote character  which is not
		//preceeded by a \ either because for instance this one is
		//closing: \\"(since the first \ only escapes the second \) but not this \\\" but this is \\\\" etc
		
		//look for either closing quote or a backslash
		while (preg_match($reg='#\\\\|'.$token.'#',$contents,$match,PREG_OFFSET_CAPTURE,$pos)&&$match[0][0]=="\\")
			$pos=$match[0][1]+1; //if backslash is found then simply skip to the position after that backslash +1-
			//this will in effect skip to position after \\ or after \". it will not skip the whole escape sequence in
			//the case of for example \x26 (this evaluates to a "&" in a string) but that wont matter
		$pos=$match[0][1]+1;//now $pos should be at the position of the character succeeding the closing quote
		$matchString=substr($contents, $startPos,$pos-$startPos);//set the out argument to string inluding its quotes
		return $pos;//return the position of the first character after the closing quote
	}
	
	/**
	 * Method that consumes an array
	 * @param string $contents Contents to read from
	 * @param int $pos Position of the opening bracket of the array
	 * @param string $matchString Out argument of the consumed array including its brackets
	 * @return int Position of the first character after the closing bracket of the array*/
	private static function consumeArray($contents,$pos,&$matchString) {
		//Valid sample: [1,2,['bb','c'=>9],[1=>1,],]
		$startPos=$pos++;//save initial value of $pos to $startPos and also advance $pos to after the bracket
		$pos=Dokser::skipWhitespaceAndComments($contents, $pos);//skip possible whitespace after [
		while ($contents[$pos]!=']'){
			$pos=Dokser::consumeValue($contents, $pos);//consume value, or actually alternatively key as it may be
			$pos=Dokser::skipWhitespaceAndComments($contents, $pos);//skip whitespace after key/value
			if ($contents[$pos]=='=')//the just consumed value was key, skip '=>' and lt next iteration consume its value
				$pos+=2;
			else if ($contents[$pos]==',')//skip comma
				++$pos;
			else//if neither "," nor "=>" was encontered then it means $pos is now either at a value or "]" and then we
				continue;//we don't have to skip whitespace since it's already been done. otherwise if "," or "=>" was
			$pos=Dokser::skipWhitespaceAndComments($contents, $pos);//found we need to skip whitespace after it
		}
		++$pos;//go past closing bracket
		$matchString=substr($contents, $startPos,$pos-$startPos);//set the out argument to the array-string
		return $pos;//return the position of the first character after the closing bracket
	}
	
	private static function directoryToArray($directory, $recursive) {
	//Function stolen from http://stackoverflow.com/a/3827000/147949
		$array_items = array();
		if ($handle = opendir($directory)) {
			while (false !== ($file = readdir($handle))) {
				if ($file != "." && $file != "..") {
					if (is_dir($directory. "/" . $file)) {
						if($recursive) {
							$array_items=array_merge($array_items
									,Dokser::directoryToArray($directory."/".$file,$recursive));
						}
						$file = $directory . "/" . $file;
						$array_items[] = preg_replace("/\/\//si", "/", $file);
					} else {
						$file = $directory . "/" . $file;
						$array_items[] = preg_replace("/\/\//si", "/", $file);
					}
				}
			}
			closedir($handle);
		}
		return $array_items;
	}
}