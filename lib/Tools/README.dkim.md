
À propos de Dkim
=================

L'API et les noms des options sont désormais stables.

Not stable/bugs:

 * Need to transform LF into CRLF before calling `Dkim::sign()`
 * Errors handling : `Dkim::sign()` may throws exception in future (not sure)


Standalone example
-------------------

    require 'lib/Tools/Dkim.php';

    $opts['domain']   = 'mydomain.tld';
    $opts['selector'] = 'selector';
    $opts['privkey']  = '/path/to/private.key'; // Or a PEM formatted key

    $from    = 'bob@mydomain.tld';
    $to      = 'alice@otherdomain.tld';
    $subject = 'Test Mail with DKIM';

    $body = "This is an example of DKIM signed mail.\r\nBye!\r\n";

    // Headers for mail() function
    $headers = "From: $from
    MIME-Version: 1.0
    Content-Type: text/plain";

    // All values must contain <CR><LF>, not <LF> alone!
    foreach (['headers','body','to','subject'] as $varname) {
        $$varname = preg_replace('#(?<!\r)\n#', "\r\n", $$varname);
    }

    $dkim = new \Wamailer\Tools\Dkim($opts);
    $dkim_header = $dkim->sign($headers, $body, $to, $subject);

    $headers = $dkim_header . $headers;
    mail($to, $subject, $body, $headers);

