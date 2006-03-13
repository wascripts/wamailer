<?php

function mail($recipients, $subject, $message, $headers)
{
	$pattern = '(.*<)?([-!#$%&\'*+\\/0-9=?a-z^_`{|}~.]+)@[-a-z0-9.]+(?(1)>)(?:\\r\\n|$)';
	
	if( !preg_match("/From: $pattern/Ui", $headers, $match) ) {
		throw new Exception("mail() (online): Custom \"From:\" header needed");
	}
	
	$from = $reply = $match[2];
	$headers = str_replace($match[0], '', $headers);
	
	if( preg_match("/Reply-To: $pattern/Ui", $headers, $match) ) {
		$reply = $match[2];
		$headers = str_replace($match[0], '', $headers);
	}
	
	return email($from, $recipients, $subject, $message, $reply, $headers);
}

?>
