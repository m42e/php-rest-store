<?php

require("vendor/autoload.php");

use Respect\Rest\Router;

define('SALT', "YOUR SALT");

function getfilename($id){
	return 'data/'.$id;
}

function getInfo($origid = null){
	return array(
		'date' => date(DATE_RFC2822),
		'info' => array(
			'useragent' => $_SERVER['HTTP_USER_AGENT'],
			'remote' => $_SERVER['REMOTE_ADDR']),
		'length' =>	$_FILES['file']['size'],
		'accessedvia' => $origid,
		);
}

function is_sha1($str) {
    return (bool) preg_match('/^[0-9a-f]{40}$/i', $str);
}

function getIds($id){
	$origid = $id;
	if(!is_sha1($id)){
		$origid = $id;
		$id = sha1($id.SALT);
	}
	return array($id, $origid);
}

function getMeta($meta){
	$data = array();
	for($runthrough = 0; $runthrough < count($meta); $runthrough += 2){
		$data[$meta[$runthrough]] = urldecode($meta[$runthrough + 1]);
	}
	return $data;
}

function copyFile($target, $mode){
	/* copy the input data */
	$input = fopen($_FILES['file']['tmp_name'], 'rb');
	$file = fopen($target, $mode);
	stream_copy_to_stream($input, $file);
	fclose($input);
	fclose($file);
}

$r3 = new Router();
$r3->isAutoDispatched = false;

$r3->get('/', function() {
	return '';
});

$r3->get('/newid/*', function($id) {
	list($id, ) = getIds($id);
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
	list($id, ) = getIds($id);
	if(file_exists(getfilename($id).$meta)){
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
	list($id, $origid) = getIds($id);
	if(file_exists(getfilename($id))){
		$data = array(
			'written' => getInfo($origid)
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
		copyfile(getfilename($id), 'wb');
		/* save the metadata */
		if($metaavailable != null){
			$data['written']['meta'] = getMeta($meta);
		}
		file_put_contents(getfilename($id).'.meta', json_encode( $data));
	}
});


$r3->post('/prepend/*/*/**', function($id, $metaavailable=null, $meta = null) {
	list($id, $origid) = getIds($id);
	if(file_exists(getfilename($id))){
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
		$newdata = getInfo($origid);
		if($metaavailable != null){
			$newdata['meta'] = getMeta($meta);
		}
		$data['prepended'][] = $newdata;
		file_put_contents(getfilename($id).'.meta', json_encode($data));
	}
});

$r3->post('/append/*/*/**', function($id, $metaavailable=null, $meta = null) {
	list($id, $origid) = getIds($id);
	if(file_exists(getfilename($id))){
		copyfile(getfilename($id), 'ab');
		$data = json_decode(file_get_contents(getfilename($id).'.meta'), true);
		$newdata = getInfo($origid);
		if($metaavailable != null){
			$newdata['meta'] = getMeta($meta);
		}
		$data['appended'][] = $newdata;
		file_put_contents(getfilename($id).'.meta', json_encode($data));
	}
});

echo $r3->run();
