<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2015 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 */

namespace Wamailer;

use Exception;

class Email
{
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
	 */
	protected $sender = '';

	/**
	 * Jeu de caractères générique de l’email (D’autres jeux peuvent être
	 * ultérieurement définis pour des sous-ensembles de l’email)
	 *
	 * @var string
	 */
	public $charset = 'ISO-8859-1';

	/**
	 * Bloc d’en-têtes de l’email (accès en lecture)
	 *
	 * @var Mime\Headers
	 */
	protected $headers = null;

	/**
	 * Partie texte brut de l’email (accès en lecture)
	 *
	 * @var Mime\Part
	 */
	protected $textPart = null;

	/**
	 * Partie HTML de l’email (accès en lecture)
	 *
	 * @var Mime\Part
	 */
	protected $htmlPart = null;

	/**
	 * Fichiers joints à l’email
	 *
	 * @var array
	 */
	protected $attachParts = array();

	/**
	 * @var string
	 */
	protected $headers_txt = '';

	/**
	 * @var string
	 */
	protected $message_txt = '';

	/**
	 * Nom de l'hôte.
	 * Utilisé dans l’identifiant du message et dans ceux des fichiers joints.
	 *
	 * @var string
	 */
	public $hostname = '';

	/**
	 * Constructeur de classe
	 */
	public function __construct($charset = null)
	{
		$this->headers = new Mime\Headers(array(
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

		if ($charset) {
			$this->charset = $charset;
		}

		if (!$this->hostname) {
			if (!($this->hostname = gethostname())) {
				$this->hostname = (!empty($_SERVER['SERVER_NAME']))
					? $_SERVER['SERVER_NAME'] : 'localhost';
			}
		}
	}

	/**
	 * Charge un email à partir d’un fichier mail
	 *
	 * @param string  $filename
	 * @param boolean $fullParse
	 *
	 * @return mixed
	 */
	public function load($filename, $fullParse = false)
	{
		if (!is_readable($filename)) {
			throw new Exception("Cannot read file '$filename'");
		}

		$input = file_get_contents($filename);
		$input = preg_replace('/\r\n?|\n/', "\r\n", $input);
		list($headers, $message) = explode("\r\n\r\n", $input, 2);

		if (!isset($this) || !($this instanceof Email)) {
			$email = new Email();
			$returnBool = false;
		}
		else {
			$email = $this;
			$returnBool = true;
		}

		$headers = preg_split("/\r\n(?![\x09\x20])/", $headers);
		foreach ($headers as $header) {
			// Pour esquiver l’éventuelle ligne From - ...
			if (strpos($header, ':')) {
				list($name, $value) = explode(':', $header, 2);
				$email->headers->add($name, $value);
			}
		}

/*		if ($fullParse) {
			$sender = $email->headers->get('Return-Path');
			if (!is_null($sender)) {
				$email->sender = trim($sender->value, '<>');
			}

			// @todo
			// Récupération charset "global"
			// + structure, si compatible
			// + attention, headers du premier Mime\Part se trouvent dans
			// l'objet Mime\Headers de l’objet Email

			$contentType = $email->headers->get('Content-Type');
			$boundary = $contentType->param('boundary');
			$charset  = $contentType->param('charset');

			if (!is_null($contentType) && strncasecmp($contentType->value, 'multipart', 9) == 0) {
				if (is_null($boundary)) {
					throw new Exception("Bad mime part (missed boundary)");
				}
			}
			else {
				switch ($contentType->value) {
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
			$email->message_txt = "\r\n".$message;
//		}
		return $returnBool ? true : $email;
	}

	/**
	 * Sauvegarde l’email dans un fichier
	 *
	 * @param string $filename
	 */
	public function save($filename)
	{
		if (!(file_exists($filename) && is_writable($filename)) && !is_writable(dirname($filename))) {
			throw new Exception("Cannot write file '$filename'");
		}

		file_put_contents($filename, $this->__toString());
	}

	/**
	 * @param string $email Email de l’expéditeur
	 * @param string $name  Personnalisation du nom de l’expéditeur
	 */
	public function setFrom($email, $name = null)
	{
		$email = $this->sender = trim($email);

		if (!$this->headers->get('Return-Path')) {
			$this->setReturnPath($email);
		}

		if (!empty($name)) {
			$email = sprintf('%s <%s>',
				Mime\Header::encode('From', $name, $this->charset, 'phrase'),
				$email
			);
		}

		$this->headers->set('From', $email);
	}

	/**
	 * @param string $email Email du destinataire
	 * @param string $name  Personnalisation du nom du destinataire
	 */
	public function addRecipient($email, $name = null)
	{
		$this->_addRecipient($email, $name, 'To');
	}

	/**
	 * @param string $email Email du destinataire
	 * @param string $name  Personnalisation du nom du destinataire
	 */
	public function addCCRecipient($email, $name = null)
	{
		$this->_addRecipient($email, $name, 'Cc');
	}

	/**
	 * @param string $email Email du destinataire
	 */
	public function addBCCRecipient($email)
	{
		$this->_addRecipient($email, null, 'Bcc');
	}

	/**
	 * @param string $email  Email du destinataire
	 * @param string $name   Personnalisation du nom du destinataire
	 * @param string $header Nom de l’en-tête concerné (parmi To, Cc et Bcc)
	 */
	private function _addRecipient($email, $name, $header)
	{
		$email = trim($email);

		if (!empty($name)) {
			$email = sprintf('%s <%s>',
				Mime\Header::encode($header, $name, $this->charset, 'phrase'),
				$email
			);
		}

		if (is_null($this->headers->get($header))) {
			$this->headers->set($header, $email);
		}
		else {
			$this->headers->get($header)->append(', ' . $email);
		}
	}

	/**
	 * Détermine si au moins un destinataire est défini
	 *
	 * @return boolean
	 */
	public function hasRecipients()
	{
		$result = false;

		foreach (array('to', 'cc', 'bcc') as $name) {
			$header = $this->headers->get($name);

			if (!is_null($header) && $header->value != '') {
				$result = true;
				break;
			}
		}

		return $result;
	}

	/**
	 * Supprime tous les destinataires définis
	 */
	public function clearRecipients()
	{
		$this->headers->remove('To');
		$this->headers->remove('Cc');
		$this->headers->remove('Bcc');
	}

	/**
	 * @param string $email Email de réponse
	 * @param string $name  Personnalisation
	 */
	public function setReplyTo($email = null, $name = null)
	{
		if (!empty($email)) {
			$email = trim($email);

			if (!empty($name)) {
				$email = sprintf('%s <%s>',
					Mime\Header::encode('Reply-To', $name, $this->charset, 'phrase'),
					$email
				);
			}
		}
		else {
			$email = $this->headers->get('From')->value;
		}

		$this->headers->set('Reply-To', $email);
	}

	/**
	 * @param string $email email pour la notification de lecture (par défaut,
	 *                      l’adresse d’expéditeur est utilisée)
	 */
	public function setNotify($email = null)
	{
		$this->headers->add(
			'Disposition-Notification-To',
			'<' . (!empty($email) ? trim($email) : $this->sender) . '>'
		);
	}

	/**
	 * Définition de l’adresse de retour pour les emails d’erreurs
	 *
	 * @param string $email mail de retour d’erreur (par défaut, l’adresse
	 *                      d’expéditeur est utilisée)
	 */
	public function setReturnPath($email = null)
	{
		$this->headers->set(
			'Return-Path',
			'<' . (!empty($email) ? trim($email) : $this->sender) . '>'
		);
	}

	/**
	 * Définition du niveau de priorité de l’email
	 *
	 * @param integer $priority Niveau de priorité de l’email
	 */
	public function setPriority($priority)
	{
		if (is_numeric($priority)) {
			$this->headers->set('X-Priority', $priority);
		}
	}

	/**
	 * @param string $str Nom de l’organisation/entreprise/etc émettrice
	 */
	public function organization($str)
	{
		$this->headers->set('Organization',
			Mime\Header::encode('Organization', $str, $this->charset)
		);
	}

	/**
	 * @param string $subject Le sujet de l’email
	 */
	public function setSubject($subject)
	{
		$this->headers->set('Subject',
			Mime\Header::encode('Subject', $subject, $this->charset)
		);
	}

	/**
	 * @param string $message Le message de l’email en texte brut
	 * @param string $charset Jeu de caractères de la chaîne contenue dans $message
	 *
	 * @return Mime\Part
	 */
	public function setTextBody($message, $charset = null)
	{
		if (is_null($charset)) {
			$charset = $this->charset;
		}

		$this->textPart = new Mime\Part($message);
		$this->textPart->headers->set('Content-Type', 'text/plain');
		$this->textPart->headers->set('Content-Transfer-Encoding', '8bit');
		$this->textPart->headers->get('Content-Type')->param('charset', $charset);
		$this->message_txt = '';

		return $this->textPart;
	}

	/**
	 * Supprime la partie texte de l'email
	 */
	public function removeTextBody()
	{
		$this->textPart = null;
		$this->message_txt = '';
	}

	/**
	 * @param string $message Le message de l’email au format HTML
	 * @param string $charset Jeu de caractères de la chaîne contenue dans $message
	 *
	 * @return Mime\Part
	 */
	public function setHTMLBody($message, $charset = null)
	{
		if (is_null($charset)) {
			$charset = $this->charset;
		}

		$this->htmlPart = new Mime\Part($message);
		$this->htmlPart->headers->set('Content-Type', 'text/html');
		$this->htmlPart->headers->set('Content-Transfer-Encoding', '8bit');
		$this->htmlPart->headers->get('Content-Type')->param('charset', $charset);
		$this->message_txt = '';

		return $this->htmlPart;
	}

	/**
	 * Supprime la partie HTML de l'email
	 */
	public function removeHTMLBody()
	{
		$this->htmlPart = null;
		$this->message_txt = '';
	}

	/**
	 * Attache un fichier comme pièce jointe de l’email
	 *
	 * @param string $filename    Chemin vers le fichier
	 * @param string $name        Nom de fichier
	 * @param string $type        Type MIME du fichier
	 * @param string $disposition Disposition
	 *
	 * @return Mime\Part
	 */
	public function attach($filename, $name = '', $type = '', $disposition = '')
	{
		if (!is_readable($filename)) {
			throw new Exception("Cannot read file '$filename'");
		}

		if (empty($name)) {
			$name = $filename;
		}

		if (empty($type)) {
			$type = Mime::getType($filename);
		}

		return $this->attachFromString(file_get_contents($filename), $name, $type, $disposition);
	}

	/**
	 * Attache un contenu comme pièce jointe de l’email
	 *
	 * @param string $data        Données à joindre
	 * @param string $name        Nom de fichier
	 * @param string $type        Type MIME des données
	 * @param string $disposition Disposition
	 *
	 * @return Mime\Part
	 */
	public function attachFromString($data, $name, $type = 'application/octet-stream', $disposition = '')
	{
		if ($disposition != 'inline') {
			$disposition = 'attachment';
		}
		$name = basename($name);

		$attach = new Mime\Part($data);
		$attach->headers->set('Content-Type', $type);
		$attach->headers->get('Content-Type')->param('name', $name);
		$attach->headers->set('Content-Disposition', $disposition);
		$attach->headers->get('Content-Disposition')->param('filename', $name);
		$attach->headers->get('Content-Disposition')->param('size', strlen($data));
		$attach->headers->set('Content-Transfer-Encoding', 'base64');

		$this->attachParts[] = $attach;
		$this->message_txt = '';

		return $attach;
	}

	/**
	 * Supprime les fichiers joints à l'email
	 */
	public function removeAttachments()
	{
		$this->attachParts = array();
		$this->message_txt = '';
	}

	/**
	 * Retourne l’email sous forme de chaîne formatée prète à l’envoi
	 *
	 * @return string
	 */
	public function __toString()
	{
		if (!$this->headers->get('Date')) {
			$this->headers->set('Date', date(DATE_RFC2822));
		}

		$this->headers->set('MIME-Version', '1.0');
		$this->headers->set('Message-ID', sprintf('<%d.%d@%s>', time(), mt_rand(), $this->hostname));

		$this->headers_txt = $this->headers->__toString();
		if (!empty($this->message_txt)) {
			return $this->headers_txt . $this->message_txt;
		}

		$rootPart = null;
		$attachParts = $this->attachParts;

		if (!is_null($this->htmlPart)) {
			$rootPart = $this->htmlPart;

			if (!is_null($this->textPart)) {
				$rootPart = new Mime\Part();
				$rootPart->addSubPart($this->textPart);
				$rootPart->addSubPart($this->htmlPart);
				$rootPart->headers->set('Content-Type', 'multipart/alternative');
			}

			$embedParts = array();
			foreach ($attachParts as &$attach) {
				if ($attach->headers->get('Content-ID') == null) {
					$name = $attach->headers->get('Content-Type')->param('name');
					$regexp = '/<([^>]+=\s*)(["\'])cid:' . preg_quote($name, '/') . '\\2([^>]*)>/S';

					if (!preg_match($regexp, $this->htmlPart->body)) {
						continue;
					}

					$cid = sprintf('%d.%d@%s', time(), mt_rand(), $this->hostname);
					$this->htmlPart->body = preg_replace($regexp,
						"<\\1\\2cid:$cid\\2\\3>",
						$this->htmlPart->body
					);
					$attach->headers->set('Content-ID', "<$cid>");
				}

				$embedParts[] = $attach;
				$attach = null;
			}

			if (count($embedParts) > 0) {
				$embedPart = new Mime\Part();
				$embedPart->addSubPart($rootPart);
				$embedPart->addSubPart($embedParts);
				$embedPart->headers->set('Content-Type', 'multipart/related');
				$embedPart->headers->get('Content-Type')->param('type',
					$rootPart->headers->get('Content-Type')->value
				);
				$rootPart = $embedPart;
			}
		}
		else if (!is_null($this->textPart)) {
			$rootPart = $this->textPart;
		}

		// filtrage nécessaire après la boucle de traitement des objets embarqués plus haut
		$attachParts = array_filter($attachParts);

		if (count($attachParts) > 0) {
			if (!is_null($rootPart)) {
				$mixedPart = new Mime\Part();
				$mixedPart->headers->set('Content-Type', 'multipart/mixed');
				$mixedPart->addSubPart($rootPart);
				$mixedPart->addSubPart($attachParts);
			}
			else if (count($attachParts) == 1) {
				$mixedPart = $attachParts[0];
			}
			else {
				$mixedPart = new Mime\Part();
				$mixedPart->headers->set('Content-Type', 'multipart/mixed');
				$mixedPart->addSubPart($attachParts);
			}

			$rootPart = $mixedPart;
		}

		//
		// Le corps d’un email est optionnel (cf. RFC 2822#3.5)
		//
		if (!is_null($rootPart)) {
			//
			// Par convention, un bref message informatif est ajouté aux emails
			// composés de plusieurs sous-parties, au cas où le client mail
			// ne supporterait pas ceux-ci…
			//
			if ($rootPart->isMultiPart()) {
				$rootPart->body = "This is a multi-part message in MIME format.";
			}

			$this->message_txt = $rootPart->__toString();
		}

		return $this->headers_txt . $this->message_txt;
	}

	public function __get($name)
	{
		switch ($name) {
			case 'headers':
			case 'textPart':
			case 'htmlPart':
				return $this->{$name};
				break;
			default:
				throw new Exception("Error while trying to get property '$name'");
				break;
		}
	}

	public function __clone()
	{
		$this->headers = clone $this->headers;

		if (!is_null($this->textPart)) {
			$this->textPart = clone $this->textPart;
		}

		if (!is_null($this->htmlPart)) {
			$this->htmlPart = clone $this->htmlPart;
		}

		foreach ($this->attachParts as &$attach) {
			$attach = clone $attach;
		}
	}
}
