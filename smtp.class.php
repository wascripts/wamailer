<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2015 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 *
 * @see RFC 2821 - Simple Mail Transfer Protocol
 * @see RFC 2554 - SMTP Service Extension for Authentication
 * @see RFC 3207 - Secure SMTP over Transport Layer Security
 *
 * Les sources qui m'ont bien aidées :
 *
 * @link http://abcdrfc.free.fr/ (français)
 * @link http://www.faqs.org/rfcs/ (anglais)
 * @link http://www.commentcamarche.net/internet/smtp.php3
 * @link http://www.interpc.fr/mapage/billaud/telmail.htm
 */

class Mailer_SMTP
{
	/**
	 * Socket de connexion au serveur SMTP
	 *
	 * @var resource
	 */
	private $socket;

	/**
	 * Nom ou IP du serveur smtp à contacter
	 *
	 * @var string
	 */
	private $host       = '';

	/**
	 * Port d'accès
	 *
	 * @var integer
	 */
	private $port       = 25;

	/**
	 * Nom d'utilisateur pour l’authentification
	 *
	 * @var string
	 */
	private $username   = '';

	/**
	 * Mot de passe pour l’authentification
	 *
	 * @var string
	 */
	private $passwd     = '';

	/**
	 * Timeout de connexion
	 *
	 * @var integer
	 */
	public  $timeout    = 30;

	/**
	 * Débogage.
	 * true pour afficher sur la sortie standard ou bien toute valeur utilisable
	 * avec call_user_func()
	 *
	 * @var boolean|callable
	 */
	public  $debug      = false;

	/**
	 * Options diverses.
	 * Voir méthode Mailer_SMTP::options()
	 *
	 * @var array
	 */
	private $opts       = array(
		/**
		 * Utilisation de la commande STARTTLS pour sécuriser la connexion.
		 * Ignoré si la connexion est sécurisée en utilisant un des préfixes de
		 * transport ssl ou tls supportés par PHP.
		 *
		 * @var boolean
		 */
		'starttls' => false,

		/**
		 * Utilisés pour la création du contexte de flux avec stream_context_create()
		 *
		 * @link http://php.net/stream_context_create
		 *
		 * @var array
		 */
		'stream_context_options' => null,
		'stream_context_params'  => null
	);

	private $fromCalled = false;
	private $_responseCode;
	private $_responseData;

	public function __construct()
	{
		if (empty($this->host)) {
			$this->host = ini_get('SMTP');
			$this->port = ini_get('smtp_port');
		}
	}

	/**
	 * Définition des options d'utilisation
	 *
	 * @param array $opts
	 */
	public function options($opts)
	{
		if (is_array($opts)) {
			// Alternative pour l'activation du débogage
			if (!empty($opts['debug'])) {
				$this->debug = $opts['debug'];
			}

			$this->opts = array_merge($this->opts, $opts);
		}
	}

	/**
	 * Établit la connexion au serveur SMTP
	 *
	 * @param string  $host     Nom ou IP du serveur
	 * @param integer $port     Port d'accès
	 * @param string  $username Nom d'utilisateur pour l’authentification (si nécessaire)
	 * @param string  $passwd   Mot de passe pour l’authentification (si nécessaire)
	 *
	 * @return boolean
	 */
	public function connect($host = null, $port = null, $username = null, $passwd = null)
	{
		foreach (array('host', 'port', 'username', 'passwd') as $varname) {
			if (empty($$varname)) {
				$$varname = $this->{$varname};
			}
		}

		if (!($hostname = @php_uname('n'))) {
			$hostname = isset($_SERVER['SERVER_NAME']) ?
				$_SERVER['SERVER_NAME'] : 'localhost';
		}

		$this->_responseCode = null;
		$this->_responseData = null;

		$startTLS = false;
		if (!preg_match('#^(ssl|tls)(v[.0-9]+)?://#', $host)) {
			$startTLS = $this->opts['starttls'];
		}

		//
		// Ouverture du socket de connexion au serveur SMTP
		//
		$params = array();
		if (is_array($this->opts['stream_context_options'])) {
			$params[] = $this->opts['stream_context_options'];

			if (is_array($this->opts['stream_context_params'])) {
				$params[] = $this->opts['stream_context_params'];
			}
		}

		$context = call_user_func_array('stream_context_create', $params);

		$this->socket = stream_socket_client(
			sprintf('%s:%d', $host, $port),
			$errno,
			$errstr,
			$this->timeout,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if (!$this->socket) {
			throw new Exception("Mailer_SMTP::connect(): Failed to connect to SMTP server ($errno - $errstr)");
		}

		stream_set_timeout($this->socket, $this->timeout);

		//
		// Code success : 220
		// Code failure : 421
		//
		if (!$this->checkResponse(220)) {
			return false;
		}

		$this->hello($hostname);

		//
		// Le cas échéant, on utilise le protocole sécurisé TLS
		//
		// Code success : 220
		// Code error   : 421
		//
		if ($startTLS) {
			$this->put('STARTTLS');
			if (!$this->checkResponse(220)) {
				return false;
			}

			if (!stream_socket_enable_crypto(
				$this->socket,
				true,
				STREAM_CRYPTO_METHOD_TLS_CLIENT
			)) {
				return false;
			}

			$this->hello($hostname);
		}

		if (!empty($username) && !empty($passwd)) {
			return $this->authenticate($username, $passwd);
		}

		return true;
	}

	/**
	 * Vérifie l'état de la connexion
	 *
	 * @return boolean
	 */
	public function isConnected()
	{
		return is_resource($this->socket);
	}

	/**
	 * Envoi d'une commande au serveur
	 *
	 * @param string $data
	 */
	public function put($data)
	{
		$data .= "\r\n";
		$this->log($data);
		fwrite($this->socket, $data);
	}

	/**
	 * Récupère le code de réponse du serveur
	 *
	 * @return integer
	 */
	public function get()
	{
		while ($data = fgets($this->socket, 512)) {
			$this->log($data);
			$this->_responseData = rtrim($data);

			if (substr($data, 3, 1) == ' ') {
				$this->_responseCode = substr($data, 0, 3);
				break;
			}
		}

		return $this->_responseCode;
	}

	/**
	 * Vérifie la réponse renvoyée par le serveur
	 *
	 * @return boolean
	 */
	public function checkResponse()
	{
		$codesOK = array();
		$numargs = func_num_args();

		for ($i = 0; $i < $numargs; $i++) {
			$arg = func_get_arg($i);
			$codesOK[] = $arg;
		}

		$this->get();

		if (!in_array($this->_responseCode, $codesOK)) {// TODO afficher erreur comme ds wamailer 2.x ?

			return false;
		}

		return true;
	}

	/**
	 * Commande de démarrage EHLO ou HELO auprès du serveur
	 *
	 * @param string $hostname
	 *
	 * @return boolean
	 */
	public function hello($hostname)
	{
		//
		// Comme on est poli, on dit bonjour
		//
		// Code success : 250
		// Code error   : 500, 501, 504, 421
		//
		$this->put(sprintf('EHLO %s', $hostname));
		if (!$this->checkResponse(250)) {
			$this->put(sprintf('HELO %s', $hostname));
			if (!$this->checkResponse(250)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Authentification auprès du serveur (mode LOGIN uniquement)
	 *
	 * @param string $username
	 * @param string $passwd
	 *
	 * @return boolean
	 */
	public function authenticate($username, $passwd)
	{
		$this->put('AUTH LOGIN');
		if (!$this->checkResponse(334)) {
			return false;
		}

		$this->put(base64_encode($username));
		if (!$this->checkResponse(334)) {
			return false;
		}

		$this->put(base64_encode($passwd));
		if (!$this->checkResponse(235)) {
			return false;
		}

		return true;
	}

	/**
	 * Envoit la commande MAIL FROM
	 * Ceci indique au serveur SMTP l’adresse email expéditrice
	 *
	 * @param string $email
	 *
	 * @return boolean
	 */
	public function from($email = null)
	{
		$this->fromCalled = true;
		if (is_null($email)) {
			$email = ini_get('sendmail_from');
		}

		//
		// Code success : 250
		// Code failure : 552, 451, 452
		// Code error   : 500, 501, 421
		//
		$this->put(sprintf('MAIL FROM:<%s>', $email));

		return $this->checkResponse(250);
	}

	/**
	 * Envoit la commande RCPT TO
	 * Ceci indique au serveur SMTP l’adresse email du destinataire
	 * Cette commande doit être invoquée autant de fois qu’il y a de destinataire.
	 * Si la méthode from() n’a pas été appelée auparavant, elle est appelée
	 * automatiquement.
	 *
	 * @param string  $email
	 * @param boolean $strict (si true, retourne true uniquement si code 250)
	 *
	 * @return boolean
	 */
	public function to($email, $strict = false)
	{
		if (!$this->fromCalled) {
			$this->from();
		}

		//
		// Code success : 250, 251
		// Code failure : 550, 551, 552, 553, 450, 451, 452
		// Code error   : 500, 501, 503, 421
		//
		$this->put(sprintf('RCPT TO:<%s>', $email));

		return $strict ? $this->checkResponse(250) : $this->checkResponse(250, 251);
	}

	/**
	 * Envoie des données
	 *
	 * @param string $message
	 *
	 * @return boolean
	 */
	public function send($message)
	{
		$message = preg_replace('/\r\n?|\n/', "\r\n", $message);

		//
		// Si un point se trouve en début de ligne, on le double pour éviter
		// que le serveur ne l’interprète comme la fin de l’envoi.
		//
		$message = str_replace("\r\n.", "\r\n..", $message);

		//
		// On indique au serveur que l’on va lui livrer les données
		//
		// Code intermédiaire : 354
		//
		$this->put('DATA');
		if (!$this->checkResponse(354)) {
			return false;
		}

		// On envoie l’email proprement dit
		$this->put($message);

		//
		// On indique la fin des données au serveur
		//
		// Code success : 250
		// Code failure : 552, 554, 451, 452
		// Code error   : 500, 501, 503, 421
		//
		$this->put('.');
		if (!$this->checkResponse(250)) {
			return false;
		}

		return true;
	}

	/**
	 * Envoi la commande NOOP
	 *
	 * @return boolean
	 */
	public function noop()
	{
		/**
		 * Code success : 250
		 * Code error   : 500, 421
		 */
		$this->put('NOOP');

		return $this->checkResponse(250);
	}

	/**
	 * Envoi la commande RSET
	 *
	 * @return boolean
	 */
	public function reset()
	{
		/**
		 * Code success : 250
		 * Code error   : 500, 501, 504, 421
		 */
		$this->put('RSET');

		return $this->checkResponse(250);
	}

	/**
	 * Envoi la commande VRFY
	 *
	 * @return boolean
	 */
	public function verify($str)
	{
		/**
		 * Code success : 250, 251
		 * Code error   : 500, 501, 502, 504, 421
		 * Code failure : 550, 551, 553
		 */
		$this->put(sprintf('VRFY %s', $str));

		return $this->checkResponse(250, 251);
	}

	/**
	 * Envoi la commande QUIT
	 * Termine le dialogue avec le serveur SMTP et ferme le socket de connexion
	 */
	public function quit()
	{
		/**
		 * Comme on est poli, on dit au revoir au serveur avec la commande adéquat QUIT
		 *
		 * Code success : 221
		 * Code failure : 500
		 */
		if (is_resource($this->socket)) {
			$this->put('QUIT');
			fclose($this->socket);
			$this->socket = null;
		}
	}

	private function log($str)
	{
		if ($this->debug) {
			if (is_callable($this->debug)) {
				call_user_func($this->debug, $str);
			}
			else {
				echo $str;
				flush();
			}
		}
	}

	public function __get($name)
	{
		switch ($name) {
			case 'responseCode':
			case 'responseData':
				return $this->{'_'.$name};
				break;
		}
	}

	public function __destruct()
	{
		$this->quit();
	}
}
