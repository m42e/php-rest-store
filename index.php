<?php

require("vendor/autoload.php");

use Respect\Rest\Router;

define('SALT', "<CHANGE HERE>");

function getfilename($id){
	return 'data/'.$id;
}

function getInfo(){
	return array(
		'date' => date(DATE_RFC2822),
		'info' => array(
			'useragent' => $_SERVER['HTTP_USER_AGENT'],
			'remote' => $_SERVER['REMOTE_ADDR'])
		);
}

function is_sha1($str) {
    return (bool) preg_match('/^[0-9a-f]{40}$/i', $str);
}

$r3 = new Router();
$r3->isAutoDispatched = false;

$r3->get('/', function() {
	return '';
});

$r3->get('/newid/*', function($code) {
	$id = sha1(($code).SALT);
	touch(getfilename($id));
	touch(getfilename($id).'.meta');
	return $id;
});

$r3->get('/get/*/*', function($id,$meta=null) {
	$data = '';
	if($meta != null){
		if($meta == "meta"){
			$meta = '.meta';
		}elseif(is_sha1($meta)){
			$meta = '.'.$meta;
		}else{
			return "fail";
		}
	}
	if(!is_sha1($id)){
		$id = sha1($id.SALT);
	}
	if(is_sha1($id) && file_exists(getfilename($id).$meta)){
		$data = file_get_contents(getfilename($id).$meta);
	}
	return $data;
})->accept(array(
	'text/html' => function($origdata) {
			json_decode($origdata);
			if(json_last_error() === JSON_ERROR_NONE){
				$data = json_encode(json_decode($origdata), JSON_PRETTY_PRINT);
			}
			else{
				$data = $origdata;
			}
		return '<html><body><pre>'.$data.'</pre></body></html>';
	},
	'text/text' => function($data) {
		return $data;
	},
	'text/json' => function($origdata) {
		if(is_array($origdata)){
			$data = json_encode($origdata);
		}else{
			json_decode($origdata);
			if(json_last_error() !== JSON_ERROR_NONE){
				$data = json_encode(array('content'=>$origdata));
			}
			else{
				$data = $origdata;
			}
		}
		return $data;
	},
));

$r3->post('/put/*/*/**', function($id, $metaavailable=null, $meta=null) {
	if(!is_sha1($id)){
		$id = sha1($id.SALT);
	}
	if(is_sha1($id) && file_exists(getfilename($id))){
		$data = array(
			'written' => getInfo()
		);
		/* save the original data to history */
		$origdata = json_decode(file_get_contents(getfilename($id).'.meta'), true);
		if(is_array($origdata)){
			/* move the old file */
			$oldid = sha1(date(DATE_RFC2822).SALT);
			$origdata['historyid'] = $oldid;
			rename(getfilename($id), getfilename($id).'.'.$oldid);
			/* add history entry */
			if(array_key_exists('history', $origdata)){
				$data['history'] = $origdata['history'];
				unset($origdata['history']);
				array_unshift($data['history'], $origdata);
			}else{
				$data['history'][] = $origdata;
			}
		}
		/* copy the input data */
		$input = fopen($_FILES['file']['tmp_name'], 'rb');
		$file = fopen(getfilename($id), 'wb');
		stream_copy_to_stream($input, $file);
		fclose($input);
		fclose($file);
		$data['written']['length'] = $_FILES['file']['size'];
		/* save the metadata */
		if($metaavailable != null){
			for($runthrough = 0; $runthrough < count($meta); $runthrough += 2){
				$data['written']['meta'][$meta[$runthrough]] = $meta[$runthrough + 1];
			}
		}
		file_put_contents(getfilename($id).'.meta', json_encode( $data));
	}
});


$r3->post('/prepend/*/*/**', function($id, $metaavailable=null, $meta = null) {
	if(!is_sha1($id)){
		$id = sha1($id.SALT);
	}
	if(is_sha1($id) && file_exists(getfilename($id))){
		$data = file_get_contents($_FILES['file']['tmp_name']);

		$handle = fopen(getfilename($id), "r+");
		$len = strlen($data);
		$final_len = filesize(getfilename($id)) + $len;
		$cache_old = fread($handle, $len);
		rewind($handle);
		$i = 1;
		while (ftell($handle) < $final_len) {
			fwrite($handle, $data);
			$data = $cache_old;
			$cache_old = fread($handle, $len);
			fseek($handle, $i * $len);
			$i++;
		}
		$data = json_decode(file_get_contents(getfilename($id).'.meta'), true);
		$newdata = getInfo();
		$newdata['length'] = $_FILES['file']['size'];
		if($metaavailable != null){
			for($runthrough = 0; $runthrough < count($meta); $runthrough += 2){
				$newdata['meta'][$meta[$runthrough]] = $meta[$runthrough + 1];
			}
		}
		$data['prepended'][] = $newdata;
		file_put_contents(getfilename($id).'.meta', json_encode($data));
	}
});

$r3->post('/append/*/*/**', function($id, $metaavailable=null, $meta = null) {
	if(!is_sha1($id)){
		$id = sha1($id.SALT);
	}
	if(is_sha1($id) && file_exists(getfilename($id))){
		/* append the input data */
		$input = fopen($_FILES['file']['tmp_name'], 'rb');
		$file = fopen(getfilename($id), 'ab');
		stream_copy_to_stream($input, $file);
		fclose($input);
		fclose($file);
		$data = json_decode(file_get_contents(getfilename($id).'.meta'), true);
		$newdata = getInfo();
		$newdata['length'] = $_FILES['file']['size'];
		if($metaavailable != null){
			for($runthrough = 0; $runthrough < count($meta); $runthrough += 2){
				$newdata['meta'][$meta[$runthrough]] = $meta[$runthrough + 1];
			}
		}
		$data['appended'][] = $newdata;
		file_put_contents(getfilename($id).'.meta', json_encode($data));
	}
});

echo $r3->run();
