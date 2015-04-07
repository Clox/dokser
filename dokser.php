#!/usr/bin/php
<?php
require_once './assets/dokserClass.php';
if (PHP_SAPI === 'cli') {
	if ($argc==1) {
		die ('Dokser is an application used to create API documents from a  source folder.'.PHP_EOL
		. 'Created by Oscar Jonsson (github.com/clox).'.PHP_EOL
		. 'Run "dokser.php --help" for help.');
	}
	$command=strtolower($argv[1]);
	
	
	
	if ($command=='-h'||$command=='--help') {
		echo "Usage: dokser.php (command) [options]".PHP_EOL
			."To get help on a specific command, do: dokser.php (command) --help".PHP_EOL
			."I.e. dokser.php generate --help".PHP_EOL.PHP_EOL
			."List of commands:".PHP_EOL
			."generate\t";
	} else if ($command[0]=='-') {
		die ('Error: Command missing!');
	} else if ($command=='generate'){
		$opts=getOptions('hio',['help','input','output']);
		if (key_exists('help', $opts)) {
			echo "A command used to generate a folder of API documentation from an input folder.".PHP_EOL
				."-i and -o are mandatory".PHP_EOL.PHP_EOL
				."-i, --input\t Path to input directory or input file".PHP_EOL
				."-o, --output\t Path to output directory.";
		}
		if (!key_exists('input', $opts)||!key_exists('output', $opts))
			die ("options --input and --output are mandatory for command generate.");
		$files=Dokser::generate($opts['input'], false);
	}
}
function getOptions($short,$long) {
	global $argc,$argv;
	$output=[];

	for ($i=2; $i<$argc; $i++) {
		$option=$argv[$i];
		if ($option[0]=='-') {
			$index=array_search(ltrim($option,'-'), $option[1]=='-'?$long:str_split($short));
			if ($index===false)
				die ("Error: Unknown option $option");
			$value=null;
			if (isset($argv[$i+1])&&$argv[$i+1][0]!='-')
				$value=$argv[++$i];
			$output[$long[$index]]=$value;
		}
	}
	return $output;
}