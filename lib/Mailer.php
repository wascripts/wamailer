<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2015 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 *
 * Les sources qui m’ont bien aidées :
 *
 * @link https://github.com/php/php-src/blob/master/ext/standard/mail.c
 * @link https://github.com/php/php-src/blob/master/win32/sendmail.c
 */

namespace Wamailer;

use Exception;

/**
 * Classe d’envois d’emails
 *
 * @todo
 * - parsing des emails sauvegardés
 * - propagation charset dans les objets entêtes (pour encodage de param)
 * - Ajout de Email::loadFromString() et Email::saveAsString() ?
 */
abstract class Mailer
{
	/**
	 * Version courante de Wamailer
	 */
	const VERSION = '4.0';

	/**
	 * Valeur du champ X-Mailer.
	 * %s est remplacé par Mailer::VERSION.
	 * Si la valeur équivaut à false, l’en-tête X-Mailer n’est pas ajouté.
	 *
	 * @var string
	 */
	public static $signature = 'Wamailer/%s';

	/********************** RÉGLAGES SENDMAIL **********************/

	/**
	 * Activation du mode sendmail
	 *
	 * @var boolean
	 */
	private static $sendmail_mode = false;

	/**
	 * Commande de lancement de sendmail
	 * L’option '-t' indique au programme de récupérer les adresses des destinataires dans les
	 * en-têtes 'To', 'Cc' et 'Bcc' de l’email.
	 * L’option '-i' permet d’éviter que le programme n’interprète une ligne contenant uniquement
	 * un caractère point comme la fin du message.
	 *
	 * @var string
	 */
	public static $sendmail_cmd = '/usr/sbin/sendmail -t -i';

	/********************** RÉGLAGES SMTP **************************/

	/**
	 * Activation du mode SMTP
	 *
	 * @var boolean
	 */
	private static $smtp_mode = false;

	/**
	 * Serveur SMTP à contacter
	 * Format simple : 'hostname', 'hostname:port' ou 'ssl://hostname:port'
	 * Format avancé : [
	 *     'server'   => 'hostname:port',
	 *     'username' => 'myusername',
	 *     'passwd'   => 'mypassword',
	 *     'starttls' => true
	 * ]
	 * L'option 'starttls' est inutile et sera ignorée si la connexion est
	 * sécurisée dès son initialisation par l'emploi de l'un des préfixes
	 * ssl/tls supportés par PHP (voir http://php.net/stream-get-transports)
	 *
	 * L'option 'debug' peut être soit un booléen (true = affichage sur la sortie
	 * standard), ou bien toute valeur utilisable avec call_user_func(). Exemple :
	 * [
	 *     'server'   => 'tls://hostname:port',
	 *     'username' => 'myusername',
	 *     'passwd'   => 'mypassword',
	 *     'debug'    => function ($str) { writelog($str); }
	 * ]
	 *
	 * L'option 'timeout' permet de configurer le délai d’attente lors d’une
	 * tentative de connexion à un serveur.
	 *
	 * L'option 'iotimeout' permet de définir le délai d’attente moyen d’une
	 * réponse du serveur après l’envoi d’une commande à celui-ci.
	 *
	 * L'option 'keepalive' permet de réaliser plusieurs transactions
	 * (= envois d'emails) durant la même connexion au serveur SMTP.
	 * Indispensable si on envoie des emails en boucle.
	 *
	 * Les options non reconnues par la classe Mailer sont transmises telles
	 * quelles à la classe SmtpClient (voir SmtpClient::$opts pour les options
	 * reconnues par SmtpClient).
	 *
	 * @var mixed
	 */
	public static $smtp_server = 'localhost';

	/**
	 * Utilisé en interne pour stocker l'instance de SmtpClient
	 *
	 * @var SmtpClient
	 */
	private static $smtp;

	/**
	 * Utilisée pour définir si la fonction mail() de PHP utilise un MTA local
	 * (Sendmail ou équivalent) ou bien établit directement une connexion vers
	 * un serveur SMTP défini dans sa configuration.
	 * Si laissée vide, cette propriété est définie au premier appel de
	 * Mailer::send() en vérifiant la valeur retournée par ini_get('sendmail_path').
	 *
	 * @see Mailer::send()
	 *
	 * @var boolean
	 */
	public static $php_use_smtp;

	/**
	 * Active ou désactive l’utilisation directe de sendmail pour l’envoi des emails
	 *
	 * @param boolean $use Active/désactive le mode sendmail
	 * @param string  $cmd Commande système à utiliser
	 */
	public static function useSendmail($use, $cmd = null)
	{
		self::$sendmail_mode = $use;

		if (is_string($cmd)) {
			self::$sendmail_cmd = $cmd;
		}
	}

	/**
	 * Active ou désactive l’utilisation directe d’un serveur SMTP pour l’envoi des emails
	 *
	 * @param boolean $use    Active/désactive le mode SMTP
	 * @param mixed   $server Informations de connexion au serveur (voir la propriété $smtp_server)
	 *
	 * @return SmtpClient
	 */
	public static function useSMTP($use, $server = null)
	{
		self::$smtp = ($use) ? new SmtpClient() : null;
		self::$smtp_mode = $use;

		if (!is_null($server)) {
			self::$smtp_server = $server;
		}

		return self::$smtp;
	}

	/**
	 * Vérifie la validité syntaxique d'un email
	 *
	 * @param string $email
	 *
	 * @return boolean
	 */
	public static function checkMailSyntax($email)
	{
		return (bool) preg_match('/^(?:(?(?<!^)\.)[-!#$%&\'*+\/0-9=?a-z^_`{|}~]+)+@'
			. '(?:(?(?<!@)\.)[a-z0-9](?:[-a-z0-9]{0,61}[a-z0-9])?)+$/i', $email);
	}

	/**
	 * Nettoie la liste des adresses destinataires pour supprimer toute
	 * personnalisation ('My name' <my@address.tld>)
	 *
	 * @param string $addressList
	 *
	 * @return array
	 */
	public static function clearAddressList($addressList)
	{
		preg_match_all(
			'/(?<=^|[\s,<])[-!#$%&\'*+\/0-9=?a-z^_`{|}~.]+@[-a-z0-9.]+(?=[\s,>]|$)/Si',
			$addressList,
			$matches
		);

		return $matches[0];
	}

	/**
	 * Envoi d’un email
	 *
	 * @param Email $email
	 *
	 * @throws Exception
	 *
	 * @return boolean
	 */
	public static function send(Email $email)
	{
		// On veut travailler sur une copie et non pas altérer l'instance d'origine
		$email = clone $email;

		if (!$email->hasRecipients()) {
			throw new Exception("No recipient address given");
		}

		$header_sig = null;
		if (!empty(self::$signature)) {
			$header_sig = $email->headers->set('X-Mailer', sprintf(self::$signature, self::VERSION));
		}

		if (!$email->headers->get('To') && !$email->headers->get('Cc')) {
			// Tous les destinataires sont en copie cachée. On ajoute quand
			// même un en-tête To pour le mentionner.
			$email->headers->set('To', 'undisclosed-recipients:;');
		}

		$rPath = $email->headers->get('Return-Path');
		if (!is_null($rPath)) {
			$rPath = trim($rPath->value, '<>');
			/**
			 * L'en-tête Return-Path ne devrait être ajouté que par le dernier
			 * serveur SMTP de la chaîne de transmission et non pas par le MUA
			 * @see RFC2321#4.4
			 */
			$email->headers->remove('Return-Path');
		}

		if (self::$sendmail_mode) {
			if ($header_sig) {
				$header_sig->append(' (Sendmail mode)');
			}

			return self::sendmail($email, null, $rPath);
		}

		if (self::$smtp_mode) {
			if ($header_sig) {
				$header_sig->append(' (SMTP mode)');
			}

			//
			// Nous devons passer directement les adresses email des destinataires
			// au serveur SMTP.
			// On récupère ces adresses des entêtes To, Cc et Bcc.
			//
			$recipients = array();
			foreach (array('to', 'cc', 'bcc') as $name) {
				$header = $email->headers->get($name);

				if (!is_null($header)) {
					$addressList = $header->value;
					$recipients = array_merge($recipients,
						self::clearAddressList($addressList)
					);
				}
			}

			//
			// L’entête Bcc ne doit pas apparaitre dans l’email envoyé.
			// On le supprime donc.
			//
			$email->headers->remove('Bcc');

			return self::smtpmail($email, $recipients, $rPath);
		}

		//
		// Si l’option PHP 'sendmail_path' est vide, cela signifie que PHP
		// ouvre une connexion vers un serveur SMTP défini dans sa configuration.
		//
		if (is_null(self::$php_use_smtp)) {
			self::$php_use_smtp = (ini_get('sendmail_path') == '');
		}

		//
		// On récupère les en-têtes Subject et To qui doivent être transmis
		// en argument de la fonction mail().
		//
		$subject = $email->headers->get('Subject');
		$recipients = $email->headers->get('To');

		if (!is_null($subject)) {
			$subject = $subject->value;
			// La fonction mail() ajoute elle-même l'en-tête Subject
			$email->headers->remove('Subject');
		}

		if (!is_null($recipients)) {
			$recipients = $recipients->value;

			if (!self::$php_use_smtp) {
				//
				// Sendmail parse les en-têtes To, Cc et Bcc s’ils sont
				// présents pour récupérer la liste des adresses destinataire.
				// On passe déjà la liste des destinataires principaux (To)
				// en argument de la fonction mail(), donc on supprime l’en-tête To
				//
				$email->headers->remove('To');
			}
		}

		if (self::$php_use_smtp && !is_null($rPath)) {
			//
			// La fonction mail() utilise prioritairement la valeur de l’option
			// sendmail_from comme adresse à passer dans la commande MAIL FROM
			// (adresse qui sera utilisée par le serveur SMTP pour forger l’entête
			// Return-Path). On donne la valeur de $rPath à l’option sendmail_from
			//
			ini_set('sendmail_from', $rPath);
		}

		list($headers, $message) = explode("\r\n\r\n", $email->__toString(), 2);

		if (!self::$php_use_smtp) {
			$headers = str_replace("\r\n", PHP_EOL, $headers);
			$message = str_replace("\r\n", PHP_EOL, $message);

			/**
			 * PHP ne laisse passer les longs entêtes Subject et To que
			 * si les plis sont séparés par des séquences <CR><LF><LWS>,
			 * cela même sur les systèmes UNIX-like.
			 * Cela semble poser problème avec certains MTA qui remplacent les
			 * séquences <LF> par <CR><LF> sans vérifier si un <CR> est déjà
			 * présent, donnant ainsi une séquence <CR><CR><LF> faussant le
			 * marquage de fin de bloc des en-têtes.
			 * On remplace les séquences <CR><LF><LWS> par une simple espace.
			 *
			 * @see SKIP_LONG_HEADER_SEP routine in
			 *      https://github.com/php/php-src/blob/master/ext/standard/mail.c
			 * @see PHP Bug 24805 at http://bugs.php.net/bug.php?id=24805
			 */
			$subject = str_replace("\r\n\t", ' ', $subject);
			$recipients = str_replace("\r\n\t", ' ', $recipients);
		}

		set_error_handler(array(__CLASS__, 'errorHandler'));

		if (!ini_get('safe_mode') && !is_null($rPath)) {
			$result = mail($recipients, $subject, $message, $headers, '-f' . $rPath);
		}
		else {
			$result = mail($recipients, $subject, $message, $headers);
		}

		restore_error_handler();

		if (self::$php_use_smtp) {
			ini_restore('sendmail_from');
		}

		return $result;
	}

	/**
	 * Envoi via sendmail
	 *
	 * @param Email  $email      Email à envoyer
	 * @param string $recipients Adresses supplémentaires de destinataires
	 * @param string $rPath      Adresse d’envoi (définit le return-path)
	 *
	 * @throws Exception
	 *
	 * @return boolean
	 */
	public static function sendmail($email, $recipients = null, $rPath = null)
	{
		if (!empty(self::$sendmail_cmd)) {
			$sendmail_cmd = self::$sendmail_cmd;
		}
		else {
			$sendmail_cmd = ini_get('sendmail_path');
		}

		if (!is_null($rPath)) {
			$sendmail_cmd .= ' -f' . escapeshellcmd($rPath);
		}

		if (is_array($recipients) && count($recipients) > 0) {
			$sendmail_cmd .= ' -- ' . escapeshellcmd(implode(' ', $recipients));
		}

		if (!$sendmail_cmd || !($sendmail = popen($sendmail_cmd, 'wb'))) {
			throw new Exception(sprintf(
				"Could not execute mail delivery program '%s'",
				substr($sendmail_cmd, 0, strpos($sendmail_cmd, ' '))
			));
		}

		fwrite($sendmail, str_replace("\r\n", PHP_EOL, $email->__toString()));

		if (($code = pclose($sendmail)) != 0) {
			throw new Exception(sprintf(
				"The mail delivery program has returned the following error code (%d)",
				$code
			));
		}

		return true;
	}

	/**
	 * Envoi via la classe smtp
	 *
	 * @param Email  $email      Email à envoyer
	 * @param string $recipients Adresses des destinataires
	 * @param string $rPath      Adresse d’envoi (définit le return-path)
	 *
	 * @throws Exception
	 *
	 * @return boolean
	 */
	public static function smtpmail($email, $recipients, $rPath = null)
	{
		if (is_null($rPath)) {
			$rPath = ini_get('sendmail_from');
		}

		$smtp      = self::$smtp;
		$server    = self::$smtp_server;
		$port      = 25;
		$username  = null;
		$passwd    = null;
		$keepalive = false;
		$opts      = array();

		if (is_array($server) && isset($server['server'])) {
			foreach (array('username','passwd','keepalive') as $optname) {
				if (isset($server[$optname])) {
					$$optname = $server[$optname];
					unset($server[$optname]);
				}
			}

			$host = $server['server'];
			// D'autres entrées du tableau server peuvent correspondre à des options
			// à transmettre à la classe smtp
			$opts = $server;
		}
		else {
			$host = $server;
		}

		if ($host == '') {
			throw new Exception("No valid SMTP server given");
		}

		if (preg_match('#^(.+):([0-9]+)$#', $host, $m)) {
			$host = $m[1];
			$port = $m[2];
		}

		$smtp->options($opts);

		if (!$smtp->isConnected() && !$smtp->connect($host, $port, $username, $passwd)) {
			$smtp->quit();
			throw new Exception(sprintf(
				"SMTP server response: '%s'",
				$smtp->responseData
			));
		}

		if (!$smtp->from($rPath)) {
			$smtp->quit();
			throw new Exception(sprintf(
				"SMTP server response: '%s'",
				$smtp->responseData
			));
		}

		foreach ($recipients as $recipient) {
			if (!$smtp->to($recipient)) {
				$smtp->quit();
				throw new Exception(sprintf(
					"SMTP server response: '%s'",
					$smtp->responseData
				));
			}
		}

		if (!$smtp->send($email->__toString())) {
			$smtp->quit();
			throw new Exception(sprintf(
				"SMTP server response: '%s'",
				$smtp->responseData
			));
		}

		if (!$keepalive) {
			$smtp->quit();
		}

		return true;
	}

	/**
	 * Méthode de gestion des erreurs.
	 * Activée lors de l'appel à la fonction mail() pour récupérer les
	 * éventuelles erreurs et les retourner proprement sous forme d'exception.
	 *
	 * @param integer $errno
	 * @param string  $error
	 *
	 * @throws Exception
	 */
	public static function errorHandler($errno, $error)
	{
		throw new Exception("mail() function has returned the following error: '$error'");
	}
}
