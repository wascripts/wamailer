
DomainKeys Identified Mail
===========================

L'API et les noms des options sont désormais stables.

Not stable/bugs:

 * Errors handling : `Dkim::sign()` may throws exception in future (not sure)


Fonctionnalités
----------------

 * Algorithme de hashage configurable (défaut: sha256)
 * Formats canoniques configurables (défaut: relaxed/simple)
 * Accepte les clés sous forme de chaîne au format PEM ou de chemin de fichier
 * Support des clés protégées à l'aide d'un passphrase
 * Liste des en-têtes à signer configurable
 * Signature d'en-têtes non existants (protège contre les ajouts à posteriori)
 * Signature d'en-têtes à multiples occurences
 * Support du format DKIM Quoted Printable
 * Support pour l'ajout de tags DKIM additionnels
 * Support des signatures partielles


Standalone example
-------------------

    require 'lib/Tools/Dkim.php';

    $opts['domain']   = 'mydomain.tld';
    $opts['selector'] = 'selector';
    $opts['privkey']  = '/path/to/private.key'; // Or a PEM formatted key

    $from    = 'bob@mydomain.tld';
    $to      = 'alice@otherdomain.tld';
    $subject = 'Test Mail with DKIM';

    $body = <<<EOD
    This is an example of DKIM signed mail.
    Bye!
    EOD;

    // Headers for mail() function
    $headers = <<<EOD
    From: $from
    MIME-Version: 1.0
    Content-Type: text/plain
    EOD;

    $dkim = new \Wamailer\Tools\Dkim($opts);
    $dkim_header = $dkim->sign($headers, $body, $to, $subject);

    $headers = $dkim_header . $headers;
    mail($to, $subject, $body, $headers);

