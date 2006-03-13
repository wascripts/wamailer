<?php
/**
 * Copyright (c) 2002-2006 Aurélien Maille
 * 
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 * 
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 * 
 * @package Wamailer
 * @author  Bobe <wascripts@phpcodeur.net>
 * @link    http://phpcodeur.net/wascripts/wamailer/
 * @license http://www.gnu.org/copyleft/lesser.html
 * @version $Id$
 */

require dirname(__FILE__) . '/mime.class.php';

if( function_exists('email') && !function_exists('mail') ) {
	// on est probablement chez l’hébergeur Online
	require dirname(__FILE__) . '/online.php';
}

//
// Compatibilité php < 5.1.1
//
if( !defined('DATE_RFC2822') ) {
	define('DATE_RFC2822', 'D, d M Y H:i:s O');
}

if( isset($_SERVER['SERVER_NAME']) ) {
	$hostname = $_SERVER['SERVER_NAME'];
}
else if( !($hostname = @php_uname('n')) ) {
	$hostname = 'localhost';
}

define('MAILER_HOSTNAME',  $hostname);
define('MAILER_MIME_EOL',  (strncasecmp(PHP_OS, 'Win', 3) != 0) ? "\n" : "\r\n");
define('PHP_USE_SENDMAIL', (ini_get('sendmail_path') != '') ? true : false);
unset($hostname);

/**
 * Classe d’envois d’emails
 * 
 * @todo
 * - Envoi avec SMTP
 * - parsing des emails sauvegardés
 * - ajout méthode pour remplissage de Mime_Part::body ?
 * - Ajouter une méthode pour récupération contenu email, en-têtes, etc plutôt
 *   qu’appeller directement __toString() ?
 * 
 * Les sources qui m’ont bien aidées : 
 * 
 * @link http://cvs.php.net/php-src/ext/standard/mail.c
 * @link http://cvs.php.net/php-src/win32/sendmail.c
 */
class Mailer {
	
	/********************** RÉGLAGES SENDMAIL **********************/
	
	/**
	 * Activation du mode sendmail
	 * 
	 * @var boolean
	 * @access public
	 */
	static $sendmail_mode = false;
	
	/**
	 * Commande de lancement de sendmail
	 * L’option '-t' indique au programme de récupérer les adresses des destinataires dans les
	 * en-têtes 'To', 'Cc' et 'Bcc' de l’email.
	 * L’option '-i' permet d’éviter que le programme n’interprète une ligne contenant uniquement 
	 * un caractère point comme la fin du message.
	 * 
	 * @var string
	 * @access public
	 */
	static $sendmail_cmd  = '/usr/sbin/sendmail -t -i';
	
	/***************************************************************/
	
	static $smtp_mode = false;
	
	/**
	 * Version courante de Wamailer
	 */
	const VERSION = '3.0';
	
	private function __construct() {}
	
	/**
	 * Active ou désactive l’utilisation directe de sendmail pour l’envoi des emails
	 * 
	 * @param boolean $use  Active/désactive le mode sendmail
	 * 
	 * @static
	 * @access public
	 * @return void
	 */
	static function useSendmail($use)
	{
		self::$sendmail_mode = $use;
	}
	
	/**
	 * Vérifie la validité syntaxique d'un email
	 * 
	 * @param string $email
	 * 
	 * @static
	 * @access public
	 * @return boolean
	 */
	static function checkMailSyntax($email)
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
	 * @static
	 * @access public
	 * @return string
	 */
	static function clearAddressList($addressList)
	{
		preg_match_all('/(?<=^|[\s,<])[-!#$%&\'*+\/0-9=?a-z^_`{|}~.]+@[-a-z0-9.]+(?=[\s,>]|$)/Si',
			$addressList, $matches);
		
		return implode(', ', $matches[0]);
	}
	
	/**
	 * Envoi d’un email
	 * 
	 * @param Email object $email
	 * 
	 * @static
	 * @access public
	 * @return boolean
	 */
	static function send(Email $email)
	{
		$email->headers->set('X-Mailer', sprintf('Wamailer/%.1f (http://phpcodeur.net/)', self::VERSION));
		
		$rPath = $email->headers->get('Return-Path');
		if( !is_null($rPath) ) {
			$rPath = trim($rPath->value, '<>');
		}
		
		if( self::$sendmail_mode == true ) {
			$email->headers->get('X-Mailer')->append(' (sendmail mode)');
			$result = self::sendmail($email->__toString(), null, $rPath);
		}
		else if( self::$smtp_mode == true ) {
			if( !class_exists('Mailer_SMTP') ) {
				require dirname(__FILE__) . '/smtp.class.php';
			}
			
			$email->headers->get('X-Mailer')->append(' (SMTP mode)');
			
			// @todo
			// Récupérer adresses To, Cc et Bcc dans $recipients puis supprimer en-tête bcc
			
			$result = false;//self::smtpmail($email->__toString(), $recipients, $rPath);
		}
		else {
			$subject = $email->headers->get('Subject');
			$recipients = $email->headers->get('To');
			
			list($headers, $message) = explode("\r\n\r\n", $email->__toString(), 2);
			
			if( !is_null($subject) ) {
				$subject = $subject->__toString();
				$headers = str_replace($subject."\r\n", '', $headers);
				$subject = substr($subject, 9);// on skip le nom de l’en-tête
			}
			
			if( !is_null($recipients) ) {
				$recipients = $recipients->__toString();
				
				if( PHP_USE_SENDMAIL ) {
					/**
					 * Sendmail parse les en-têtes To, Cc et Bcc s’ils sont
					 * présents pour récupérer la liste des adresses destinataire.
					 * On passe déjà la liste des destinataires principaux (To)
					 * en argument de la fonction mail(), donc on supprime l’en-tête To
					 */
					$headers = str_replace($recipients."\r\n", '', $headers);
					$recipients = substr($recipients, 4);// on skip le nom de l’en-tête
				}
				else {
					/**
					 * La fonction mail() ouvre un socket vers un serveur SMTP.
					 * On peut laisser l’en-tête To pour la personnalisation.
					 * Il faut par contre passer une liste d’adresses débarassée
					 * de cette personnalisation en argument de la fonction mail()
					 * sous peine d’obtenir une erreur.
					 */
					$recipients = self::clearAddressList($recipients);
				}
			}
			
			if( PHP_USE_SENDMAIL ) {
				/**
				 * On ne réalise pas l’opération sur subject et recipients
				 * car la fonction mail() ne laisse passer les long en-têtes
				 * que si les plis sont séparés par \r\n<LWS>
				 * 
				 * @see SKIP_LONG_HEADER_SEP routine in
				 *   http://cvs.php.net/php-src/ext/standard/mail.c
				 */
				$headers = str_replace("\r\n", MAILER_MIME_EOL, $headers);
				$message = str_replace("\r\n", MAILER_MIME_EOL, $message);
			}
			else {
				/**
				 * La fonction mail() utilise prioritairement la valeur de l’option
				 * sendmail_from comme adresse à passer dans la commande MAIL FROM
				 * (adresse qui sera utilisée par le serveur SMTP pour forger l’en-tête
				 * Return-Path). On donne la valeur de $rPath à l’option sendmail_from
				 */
				if( !is_null($rPath) ) {
					ini_set('sendmail_from', $rPath);
				}
				
				/**
				 * La fonction mail() va parser elle-même les en-têtes Cc et Bcc
				 * pour passer les adresses destinataires au serveur SMTP.
				 * Il est donc indispensable de nettoyer l’en-tête Cc de toute
				 * personnalisation sous peine d’obtenir une erreur.
				 */
				$header_cc = $email->headers->get('Cc');
				if( !is_null($header_cc) ) {
					$header_cc = $header_cc->__toString();
					$new_header_cc = new Mime_Header('Cc', self::clearAddressList($header_cc));
					$headers = str_replace($header_cc, $new_header_cc->__toString(), $headers);
				}
			}
			
			if( ini_get('safe_mode') == false && !is_null($rPath) ) {
				$result = mail($recipients, $subject, $message, $headers, '-f' . $rPath);
			}
			else {
				$result = mail($recipients, $subject, $message, $headers);
			}
			
			if( !PHP_USE_SENDMAIL ) {
				ini_restore('sendmail_from');
			}
		}
		
		return $result;
	}
	
	/**
	 * Envoi via sendmail
	 * 
	 * @param string $email       Email à envoyer
	 * @param string $recipients  Adresses supplémentaires de destinataires
	 * @param string $rPath       Adresse d’envoi (définit le return-path)
	 * 
	 * @access public
	 * @return boolean
	 */
	static function sendmail($email, $recipients = null, $rPath = null)
	{
		if( !empty(self::$sendmail_cmd) ) {
			$sendmail_cmd = self::$sendmail_cmd;
		}
		else {
			$sendmail_cmd = ini_get('sendmail_path');
		}
		
		$sendmail_path = substr($sendmail_cmd, 0, strpos($sendmail_cmd, ' '));
		
		if( !is_executable($sendmail_path) ) {
			throw new Exception("Mailer::sendmail() : [$sendmail_path] n'est pas un fichier exécutable valide");
		}
		
		if( !is_null($rPath) ) {
			$sendmail_cmd .= ' -f' . escapeshellcmd($rPath);
		}
		
		if( is_array($recipients) && count($recipients) > 0 ) {
			$sendmail_cmd .= ' -- ' . escapeshellcmd(implode(' ', $recipients));
		}
		
		$sendmail = popen($sendmail_cmd, 'wb');
		fputs($sendmail, preg_replace("/\r\n?/", MAILER_MIME_EOL, $email));
		
		if( ($code = pclose($sendmail)) != 0 ) {
			trigger_error("Mailer::sendmail() : Sendmail a retourné le code"
				. " d'erreur suivant -> $code", E_USER_WARNING);
			return false;
		}
		
		return true;
	}
	
	/**
	 * Envoi via la classe smtp
	 * 
	 * @param string $email       Email à envoyer
	 * @param string $recipients  Adresses des destinataires
	 * @param string $rPath       Adresse d’envoi (définit le return-path)
	 * 
	 * @access public
	 * @return boolean
	 */
	static function smtpmail($email, $recipients, $rPath = null)
	{
		if( !class_exists('Mailer_SMTP') ) {
			require dirname(__FILE__) . '/smtp.class.php';
		}
		
		if( is_null($rPath) ) {
			$rPath = ini_get('sendmail_from');
		}
		
		// smtp::isConnected()
		if( !is_resource($this->smtp->socket) || !$this->smtp->noop() ) {
			$this->smtp->connect();
		}
		
		if( !$this->smtp->from($rPath) ) {
			$this->error($this->smtp->msg_error);
			return false;
		}
		
		foreach( $recipients as $recipient ) {
			if( !$this->smtp->to($email) ) {
				$this->error($this->smtp->msg_error);
				return false;
			}
		}
		
		if( !$this->smtp->send($headers, $message) ) {
			$this->error($this->smtp->msg_error);
			return false;
		}
		
		//
		// Apparamment, les commandes ne sont réellement effectuées qu'après
		// la fermeture proprement de la connexion au serveur SMTP. On quitte
		// donc la connexion courante si l’option de connexion persistante
		// n’est pas activée.
		//
		if( !$this->persistent_connection ) {
			$this->smtp->quit();
		}
		
		return true;
	}
	
	static function setError() {}
	static function getError() { return $GLOBALS['php_errormsg']; }
}

class Email {
	/**
	 * Liste de constantes utilisables avec la méthode Email::setPriority()
	 */
	const PRIORITY_HIGHEST = 1;
	const PRIORITY_HIGH    = 2;
	const PRIORITY_NORMAL  = 3;
	const PRIORITY_LOW     = 4;
	const PRIORITY_LOWEST  = 5;
	
	/**
	 * Email du destinataire
	 * 
	 * @var string
	 * @access private
	 */
	private $sender = '';
	
	/**
	 * Jeu de caractères générique de l’email (D’autres jeux peuvent être
	 * ultérieurement définis pour des sous-ensembles de l’email)
	 * 
	 * @var string
	 * @access public
	 */
	public $charset = 'ISO-8859-1';
	
	/**
	 * Bloc d’en-têtes de l’email
	 * 
	 * @var object
	 * @see Mime_Headers class
	 * @access private
	 */
	private $_headers = null;
	
	/**
	 * Partie texte brut de l’email
	 * 
	 * @var object
	 * @see Mime_Part class
	 * @access private
	 */
	private $_textPart = null;
	
	/**
	 * Partie HTML de l’email
	 * 
	 * @var object
	 * @see Mime_Part class
	 * @access private
	 */
	private $_htmlPart = null;
	
	/**
	 * Multi-Partie globale de l’email
	 * 
	 * @var array
	 * @access private
	 */
	private $_attachParts = array();
	
	/**
	 * @var string
	 * @access private
	 */
	private $_compiledBody = '';
	
	/**
	 * Constructeur de classe
	 * 
	 * @access public
	 * @return void
	 */
	public function __construct($charset = null)
	{
		$this->_headers = new Mime_Headers(array(
			'Return-Path' => '',
			'Date' => '',
			'From' => '',
			'Sender' => '',
			'Reply-To' => '',
			'To' => '',
			'Cc' => '',
			'Bcc' => '',
			'Subject' => '',
			'Message-ID' => '',
			'MIME-Version' => ''
		));
		
		if( !is_null($charset) ) {
			$this->charset = $charset;
		}
	}
	
	/**
	 * Charge un email à partir d’un fichier mail
	 * 
	 * @param string  $filename
	 * @param boolean $fullParse
	 * 
	 * @access public
	 * @return mixed
	 */
	public function load($filename, $fullParse = false)
	{
		if( !is_readable($filename) ) {
			throw new Exception("Cannot read file '$filename'");
		}
		
		$input = file_get_contents($filename);
		$input = preg_replace('/\r\n?|\n/', "\r\n", $input);
		list($headers, $message) = explode("\r\n\r\n", $input, 2);
		
		if( !isset($this) || !($this instanceof Email) ) {
			$email = new Email();
			$returnBool = false;
		}
		else {
			$email = $this;
			$returnBool = true;
		}
		
		$headers = preg_split("/\r\n(?![\x09\x20])/", $headers);
		foreach( $headers as $header ) {
			if( strpos($header, ':') ) {// Pour esquiver l’éventuelle ligne From - ...
				list($name, $value) = explode(':', $header, 2);
				$email->headers->add($name, $value);
			}
		}
		
/*		if( $fullParse ) {
			$sender = $email->headers->get('Return-Path');
			if( !is_null($sender) ) {
				$email->sender = trim($sender->value, '<>');
			}
			
			// @todo
			// Récupération charset "global"
			// + structure, si compatible
			// + attention, headers du premier mime_part se trouvent dans
			// le Mime_Headers de l’objet Email
			
			$contentType = $email->headers->get('Content-Type');
			$boundary = $contentType->param('boundary');
			$charset  = $contentType->param('charset');
			
			if( !is_null($contentType) && strncasecmp($contentType->value, 'multipart', 9) == 0 ) {
				if( is_null($boundary) ) {
					throw new Exception("Bad mime part (missed boundary)");
				}
				
				
			}
			else {
				switch( $contentType->value ) {
					case 'text/plain':
						$email->setTextBody($message, $charset);
						break;
					case 'text/html':
						$email->setHTMLBody($message, $charset);
						break;
					default:
						// C'est donc un fichier attaché seul
						break;
				}
			}
		}
		else {*/
			$email->_compiledBody = "\r\n".$message;
//		}
		
		return $returnBool ? true : $email;
	}
	
	/**
	 * Sauvegarde l’email dans un fichier
	 * 
	 * @param string $filename
	 * 
	 * @access public
	 * @return void
	 */
	public function save($filename)
	{
		if( !(file_exists($filename) && is_writable($filename)) && !is_writable(dirname($filename)) ) {
			throw new Exception("Cannot write file '$filename'");
		}
		
		file_put_contents($filename, $this->__toString());
	}
	
	/**
	 * @param string $email  Email de l’expéditeur
	 * @param string $name   Personnalisation du nom de l’expéditeur
	 * 
	 * @access public
	 * @return void
	 */
	public function setFrom($email, $name = null)
	{
		$email = $this->sender = trim($email);
		
		if( !is_null($name) ) {
			$email = sprintf('%s <%s>',
				Mime::encodeHeader('From', $name, $this->charset, 'phrase'), $email);
		}
		
		$this->headers->set('From', $email);
	}
	
	/**
	 * @param string $email  Email du destinataire ou tableau contenant la liste des destinataires
	 * @param string $name   Personnalisation du nom du destinataire
	 * 
	 * @access public
	 * @return void
	 */
	public function addRecipient($email, $name = null)
	{
		$this->_addRecipient($email, $name, 'To');
	}
	
	/**
	 * @param string $email  Email du destinataire ou tableau contenant la liste des destinataires
	 * @param string $name   Personnalisation du nom du destinataire
	 * 
	 * @access public
	 * @return void
	 */
	public function addCCRecipient($email, $name = null)
	{
		$this->_addRecipient($email, $name, 'Cc');
	}
	
	/**
	 * @param string $email  Email du destinataire ou tableau contenant la liste des destinataires
	 * 
	 * @access public
	 * @return void
	 */
	public function addBCCRecipient($email)
	{
		$this->_addRecipient($email, null, 'Bcc');
	}
	
	/**
	 * @param string $email   Email du destinataire ou tableau contenant la liste des destinataires
	 * @param string $name    Personnalisation du nom du destinataire
	 * @param string $header  Nom de l’en-tête concerné (parmi To, Cc et Bcc)
	 * 
	 * @access private
	 * @return void
	 */
	private function _addRecipient($email, $name, $header)
	{
		$email = trim($email);
		
		if( !is_null($name) ) {
			$email = sprintf('%s <%s>',
				Mime::encodeHeader($header, $name, $this->charset, 'phrase'), $email);
		}
		
		if( is_null($this->headers->get($header)) ) {
			$this->headers->set($header, $email);
		}
		else {
			$this->headers->get($header)->append(', ' . $email);
		}
	}
	
	/**
	 * @param string $email  email de réponse
	 * @param string $name   Personnalisation
	 * 
	 * @access public
	 * @return void
	 */
	public function setReplyTo($email = null, $name = null)
	{
		if( !is_null($email) ) {
			$email = trim($email);
			
			if( !is_null($name) ) {
				$email = sprintf('%s <%s>', Mime::encodeHeader('Reply-To',
					$name, $this->charset, 'phrase'), $email);
			}
		}
		else {
			$email = $this->headers->get('From')->value;
		}
		
		$this->headers->set('Reply-To', $email);
	}
	
	/**
	 * @param string $email  email pour la notification de lecture (par défaut,
	 *                       l’adresse d’expéditeur est utilisée)
	 * 
	 * @access public
	 * @return void
	 */
	public function setNotify($email = null)
	{
		$this->headers->add(
			'Disposition-Notification-To',
			'<' . (( !is_null($email) ) ? trim($email) : $this->sender) . '>'
		);
	}
	
	/**
	 * Définition de l’adresse de retour pour les emails d’erreurs
	 * 
	 * @param string $email  mail de retour d’erreur (par défaut, l’adresse
	 *                       d’expéditeur est utilisée)
	 * 
	 * @access public
	 * @return void
	 */
	public function setReturnPath($email = null)
	{
		$this->headers->set(
			'Return-Path',
			'<' . (( !is_null($email) ) ? trim($email) : $this->sender) . '>'
		);
	}
	
	/**
	 * Définition du niveau de priorité de l’email
	 * 
	 * @param integer $priority  Niveau de priorité de l’email
	 * 
	 * @access public
	 * @return void
	 */
	public function setPriority($priority)
	{
		if( is_numeric($priority) ) {
			$this->headers->set('X-Priority', $priority);
		}
	}
	
	/**
	 * @param string $str  Nom de l’organisation/entreprise/etc émettrice
	 * 
	 * @access public
	 * @return void
	 */
	function organization($str)
	{
		$this->headers->set('Organization',
			Mime::encodeHeader('Organization', $str, $this->charset));
	}
	
	/**
	 * @param string $subject  Le sujet de l’email
	 * 
	 * @access public
	 * @return void
	 */
	public function setSubject($subject)
	{
		$this->headers->set('Subject',
			Mime::encodeHeader('Subject', $subject, $this->charset));
	}
	
	/**
	 * @param string $message  Le message de l’email en texte brut
	 * @param string $charset  Jeu de caractères de la chaîne contenue dans $message
	 * 
	 * @access public
	 * @return Mime_Part
	 */
	public function setTextBody($message, $charset = '')
	{
		if( empty($charset) ) {
			$charset = $this->charset;
		}
		
		$this->_textPart = new Mime_Part($message);
		$this->_textPart->headers->set('Content-Type', 'text/plain');
		$this->_textPart->headers->set('Content-Transfer-Encoding', '8bit');
		$this->_textPart->headers->get('Content-Type')->param('charset', $charset);
		$this->_compiledBody = '';
		
		return $this->_textPart;
	}
	
	/**
	 * @param string $message  Le message de l’email au format HTML
	 * @param string $charset  Jeu de caractères de la chaîne contenue dans $message
	 * 
	 * @access public
	 * @return Mime_Part
	 */
	public function setHTMLBody($message, $charset = '')
	{
		if( empty($charset) ) {
			$charset = $this->charset;
		}
		
		$this->_htmlPart = new Mime_Part($message);
		$this->_htmlPart->headers->set('Content-Type', 'text/html');
		$this->_htmlPart->headers->set('Content-Transfer-Encoding', '8bit');
		$this->_htmlPart->headers->get('Content-Type')->param('charset', $charset);
		$this->_compiledBody = '';
		
		return $this->_htmlPart;
	}
	
	/**
	 * Attache un fichier comme pièce jointe de l’email
	 * 
	 * @param string $filename     Chemin vers le fichier
	 * @param string $name         Nom de fichier
	 * @param string $type         Type MIME du fichier
	 * @param string $disposition  Disposition
	 * 
	 * @access public
	 * @return Mime_Part
	 */
	public function attach($filename, $name = '', $type = '', $disposition = '')
	{
		if( !is_readable($filename) ) {
			throw new Exception("Cannot read file '$filename'");
		}
		
		if( empty($name) ) {
			$name = $filename;
		}
		
		if( empty($type) ) {
			$type = Mime::getType($filename);
		}
		
		return $this->attachFromString(file_get_contents($filename), $name, $type, $disposition);
	}
	
	/**
	 * Attache un contenu comme pièce jointe de l’email
	 * 
	 * @param string $data         Données à joindre
	 * @param string $name         Nom de fichier
	 * @param string $type         Type MIME des données
	 * @param string $disposition  Disposition
	 * 
	 * @access public
	 * @return Mime_Part
	 */
	public function attachFromString($data, $name, $type = 'application/octet-stream', $disposition = '')
	{
		if( $disposition != 'inline' ) {
			$disposition = 'attachment';
		}
		$name = basename($name);
		
		$attach = new Mime_Part($data);
		$attach->headers->set('Content-Type', $type);
		$attach->headers->get('Content-Type')->param('name', $name);
		$attach->headers->set('Content-Disposition', $disposition);
		$attach->headers->get('Content-Disposition')->param('filename', $name);
		$attach->headers->get('Content-Disposition')->param('size', strlen($data));
		$attach->headers->set('Content-Transfer-Encoding', 'base64');
		
		array_push($this->_attachParts, $attach);
		$this->_compiledBody = '';
		
		return $attach;
	}
	
	/**
	 * Retourne l’email sous forme de chaîne formatée prète à l’envoi
	 * 
	 * @access public
	 * @return string
	 */
	public function __toString()
	{
		$this->headers->set('Date', date(DATE_RFC2822));
		$this->headers->set('MIME-Version', '1.0');
		$this->headers->set('Message-ID', sprintf('<%s@%s>', md5(microtime().rand()), MAILER_HOSTNAME));
		
		if( $this->headers->get('To') == null && $this->headers->get('Cc') == null ) {
			$this->headers->set('To', 'Undisclosed-recipients:;');
		}
		
		$headers = $this->headers->__toString();
		if( !empty($this->_compiledBody) ) {
			return $headers . $this->_compiledBody;
		}
		
		$rootPart = null;
		$attachParts = $this->_attachParts;
		
		if( !is_null($this->_htmlPart) ) {
			$rootPart = $this->_htmlPart;
			
			if( !is_null($this->_textPart) ) {
				$rootPart = new Mime_Part(array($this->_textPart, $this->_htmlPart));
				$rootPart->headers->set('Content-Type', 'multipart/alternative');
			}
			
			$embedParts = array();
			foreach( $attachParts as &$attach ) {
				if( $attach->headers->get('Content-ID') == null ) {
					$name = $attach->headers->get('Content-Type')->param('name');
					$regexp = '/<([^>]+=\s*)(["\'])cid:' . preg_quote($name, '/') . '\\2([^>]*)>/S';
					
					if( !preg_match($regexp, $this->_htmlPart->body) ) {
						continue;
					}
					
					$cid = md5(microtime()) . '@' . MAILER_HOSTNAME;
					$this->_htmlPart->body = preg_replace($regexp,
						'<\\1\\2cid:' . $cid . '\\2\\3>', $this->_htmlPart->body);
					$attach->headers->set('Content-ID', "<$cid>");
				}
				
				array_push($embedParts, $attach);
				$attach = null;
			}
			
			if( count($embedParts) > 0 ) {
				$embedPart = new Mime_Part($embedParts);
				$embedPart->headers->set('Content-Type', 'multipart/related');
				$embedPart->headers->get('Content-Type')->param('type',
					$rootPart->headers->get('Content-Type')->value);
				array_unshift($embedPart->body, $rootPart);
				$rootPart = $embedPart;
			}
		}
		else if( !is_null($this->_textPart) ) {
			$rootPart = $this->_textPart;
		}
		
		$attachParts = array_filter($attachParts,
			create_function('$var', 'return !is_null($var);'));
		
		if( count($attachParts) > 0 ) {
			$mixedPart = new Mime_Part($attachParts);
			$mixedPart->headers->set('Content-Type', 'multipart/mixed');
			
			if( !is_null($rootPart) ) {
				array_unshift($mixedPart->body, $rootPart);
			}
			else if( count($attachParts) == 1 ) {
				$mixedPart = $attachParts[0];
			}
			
			$rootPart = $mixedPart;
		}
		
		//
		// Le corps d’un email est optionnel (cf. RFC 2822#3.5)
		//
		if( !is_null($rootPart) ) {
			$this->_compiledBody = $rootPart->__toString();
			
			if( strncasecmp($rootPart->headers->get('Content-Type')->value, 'multipart', 9) == 0 ) {
				$this->_compiledBody = preg_replace("/\r\n\r\n/",
					"\r\n\r\nThis is a multi-part message in MIME format.\r\n\r\n", $this->_compiledBody, 1);
			}
		}
		
		return $headers . $this->_compiledBody;
	}
	
	private function __set($name, $value)
	{
		switch( $name ) {
			case 'headers':
			case 'textPart':
			case 'htmlPart':
				throw new Exception("Cannot setting $name attribute");
				break;
		}
	}
	
	private function __get($name)
	{
		switch( $name ) {
			case 'headers':
			case 'textPart':
			case 'htmlPart':
				return $this->{'_'.$name};
				break;
		}
	}
	
	public function __clone()
	{
		$this->_headers = clone $this->_headers;
		
		if( !is_null($this->_textPart) ) {
			$this->_textPart = clone $this->_textPart;
		}
		
		if( !is_null($this->_htmlPart) ) {
			$this->_htmlPart = clone $this->_htmlPart;
		}
		
		foreach( $this->_attachParts as &$attach ) {
			$attach = clone $attach;
		}
	}
}

?>
