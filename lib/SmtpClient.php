<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2015 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 *
 * @see RFC 5321 - Simple Mail Transfer Protocol
 * @see RFC 4954 - SMTP Service Extension for Authentication
 * @see RFC 3207 - Secure SMTP over Transport Layer Security
 * @see RFC 2920 - SMTP Service Extension for Command Pipelining
 *
 * Les sources qui m'ont bien aidées :
 *
 * @link http://abcdrfc.free.fr/ (français)
 * @link http://www.faqs.org/rfcs/ (anglais)
 * @link http://www.commentcamarche.net/internet/smtp.php3
 */

namespace Wamailer;

use Exception;

class SmtpClient
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
	 * Délai d’attent lors d’envoi/réception de données.
	 * La RFC 5321 recommande un certain délai (variable selon la commande)
	 * dans le traitement des commandes lors d’une transaction SMTP.
	 *
	 * La plupart des commandes sont suivies d'un délai max. t = iotimeout.
	 * Pour la commande DATA, on a t = ceil(iotimeout / 2).
	 * Pour la commande de fin d'envoi du message (.), t = iotimeout * 2.
	 * Lors des envois de données, t = ceil(iotimeout / 2).
	 *
	 * @see RFC 5321#4.5.3.2
	 *
	 * @var integer
	 */
	public  $iotimeout  = 300;

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
	 * Voir méthode SmtpClient::options()
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
		'stream_opts'   => array(
			'ssl' => array(
				'disable_compression' => true, // default value in PHP ≥ 5.6
			)
		),
		'stream_params' => null,

		/**
		 * Le pipelining est la capacité à envoyer un groupe de commandes sans
		 * attendre la réponse du serveur entre chaque commande.
		 * Le bénéfice est un gain de temps dans le dialogue opéré entre le
		 * client et le serveur.
		 * Seules certaines commandes sont concernées par ce mécanisme (RSET,
		 * MAIL FROM, SEND FROM, SOML FROM, SAML FROM, et RCPT TO).
		 * Les autres commandes imposent forcément une réponse immédiate du
		 * serveur (ou la fin de la connexion, dans le cas de QUIT).
		 * Cette option est ignorée si le serveur SMTP ne supporte pas
		 * l'extension PIPELINING.
		 *
		 * @see RFC 2920
		 *
		 * @var boolean
		 */
		'pipelining' => false,

		/**
		 * Liste des méthodes d'authentification utilisables.
		 * Utile si on veut forcer l'utilisation d'une ou plusieurs méthodes au choix.
		 * Les méthodes supportées par la classe sont CRAM-MD5, LOGIN et PLAIN.
		 *
		 * @var string
		 */
		'auth_methods' => ''
	);

	/**
	 * Liste des extensions SMTP supportées.
	 *
	 * @see self::getExtensions() self::hello()
	 *
	 * @var array
	 */
	private $extensions = array();

	/**
	 * Si la méthode from() n'a pas été appelée, la méthode to() le fait
	 * automatiquement en utilisant comme valeur l'option PHP 'sendmail_from'
	 * Réinitialisé à false après chaque transaction.
	 *
	 * @var boolean
	 */
	private $fromCalled = false;

	/**
	 * Dernier code de réponse retourné par le serveur.
	 * Accessible en lecture sous la forme $obj->responseCode
	 *
	 * @var integer
	 */
	private $_responseCode;

	/**
	 * Dernier message de réponse retourné par le serveur.
	 * Accessible en lecture sous la forme $obj->responseData
	 *
	 * @var string
	 */
	private $_responseData;

	/**
	 * Dernière commande transmise au serveur.
	 * Accessible en lecture sous la forme $obj->lastCommand
	 *
	 * @var string
	 */
	private $_lastCommand;

	/**
	 * Groupe de commandes en cours de traitement
	 *
	 * @var array
	 */
	private $pipeline = array();

	/**
	 * Nom de l'hôte.
	 * Utilisé dans les commandes EHLO ou HELO.
	 *
	 * @var string
	 */
	public $hostname = '';

	public function __construct()
	{
		if (empty($this->host)) {
			$this->host = ini_get('SMTP');
			$this->port = ini_get('smtp_port');
		}

		if (!$this->hostname) {
			if (!($this->hostname = gethostname())) {
				$this->hostname = (!empty($_SERVER['SERVER_NAME']))
					? $_SERVER['SERVER_NAME'] : 'localhost';
			}
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
			// Configuration alternative
			foreach (array('debug','timeout','iotimeout') as $name) {
				if (!empty($opts[$name])) {
					$this->{$name} = $opts[$name];
					unset($opts[$name]);
				}
			}

			$this->opts = array_replace_recursive($this->opts, $opts);
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

		$this->_responseCode = null;
		$this->_responseData = null;
		$this->fromCalled    = false;
		$this->_lastCommand  = null;
		$this->pipeline      = array();

		$useSSL   = preg_match('#^(ssl|tls)(v[.0-9]+)?://#', $host);
		$startTLS = (!$useSSL && $this->opts['starttls']);

		// check de l'extension openssl si besoin
		if (($useSSL || $startTLS) && !extension_loaded('openssl')) {
			throw new Exception("Cannot use SSL/TLS because the openssl extension isn't loaded!");
		}

		//
		// Ouverture du socket de connexion au serveur SMTP
		//
		$context_opts   = $this->opts['stream_opts'];
		$context_params = $this->opts['stream_params'];
		$context = stream_context_create($context_opts, $context_params);

		$this->socket = stream_socket_client(
			sprintf('%s:%d', $host, $port),
			$errno,
			$errstr,
			$this->timeout,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if (!$this->socket) {
			throw new Exception("Failed to connect to SMTP server ($errno - $errstr)");
		}

		stream_set_timeout($this->socket, $this->iotimeout);

		//
		// S: 220
		// E: 554
		//
		if (!$this->checkResponse(220)) {
			return false;
		}

		$this->hello($this->hostname);

		//
		// Le cas échéant, on utilise le protocole sécurisé TLS
		//
		// S: 220
		// E: 454, 501
		//
		if ($startTLS) {
			if (!$this->hasSupport('STARTTLS')) {
				throw new Exception("SMTP server doesn't support STARTTLS command");
			}

			$this->put('STARTTLS');
			if (!$this->checkResponse(220)) {
				return false;
			}

			$crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
			if (isset($context_opts['ssl']['crypto_method'])) {
				$crypto_method = $context_opts['ssl']['crypto_method'];
			}

			if (!stream_socket_enable_crypto($this->socket, true, $crypto_method)) {
				fclose($this->socket);
				throw new Exception("Cannot enable TLS encryption");
			}

			$this->hello($this->hostname);
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
		if (!$this->isConnected()) {
			throw new Exception("The connection was closed!");
		}

		$this->_lastCommand = (strpos($data, ':')) ? strtok($data, ':') : strtok($data, ' ');
		$data .= "\r\n";
		$this->log(sprintf('C: %s', $data));

		stream_set_timeout($this->socket, ceil($this->iotimeout / 2));

		while ($data) {
			$bw = fwrite($this->socket, $data);

			if (!$bw) {
				$md = stream_get_meta_data($this->socket);

				if ($md['timed_out']) {
					throw new Exception("The connection timed out!");
				}

				break;
			}

			$data = substr($data, $bw);
		}
	}

	/**
	 * Vérifie la réponse renvoyée par le serveur
	 *
	 * @return boolean
	 */
	public function checkResponse()
	{
		if (!$this->isConnected()) {
			throw new Exception("The connection was closed!");
		}

		$codes = array();
		$numargs = func_num_args();

		for ($i = 0; $i < $numargs; $i++) {
			$arg = func_get_arg($i);
			$codes[] = $arg;
		}

		$this->pipeline[] = array('codes' => $codes, 'cmd' => $this->_lastCommand);

		if ($this->hasSupport('pipelining') && $this->opts['pipelining'] &&
			in_array($this->_lastCommand, array(
				'RSET','MAIL FROM','SEND FROM','SOML FROM','SAML FROM','RCPT TO'
			))
		) {
			return true;
		}

		$pipeline = $this->pipeline;
		$this->pipeline = array();
		$result = true;

		for ($i = 0; $i < count($pipeline); $i++) {
			$this->_responseData = '';

			// voir commentaire de la propriété self::$iotimeout
			$timeout = $this->iotimeout;
			if ($this->_lastCommand == 'DATA') {
				$timeout /= 2;
			}
			else if ($this->_lastCommand == '.') {
				$timeout *= 2;
			}

			stream_set_timeout($this->socket, ceil($timeout));

			do {
				$data = fgets($this->socket);

				if (!$data) {
					$md = stream_get_meta_data($this->socket);

					if ($md['timed_out']) {
						throw new Exception("The connection timed out!");
					}

					break;
				}

				$this->log(sprintf('S: %s', $data));
				$this->_responseCode  = substr($data, 0, 3);
				$this->_responseData .= $data;
			}
			while (!feof($this->socket) && $data[3] != ' ');

			if (!in_array($this->_responseCode, $pipeline[$i]['codes'])) {
				$result = false;
			}
		}

		return $result;
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
		// S: 250
		// E: 502, 504, 550
		//
		$this->put(sprintf('EHLO %s', $hostname));
		if (!$this->checkResponse(250)) {
			$this->put(sprintf('HELO %s', $hostname));
			if (!$this->checkResponse(250)) {
				return false;
			}

			return true;
		}

		// On récupère la liste des extensions supportées par ce serveur
		$this->extensions = array();
		$lines = explode("\r\n", trim($this->_responseData));

		foreach ($lines as $line) {
			$line  = substr($line, 4);// on retire le code réponse
			// La RFC 5321 ne précise pas la casse des noms d’extension.
			// On normalise en haut de casse.
			$name  = strtoupper(strtok($line, ' '));
			$space = strpos($line, ' ');
			$this->extensions[$name] = ($space !== false)
				? strtoupper(substr($line, $space+1)) : true;
		}

		return true;
	}

	/**
	 * Retourne la liste des extensions supportées par le serveur SMTP.
	 * Les noms des extensions, ainsi que les éventuels paramètres, sont
	 * normalisés en haut de casse. Exemple :
	 * [
	 *     'VRFY' => true,
	 *     'SIZE' => 35651584,
	 *     'AUTH' => 'PLAIN LOGIN',
	 *     '8BITMIME' => true
	 * ]
	 *
	 * @return array
	 */
	public function getExtensions()
	{
		return $this->extensions;
	}

	/**
	 * Indique si l'extension ciblée est supportée par le serveur SMTP.
	 * Si l'extension possède des paramètres (par exemple, AUTH donne aussi la
	 * liste des méthodes supportées), ceux-ci sont retournés au lieu de true
	 *
	 * @param string $name Nom de l'extension (insensible à la casse)
	 *
	 * @return mixed
	 */
	public function hasSupport($name)
	{
		$name = strtoupper($name);

		if (isset($this->extensions[$name])) {
			return $this->extensions[$name];
		}

		return false;
	}

	/**
	 * Authentification auprès du serveur.
	 * Les méthodes CRAM-MD5, LOGIN et PLAIN sont supportées.
	 *
	 * @param string $username
	 * @param string $passwd
	 *
	 * @return boolean
	 */
	public function authenticate($username, $passwd)
	{
		if (!($available_methods = $this->hasSupport('AUTH'))) {
			throw new Exception("SMTP server doesn't support authentication");
		}

		$available_methods = explode(' ', $available_methods);
		$supported_methods = array('CRAM-MD5','LOGIN','PLAIN');

		if (!empty($this->opts['auth_methods'])) {
			$force_methods = explode(' ', $this->opts['auth_methods']);
			$supported_methods = array_intersect($force_methods, $supported_methods);
		}

		foreach ($supported_methods as $method) {
			if (!in_array($method, $available_methods)) {
				continue;
			}

			if ($method == 'CRAM-MD5' && !function_exists('hash_hmac')) {
				continue;
			}

			$this->put(sprintf('AUTH %s', $method));

			if (!$this->checkResponse(334)) {
				return false;
			}

			switch ($method) {
				case 'CRAM-MD5':
					$challenge = base64_decode(substr(rtrim($this->responseData), 4));

					$this->put(base64_encode(sprintf('%s %s',
						$username,
						hash_hmac('md5', $challenge, $passwd)
					)));
					if (!$this->checkResponse(235)) {
						return false;
					}
					break;
				case 'LOGIN':
					$this->put(base64_encode($username));
					if (!$this->checkResponse(334)) {
						return false;
					}

					$this->put(base64_encode($passwd));
					if (!$this->checkResponse(235)) {
						return false;
					}
					break;
				case 'PLAIN':
					$this->put(base64_encode("\0$username\0$passwd"));
					if (!$this->checkResponse(235)) {
						return false;
					}
					break;
			}

			return true;
		}

		throw new Exception("Cannot select an authentication mechanism");
	}

	/**
	 * Envoie la commande MAIL FROM
	 *
	 * @param string $email  Adresse email de l'expéditeur
	 * @param string $params Paramètres additionnels
	 *
	 * @return boolean
	 */
	public function from($email = null, $params = null)
	{
		$this->fromCalled = true;
		if (is_null($email)) {
			$email = ini_get('sendmail_from');
		}

		//
		// S: 250
		// E: 552, 451, 452, 550, 553, 503, 455, 555
		//
		$params = (!empty($params)) ? ' ' . $params : '';
		$this->put(sprintf('MAIL FROM:<%s>%s', $email, $params));

		return $this->checkResponse(250);
	}

	/**
	 * Envoie la commande RCPT TO
	 * Cette commande doit être invoquée autant de fois qu’il y a de destinataire.
	 * Si la méthode from() n’a pas été appelée auparavant, elle est appelée
	 * automatiquement.
	 *
	 * @param string $email  Adresse email du destinataire
	 * @param string $params Paramètres additionnels
	 *
	 * @return boolean
	 */
	public function to($email, $params = null)
	{
		if (!$this->fromCalled) {
			$this->from();
		}

		//
		// S: 250, 251
		// E: 550, 551, 552, 553, 450, 451, 452, 503, 455, 555
		//
		$params = (!empty($params)) ? ' ' . $params : '';
		$this->put(sprintf('RCPT TO:<%s>%s', $email, $params));

		return $this->checkResponse(250, 251);
	}

	/**
	 * Envoie les données
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
		// I: 354
		// E: 503, 554
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
		// S: 250
		// E: 450, 451, 452, 550, 552, 554
		//
		$this->put('.');
		if (!$this->checkResponse(250)) {
			return false;
		}

		$this->fromCalled = false;

		return true;
	}

	/**
	 * Envoie la commande NOOP
	 *
	 * @return boolean
	 */
	public function noop()
	{
		//
		// S: 250
		//
		$this->put('NOOP');

		return $this->checkResponse(250);
	}

	/**
	 * Envoie la commande RSET
	 *
	 * @return boolean
	 */
	public function reset()
	{
		//
		// S: 250
		//
		$this->put('RSET');

		return $this->checkResponse(250);
	}

	/**
	 * Envoie la commande VRFY
	 *
	 * @return boolean
	 */
	public function verify($str)
	{
		//
		// S: 250, 251, 252
		// E: 550, 551, 553, 502, 504
		//
		$this->put(sprintf('VRFY %s', $str));

		return $this->checkResponse(250, 251, 252);
	}

	/**
	 * Envoie la commande QUIT
	 * Termine le dialogue avec le serveur SMTP et ferme le socket de connexion
	 */
	public function quit()
	{
		//
		// Comme on est poli, on dit au revoir au serveur avec la commande QUIT
		//
		// S: 221
		//
		if (is_resource($this->socket)) {
			$this->put('QUIT');
			fclose($this->socket);
			$this->socket = null;
		}
	}

	/**
	 * @param string $str
	 */
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
			case 'lastCommand':
				return $this->{'_'.$name};
				break;
		}
	}

	public function __destruct()
	{
		$this->quit();
	}
}
