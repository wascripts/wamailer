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
 * D’autres source qui m’ont aidées :
 *
 * @link http://www.commentcamarche.net/internet/smtp.php3
 * @link http://www.iana.org/assignments/sasl-mechanisms/sasl-mechanisms.xhtml
 */

namespace Wamailer\Transport;

use Exception;

class SmtpClient
{
	/**
	 * Nom de l’hôte.
	 * Utilisé dans les commandes EHLO ou HELO.
	 * Configurable également via SmtpClient::options().
	 *
	 * @var string
	 */
	public $hostname = '';

	/**
	 * Socket de connexion au serveur SMTP
	 *
	 * @var resource
	 */
	private $socket;

	/**
	 * Nom ou IP du serveur SMTP à contacter, ainsi que le port.
	 *
	 * @var string
	 */
	private $server     = 'localhost:25';

	/**
	 * Timeout de connexion.
	 * Configurable également via SmtpClient::options().
	 *
	 * @var integer
	 */
	public  $timeout    = 30;

	/**
	 * Délai d’attente lors d’envoi/réception de données.
	 * La RFC 5321 recommande un certain délai (variable selon la commande)
	 * dans le traitement des commandes lors d’une transaction SMTP.
	 *
	 * La plupart des commandes sont suivies d’un délai max. t = iotimeout.
	 * Pour la commande DATA, on a t = ceil(iotimeout / 2).
	 * Pour la commande de fin d’envoi du message (.), t = iotimeout * 2.
	 * Lors des envois de données, t = ceil(iotimeout / 2).
	 *
	 * Configurable également via SmtpClient::options().
	 *
	 * @see RFC 5321#4.5.3.2 - Timeouts
	 *
	 * @var integer
	 */
	public  $iotimeout  = 300;

	/**
	 * Débogage.
	 * true pour afficher sur la sortie standard ou bien toute valeur utilisable
	 * avec call_user_func()
	 * Configurable également via SmtpClient::options().
	 *
	 * @var boolean|callable
	 */
	public  $debug      = false;

	/**
	 * Options diverses.
	 * Les propriétés 'hostname', 'timeout', 'iotimeout' et 'debug' peuvent être
	 * configurées également au travers de la méthode SmtpClient::options()
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
		 * serveur.
		 * Cette option est ignorée si le serveur SMTP ne supporte pas
		 * l’extension PIPELINING.
		 *
		 * @see RFC 2920
		 *
		 * @var boolean
		 */
		'pipelining' => false,

		/**
		 * Options concernant l’authentification auprès du serveur.
		 * 'methods':
		 * Liste des méthodes d’authentification utilisables.
		 * Utile si on veut restreindre la liste des méthodes utilisables ou
		 * bien changer l’ordre de préférence.
		 * Les méthodes supportées par la classe sont CRAM-MD5, PLAIN et LOGIN.
		 *
		 * @var string
		 */
		'auth' => array(
			'methods' => ''
		)
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
	 * Dernier code de réponse retourné par le serveur (accès en lecture).
	 *
	 * @var integer
	 */
	private $responseCode;

	/**
	 * Dernier message de réponse retourné par le serveur (accès en lecture).
	 *
	 * @var string
	 */
	private $responseData;

	/**
	 * Dernière commande transmise au serveur (accès en lecture).
	 *
	 * @var string
	 */
	private $lastCommand;

	/**
	 * Groupe de commandes en cours de traitement
	 *
	 * @var array
	 */
	private $pipeline = array();

	/**
	 * @param array $opts
	 */
	public function __construct(array $opts = array())
	{
		if (!$this->server) {
			$this->server = ini_get('SMTP');
			$port = ini_get('smtp_port');
			$this->server .= ':'.(($port > 0) ? $port : 25);
		}

		if (!strpos($this->server, '://')) {
			$this->server = 'tcp://'.$this->server;
		}

		if (!$this->hostname) {
			if (!($this->hostname = gethostname())) {
				$this->hostname = (!empty($_SERVER['SERVER_NAME']))
					? $_SERVER['SERVER_NAME'] : 'localhost';
			}
		}

		$this->options($opts);
	}

	/**
	 * Définition des options d’utilisation.
	 * Les options 'hostname', 'debug', 'timeout' et 'iotimeout' renvoient
	 * aux propriétés de classe de même nom.
	 *
	 * @param array $opts
	 *
	 * @return array
	 */
	public function options(array $opts = array())
	{
		// Configuration alternative
		foreach (array('hostname','debug','timeout','iotimeout') as $name) {
			if (!empty($opts[$name])) {
				$this->{$name} = $opts[$name];
				unset($opts[$name]);
			}
		}

		$this->opts = array_replace_recursive($this->opts, $opts);

		return $this->opts;
	}

	/**
	 * Établit la connexion au serveur SMTP
	 *
	 * @param string $server    Nom ou IP du serveur (hostname, proto://hostname, proto://hostname:port)
	 *                          Si IPv6, bien utiliser la syntaxe à crochets (eg: proto://[::1]:25)
	 * @param string $username  Nom d’utilisateur pour l’authentification (si nécessaire)
	 * @param string $secretkey Clé secrète pour l’authentification (si nécessaire)
	 *
	 * @throws Exception
	 * @return boolean
	 */
	public function connect($server = null, $username = null, $secretkey = null)
	{
		// Reset des données relatives à l’éventuelle connexion précédente
		$this->responseCode = null;
		$this->responseData = null;
		$this->lastCommand  = null;
		$this->pipeline     = array();

		if (!$server) {
			$server = $this->server;
		}

		if (!strpos($server, '://')) {
			$server = 'tcp://'.$server;
		}

		$port = parse_url($server, PHP_URL_PORT);
		if (!$port) {
			$server .= ':'.parse_url($this->server, PHP_URL_PORT);
		}

		$proto = substr(parse_url($server, PHP_URL_SCHEME), 0, 3);
		$useSSL   = ($proto == 'ssl' || $proto == 'tls');
		$startTLS = (!$useSSL && $this->opts['starttls']);

		// check de l’extension openssl si besoin
		if (($useSSL || $startTLS) && !in_array('tls', stream_get_transports())) {
			throw new Exception("Cannot use SSL/TLS because the openssl extension is not available!");
		}

		//
		// Ouverture du socket de connexion au serveur SMTP
		//
		$context_opts   = $this->opts['stream_opts'];
		$context_params = $this->opts['stream_params'];
		$context = stream_context_create($context_opts, $context_params);

		$this->socket = stream_socket_client(
			$server,
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
				throw new Exception(sprintf(
					"SMTP server returned an error after STARTTLS command (%s)",
					$this->responseData
				));
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

		if ($username && $secretkey) {
			return $this->authenticate($username, $secretkey);
		}

		return true;
	}

	/**
	 * Vérifie l’état de la connexion
	 *
	 * @return boolean
	 */
	public function isConnected()
	{
		return is_resource($this->socket);
	}

	/**
	 * Envoi d’une commande au serveur
	 *
	 * @param string $data
	 *
	 * @throws Exception
	 */
	public function put($data)
	{
		if (!$this->isConnected()) {
			throw new Exception("The connection was closed!");
		}

		$this->lastCommand = (strpos($data, ':')) ? strtok($data, ':') : strtok($data, ' ');
		$data .= "\r\n";
		$this->log(sprintf('C: %s', $data));

		stream_set_timeout($this->socket, (integer) ceil($this->iotimeout / 2));

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
	 * Vérifie la réponse renvoyée par le serveur.
	 *
	 * @param mixed   $codes   Codes retour acceptés. Peut être un code seul
	 *                         ou un tableau de codes.
	 * @param integer $timeout Timeout de lecture. Par défaut, $this->iotimeout
	 *                         sera utilisé.
	 *
	 * @throws Exception
	 * @return boolean
	 */
	public function checkResponse($codes, $timeout = 0)
	{
		if (!$this->isConnected()) {
			throw new Exception("The connection was closed!");
		}

		if (!is_array($codes)) {
			$codes = array($codes);
		}

		if (count($codes) == 0) {
			throw new Exception("No response code given!");
		}

		$this->pipeline[] = array('codes' => $codes, 'cmd' => $this->lastCommand);

		if ($this->hasSupport('pipelining') && $this->opts['pipelining'] &&
			in_array($this->lastCommand, array(
				'RSET','MAIL FROM','SEND FROM','SOML FROM','SAML FROM','RCPT TO'
			))
		) {
			return true;
		}

		$pipeline = $this->pipeline;
		$this->pipeline = array();
		$result = true;

		for ($i = 0; $i < count($pipeline); $i++) {
			$this->responseData = '';

			if (!is_numeric($timeout) || $timeout < 1) {
				$timeout = $this->iotimeout;
			}

			stream_set_timeout($this->socket, (integer) ceil($timeout));

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
				$this->responseCode  = substr($data, 0, 3);
				$this->responseData .= $data;
			}
			while (!feof($this->socket) && $data[3] != ' ');

			if (!in_array($this->responseCode, $pipeline[$i]['codes'])) {
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
		$lines = explode("\r\n", trim($this->responseData));

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
	 * Indique si l’extension ciblée est supportée par le serveur SMTP.
	 * Si l’extension possède des paramètres (par exemple, AUTH donne aussi la
	 * liste des méthodes supportées), ceux-ci sont retournés au lieu de true
	 *
	 * @param string $name Nom de l’extension (insensible à la casse)
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
	 * Les méthodes CRAM-MD5, PLAIN et LOGIN sont supportées.
	 *
	 * À noter que la méthode LOGIN a été marquée "OBSOLETE" par l’IANA.
	 * @see http://www.iana.org/assignments/sasl-mechanisms/sasl-mechanisms.xhtml
	 *
	 * @param string $username  Les caractères non ASCII doivent être codés en UTF-8.
	 * @param string $secretkey Les caractères non ASCII doivent être codés en UTF-8.
	 *
	 * @throws Exception
	 * @return boolean
	 */
	public function authenticate($username, $secretkey)
	{
		if (strpos($username.$secretkey, "\x00") !== false) {
			throw new Exception("The null byte is not allowed in the username or secretkey.");
		}

		if (!($available_methods = $this->hasSupport('AUTH'))) {
			throw new Exception("SMTP server doesn't support authentication");
		}

		$available_methods = explode(' ', $available_methods);
		$supported_methods = array('CRAM-MD5','PLAIN','LOGIN');

		if (!empty($this->opts['auth']['methods'])) {
			$force_methods = explode(' ', $this->opts['auth']['methods']);
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
						hash_hmac('md5', $challenge, $secretkey)
					)));
					if (!$this->checkResponse(235)) {
						return false;
					}
					break;
				case 'PLAIN':
					$this->put(base64_encode("\0$username\0$secretkey"));
					if (!$this->checkResponse(235)) {
						return false;
					}
					break;
				case 'LOGIN':
					$this->put(base64_encode($username));
					if (!$this->checkResponse(334)) {
						return false;
					}

					$this->put(base64_encode($secretkey));
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
	 * @param string $email  Adresse email de l’expéditeur
	 * @param string $params Paramètres additionnels
	 *
	 * @return boolean
	 */
	public function from($email, $params = null)
	{
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
	 *
	 * @param string $email  Adresse email du destinataire
	 * @param string $params Paramètres additionnels
	 *
	 * @return boolean
	 */
	public function to($email, $params = null)
	{
		//
		// S: 250, 251
		// E: 550, 551, 552, 553, 450, 451, 452, 503, 455, 555
		//
		$params = (!empty($params)) ? ' ' . $params : '';
		$this->put(sprintf('RCPT TO:<%s>%s', $email, $params));

		return $this->checkResponse(array(250, 251));
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
		if (!$this->checkResponse(354, ($this->iotimeout / 2))) {
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
		if (!$this->checkResponse(250, ($this->iotimeout * 2))) {
			return false;
		}

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

		return $this->checkResponse(array(250, 251, 252));
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
			// Inutile, mais autant quitter proprement dans les règles.
			$this->checkResponse(221);
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

	/**
	 * Lecture des propriétés non publiques autorisées.
	 *
	 * @param string $name Nom de la propriété
	 *
	 * @throws Exception
	 * @return mixed
	 */
	public function __get($name)
	{
		switch ($name) {
			case 'responseCode':
			case 'responseData':
			case 'lastCommand':
				return $this->{$name};
				break;
			default:
				throw new Exception("Error while trying to get property '$name'");
				break;
		}
	}

	/**
	 * Destructeur de classe.
	 * On s’assure de fermer proprement la connexion s’il y a lieu.
	 */
	public function __destruct()
	{
		$this->quit();
	}
}
