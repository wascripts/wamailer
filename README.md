
Wamailer
==========

Présentation
--------------

Qu’est-ce que Wamailer ? Une librairie composée de plusieurs classes écrites en
PHP et permettant de générer et envoyer des emails.
Wamailer respecte du mieux possible les différentes RFC décrivant la syntaxe des
emails.
Wamailer requiert PHP ≥ 5.4.0 et est distribué sous licence LGPL.


Fonctionnalités
-----------------

 * Support des emails HTML et multi-formats (texte et HTML)
 * Destinataires multiples directs, en CC ou BCC
 * Support des pièces jointes
 * Support des images embarquées (applicable aussi à d’autres types de fichier)
 * Support des codages de transfert 8bit, quoted-printable et base64
 * Support d’Unicode via le codage UTF-8
 * Support des signatures DKIM
 * Ajout, modification et suppression d’en-têtes d’email
 * Reformatage des messages sur la limite de 78 caractères par ligne (word wrap)
 * Support SMTP complet
 * Méthodes d’authentification CRAM-MD5, LOGIN et PLAIN
 * Sécurisation des connexions avec SSL/TLS
 * Support des appels systèmes à Sendmail ou compatible
 * Support expérimental d’OpenPGP/MIME (voir la page OpenPgp)


Utilisation
-------------

Incluez simplement la classe dans vos scripts.
Exemple d’utilisation :

    // Inclusion de l’autoloader de Wamailer.
    // Inutile dans le cas où wamailer est géré par un gestionnaire de
    // dépendances tel que composer.
    require 'wamailer.php';

    $email = new \Wamailer\Email();
    $email->setFrom('me@domain.tld', 'MyName');
    $email->addRecipient('other@domain.tld');
    $email->setSubject('This is the subject');
    $email->setTextBody('This is the message');

    try {
        \Wamailer\Mailer::send($email);
    }
    catch (Exception $e) {
        ...
    }

Deuxième exemple avec un email texte et html et en utilisant un serveur SMTP :

    use Wamailer\Email;
    use Wamailer\Mailer;

    require 'wamailer.php';

    $email = new Email();
    $email->setFrom('me@domain.tld', 'MyName');
    $email->addRecipient('other@domain.tld', 'OtherName');
    $email->setSubject('This is the subject');
    $email->setTextBody('This is the message in plain text format');
    $email->setHTMLBody('This is the <strong>message</strong> in HTML format.');

    // Sur le port 587, l’option 'starttls' est automatiquement activée.
    $opts = [
        'server' => 'mail.mydomain.tld:587',
        'auth'   => ['username' => 'myusername', 'secretkey' => 'mypassword'],
    ];

    try {
        Mailer::setTransport('smtp', $opts);
        Mailer::send($email);
    }
    catch (Exception $e) {
        ...
    }

Une documentation succinte est disponible sur le wiki à l’adresse suivante :
<http://dev.webnaute.net/wamailer/trac>
ou
<https://github.com/wascripts/wamailer/wiki>


Licence
---------

Wamailer est distribué sous licence LGPL. Pour plus d’informations,
consultez le fichier COPYING livré avec Wamailer, ou rendez-vous à l’URL
suivante : <http://www.gnu.org/copyleft/lesser.html>


Auteurs
---------

 * Développeur
   * Aurélien Maille <wascripts@phpcodeur.net>
 * Contributeurs
   * freeDani
   * Loufoque <loufoque@gmail.com>

