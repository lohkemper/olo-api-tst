<?php

$protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
$code = 204;
$text = 'No Content';

header( $protocol . ' ' . $code . ' ' . $text );

$GLOBALS['http_response_code'] = $code;
?>