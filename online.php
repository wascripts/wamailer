<?php

function mail($recipients, $subject, $message, $headers)
{
	$pattern = '(.*<)?([^@]+@[^>]+)(?(1)>)(\r\n|$)';
	
	if( !preg_match("/From: $pattern/Ui", $headers, $match) ) {
		throw new Exception("Mailer::send() : from address needed!");
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
