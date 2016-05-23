<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2016 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 */

namespace Wamailer\Transport;

use Exception;
use Wamailer\Mailer;
use Wamailer\Email;

class Smtp extends Transport
{
	/**
	 * Tableau d’options pour ce transport.
	 *
	 * Les options non utilisées par la classe Smtp sont transmises telles
	 * quelles à la classe SmtpClient (voir SmtpClient::$opts pour les options
	 * reconnues par SmtpClient).
	 *
	 * @var array
	 */
	protected $opts = [
		/**
		 * Serveur SMTP à contacter.
		 * Format : 'hostname', 'hostname:port' ou encore 'tls://hostname:port'
		 * Les schemes ssl ou tls impliquent une connexion via le port 465,
		 * ce qui est obsolète. La méthode recommandée est une connexion
		 * standard sur le port 587, le client SMTP effectuant ensuite une
		 * commande STARTTLS pour démarrer la négociation TLS avec le serveur.
		 * Voyez l’option 'starttls' de la classe SmtpClient.
		 * Si IPv6, bien utiliser la syntaxe à crochets (eg: proto://[::1]:25)
		 *
		 * @var string
		 */
		'server' => '',

		/**
		 * Paramètres d’authentification. Les éventuels caractères non ASCII
		 * doivent être codés en UTF-8.
		 *
		 * @var array
		 */
		'auth' => [
			'username'  => '',
			'secretkey' => '' // 'password' est également accepté comme alias.
		],

		/**
		 * Permet de maintenir la connexion ouverte et donc de réaliser
		 * plusieurs transactions (= envois d’emails) durant la même connexion
		 * au serveur SMTP.
		 * Indispensable si on envoie des emails en boucle.
		 *
		 * @var boolean
		 */
		'keepalive' => false
	];

	/**
	 * Stockage de notre instance de la classe SmtpClient
	 *
	 * @var SmtpClient
	 */
	protected $smtp = null;

	/**
	 * Dernière réponse retournée par le serveur
	 *
	 * @var string
	 */
	protected $lastResponse = '';

	/**
	 * Traitement/envoi d’un email.
	 *
	 * @param Email $email
	 *
	 * @throws Exception
	 */
	public function send(Email $email)
	{
		// Récupération de l’expéditeur (à faire en premier)
		$sender = $email->getSender();

		// Préparation des en-têtes et du message
		$email  = $this->prepareMessage($email);

		//
		// Nous devons passer directement les adresses email des destinataires
		// au serveur SMTP.
		// On récupère les adresses des entêtes To, Cc et Bcc.
		//
		$recipients = [];
		foreach (['to', 'cc', 'bcc'] as $name) {
			$header = $email->headers->get($name);

			if (!is_null($header)) {
				$addressList = $header->value;
				$recipients = array_merge($recipients,
					Mailer::clearAddressList($addressList)
				);
			}
		}

		// L’entête Bcc ne doit pas apparaitre dans l’email envoyé.
		$email->headers->remove('Bcc');

		if (!($this->smtp instanceof SmtpClient) || !$this->smtp->isConnected()) {
			$this->connect();
		}
		else {
			$this->smtp->reset();
		}

		if (!$this->smtp->from($sender)) {
			$this->close();
			throw new Exception(sprintf("Sender address rejected (%s)", $this->lastResponse));
		}

		foreach ($recipients as $recipient) {
			if (!$this->smtp->to($recipient)) {
				$this->close();
				throw new Exception(sprintf("Recipient address rejected (%s)", $this->lastResponse));
			}
		}

		if (!$this->smtp->send($email->__toString())) {
			$this->close();
			throw new Exception(sprintf("Error while sending data (%s)", $this->lastResponse));
		}

		if (!$this->opts['keepalive']) {
			$this->close();
		}
	}

	/**
	 * Initialisation de la connexion au serveur.
	 *
	 * @throws Exception
	 */
	public function connect()
	{
		if (!($this->smtp instanceof SmtpClient)) {
			$this->smtp = new SmtpClient();
		}

		if ($this->smtp->isConnected()) {
			throw new Exception("Connection is already established!");
		}

		$server    = $this->opts['server'];
		$username  = $this->opts['auth']['username'];
		$secretkey = $this->opts['auth']['secretkey'];

		// alias 'password'
		if (!$secretkey && !empty($this->opts['auth']['password'])) {
			$secretkey = $this->opts['auth']['password'];
		}

		$this->smtp->options($this->opts);

		if (!$this->smtp->connect($server)) {
			$this->close();
			throw new Exception(sprintf("Failed to connect (%s)", $this->lastResponse));
		}

		if ($username && $secretkey && !$this->smtp->authenticate($username, $secretkey)) {
			$this->close();
			throw new Exception(sprintf("Failed to authenticate (%s)", $this->lastResponse));
		}
	}

	/**
	 * Wrapper pour SmtpClient::quit()
	 */
	public function close()
	{
		// On récupère d’abord la dernière réponse
		$this->lastResponse = rtrim($this->smtp->responseData);

		if ($this->smtp instanceof SmtpClient) {
			$this->smtp->quit();
		}
	}
}
