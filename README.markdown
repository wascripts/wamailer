
Wamailer
==========

Présentation
--------------

Qu’est-ce que Wamailer ? Une librairie composée de plusieurs classes écrites en
PHP et permettant de générer et envoyer des emails.
Wamailer respecte du mieux possible les différentes RFC décrivant la syntaxe des
emails.
Wamailer est distribué sous licence LGPL.


Fonctionnalités
-----------------

 * Support des emails HTML et multi-formats (texte et HTML)
 * Destinataires multiples directs, en CC ou BCC
 * Support des attachements de fichiers
 * Support des images embarquées (applicable aussi bien à d’autres types de fichier)
 * Support des encodages de transfert 8bit, quoted-printable, base64 et binary
 * Support d’Unicode (avec l’UTF-8 seulement)
 * Ajout et modification d’entêtes d’email
 * Reformatage des messages sur la limite de 78 caractères par ligne (word wrap)
 * Support SMTP basique
 * Support des appels systèmes à Sendmail ou compatible
 * Support expérimental d’OpenPGP/MIME (voir la page OpenPgp)


Utilisation
-------------

Incluez simplement la classe dans vos scripts. Exemple d’utilisation :

    require 'wamailer/mailer.class.php';

    $email = new Email();
    $email->setFrom('my@domain.example', 'MyName');
    $email->addRecipient('other@domain.tld');
    $email->setSubject('This is the subject');
    $email->setTextBody('This is the body of mail');

    try {
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

