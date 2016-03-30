
À propos de Dkim
=================

L'API et les noms des options sont désormais stables, mais l'espace de noms
de la classe peut être amené à changer à l'avenir, d'ici à ce que Wamailer 4
soit marqué comme stable.

Not stable/bugs:

 * namespace
 * Need to transform LF into CRLF before calling `Dkim::sign()`
 * Errors handling : `Dkim::sign()` may throws exception in future (not sure)


Standalone example
-------------------

    require 'lib/Filters/Dkim.php';

    $opts['domain']   = 'mydomain.tld';
    $opts['selector'] = 'selector';
    $opts['privkey']  = '/path/to/private.key'; // Or a PEM formatted key

    $from    = 'bob@mydomain.tld';
    $to      = 'alice@otherdomain.tld';
    $subject = 'Test Mail with DKIM';
    $date    = date('r');

    $body = "This is an example of DKIM signed mail.\r\nBye!\r\n";

    // Headers for mail() function
    $mail_headers = "From: $from
    Date: $date
    MIME-Version: 1.0
    Content-Type: text/plain";

    $headers = "To: $to\r\nSubject: $subject\r\n".$mail_headers;

    // $headers and $body must contain <CR><LF>, not <LF> alone!
    $headers = preg_replace('#(?<!\r)\n#', "\r\n", $headers);
    $body = preg_replace('#(?<!\r)\n#', "\r\n", $body);

    $dkim = new \Wamailer\Filters\Dkim($opts);
    $dkim_header = $dkim->sign($headers, $body);

    $headers = $dkim_header . $mail_headers;
    mail($to, $subject, $body, $headers);

