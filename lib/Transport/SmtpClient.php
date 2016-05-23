<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2016 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 *
 * @see RFC 5321 - Simple Mail Transfer Protocol
 * @see RFC 4954 - SMTP Service Extension for Authentication
 * @see RFC 3207 - Secure SMTP over Transport Layer Security
 * @see RFC 2920 - SMTP Service Extension for Command Pipelining
 *
 * D’autres sources qui m’ont aidées :
 *
 * @link http://www.commentcamarche.net/internet/smtp.php3
 * @link http://www.iana.org/assignments/sasl-mechanisms/sasl-mechanisms.xhtml
 */

namespace Wamailer\Transport;

use Exception;

class SmtpClient
{
	/**
	 * Nom de l’hôte local.
	 * Utilisé dans les commandes EHLO/HELO.
	 * Doit être un nom de domaine pleinement qualifié (FQDN) ou, à défaut,
	 * une adresse IPv4 ou IPv6.
	 * Cette propriété est configurable également via self::options().
	 *
	 * Si laissée vide, une auto-détection est effectuée dans self::__construct().
	 *
	 * @see self::getLocalHost()
	 * @var string
	 */
	public $localhost   = '';

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
	 * Configurable également via self::options().
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
	 * Configurable également via self::options().
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
	 * Configurable également via self::options().
	 *
	 * @var boolean|callable
	 */
	public  $debug      = false;

	/**
	 * Options diverses.
	 * Les propriétés 'localhost', 'timeout', 'iotimeout' et 'debug' peuvent être
	 * configurées également au travers de la méthode self::options()
	 *
	 * @var array
	 */
	private $opts       = [
		/**
		 * Utilisation de la commande STARTTLS pour sécuriser la connexion.
		 * Ignoré si la connexion est sécurisée en utilisant un des préfixes de
		 * transport tls supportés par PHP.
		 * Si laissé à null, STARTTLS est automatiquement utilisé si le port
		 * de connexion est 587.
		 *
		 * @var boolean
		 */
		'starttls' => null,

		/**
		 * Alias pour ['stream_opts']['ssl'].
		 * Plus pratique dans la grande majorité des cas.
		 *
		 * @var array
		 */
		'ssl' => [],

		/**
		 * Utilisés pour la création du contexte de flux avec stream_context_create()
		 *
		 * @link http://php.net/stream_context_create
		 *
		 * @var array
		 */
		'stream_opts'   => [
			'ssl' => [
				'disable_compression' => true, // default value in PHP ≥ 5.6
			]
		],
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
		'auth' => [
			'methods' => ''
		]
	];

	/**
	 * Liste des extensions SMTP supportées.
	 *
	 * @see self::getExtensions() self::hello()
	 *
	 * @var array
	 */
	private $extensions = [];

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
	private $pipeline = [];

	/**
	 * Indique si une transaction est en cours.
	 * Permet par exemple d’éviter l’envoi d’une commande RSET, superflue
	 * si aucune transaction n’est en cours.
	 *
	 * @see RFC 5321#3.3 - Mail Transactions
	 *
	 * @var boolean
	 */
	private $inMailTransaction = false;

	/**
	 * Tableau compilant quelques informations sur la connexion en cours (accès en lecture).
	 *
	 * @var array
	 */
	private $serverInfos = [
		'host'      => '',
		'port'      => 0,
		// Annonce de présentation du serveur
		'greeting'  => '',
		// true si la connexion est chiffrée avec TLS
		'encrypted' => false,
		// true si le certificat a été vérifié
		'trusted'   => false,
		// Ressource de contexte de flux manipulable avec les fonctions stream_context_*
		'context'   => null
	];

	/**
	 * @param array $opts
	 */
	public function __construct(array $opts = [])
	{
		if (!$this->server) {
			$this->server = ini_get('SMTP');
			$port = ini_get('smtp_port');
			$this->server .= ':'.(($port > 0) ? $port : 25);
		}

		if (!strpos($this->server, '://')) {
			$this->server = 'tcp://'.$this->server;
		}

		$this->options($opts);

		if (!$this->localhost) {
			$this->localhost = $this->getLocalHost();
		}
	}

	/**
	 * Définition des options d’utilisation.
	 * Les options 'localhost', 'debug', 'timeout' et 'iotimeout' renvoient
	 * aux propriétés de classe de même nom.
	 * Le tableau 'ssl' est un alias pour le sous-tableau 'ssl' de l’option 'stream_opts'.
	 *
	 * @param array $opts
	 *
	 * @return array
	 */
	public function options(array $opts = [])
	{
		// Configuration alternative
		foreach (['localhost','debug','timeout','iotimeout'] as $name) {
			if (!empty($opts[$name])) {
				$this->{$name} = $opts[$name];
				unset($opts[$name]);
			}
		}

		// Alias
		if (isset($opts['ssl'])) {
			$opts['stream_opts']['ssl'] = $opts['ssl'];
			unset($opts['ssl']);
		}

		$this->opts = array_replace_recursive($this->opts, $opts);

		return $this->opts;
	}

	/**
	 * Retourne le nom d’hôte qualifié (FQDN).
	 * Si aucun nom qualifié n’a été trouvé, retourne l’adresse IP.
	 *
	 * @return string
	 */
	public function getLocalHost()
	{
		$valid_fqdn = function ($host) {
			$subdomain = '[A-Z0-9](?:[A-Z0-9-]*[A-Z0-9])?';
			return preg_match("/^$subdomain(?:\.$subdomain)+$/i", $host);
		};

		// fix: peut être incorrect. Cette variable indique sur quelle
		// interface le serveur HTTP a reçu la requête (donc en cas de requête
		// effectuée localement, l’IP sera par exemple 127.0.0.1 ou ::1).
		$ip = filter_input(INPUT_SERVER, 'SERVER_ADDR', FILTER_VALIDATE_IP);

		if ($ip) {
			$host = filter_input(INPUT_SERVER, 'SERVER_NAME');
			// Peut contenir le numéro de port
			$host = preg_replace('#:\d+$#', '', $host);

			if ($valid_fqdn($host)) {
				return $host;
			}
		}
		else {
			if (!($host = gethostname())) {
				$host = 'localhost';
			}

			$ip = gethostbyname($host); // IPv4 only

			// gethostbyname() peut avoir échoué
			if (!filter_var($ip, FILTER_VALIDATE_IP)) {
				$ip = '127.0.0.1';
			}

			$host = gethostbyaddr($ip);

			if ($valid_fqdn($host)) {
				return $host;
			}
		}

		return $ip;
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
		$this->pipeline     = [];

		if (!$server) {
			$server = $this->server;
		}

		if (!strpos($server, '://')) {
			$server = 'tcp://'.$server;
		}

		$url = parse_url($server);
		if (!$url) {
			throw new Exception("Invalid server argument given.");
		}

		$proto = substr($url['scheme'], 0, 3);
		$useTLS   = ($proto == 'tls');
		$startTLS = (!$useTLS) ? $this->opts['starttls'] : false;

		// Attribution du port par défaut si besoin
		if (empty($url['port'])) {
			$url['port'] = 25;
			if ($useTLS) {
				$url['port'] = 465;// SMTPS
			}
			else if ($startTLS) {
				$url['port'] = 587;// SMTP over TLS
			}

			$server .= ':'.$url['port'];
		}

		// check de l’extension openssl si besoin
		if (in_array('tls', stream_get_transports())) {
			if (is_null($startTLS) && $url['port'] == 587) {
				$startTLS = true;
			}
		}
		else if ($useTLS || $startTLS) {
			throw new Exception("Cannot use TLS because the openssl extension is not available!");
		}

		//
		// Ouverture du socket de connexion au serveur SMTP
		//
		$context = stream_context_create(
			$this->opts['stream_opts'],
			$this->opts['stream_params']
		);

		$this->socket = stream_socket_client(
			$server,
			$errno,
			$errstr,
			$this->timeout,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if (!$this->socket) {
			if ($errno == 0) {
				$errstr = 'Unknown error. Check PHP errors log to get more information.';
			}
			throw new Exception("Failed to connect to SMTP server ($errstr)");
		}

		stream_set_timeout($this->socket, $this->iotimeout);

		//
		// S: 220
		// E: 554
		//
		if (!$this->checkResponse(220)) {
			return false;
		}

		$greeting = rtrim(substr($this->responseData, 4));

		$this->hello($this->localhost);

		if ($startTLS) {
			$this->startTLS();
		}

		$infos = [];
		$infos['host']      = $url['host'];
		$infos['port']      = $url['port'];
		$infos['greeting']  = $greeting;
		$infos['encrypted'] = ($useTLS || $startTLS);
		$infos['trusted']   = ($infos['encrypted'] && PHP_VERSION_ID >= 50600);
		$infos['context']   = $context;

		if (isset($this->opts['stream_opts']['ssl']['verify_peer'])) {
			$infos['trusted'] = $this->opts['stream_opts']['ssl']['verify_peer'];
		}

		$this->serverInfos = $infos;

		if ($username && $secretkey) {
			return $this->authenticate($username, $secretkey);
		}

		return true;
	}

	/**
	 * Utilisation de la commande STARTTLS pour sécuriser la connexion.
	 *
	 * @throws Exception
	 */
	public function startTLS()
	{
		if (!$this->hasSupport('STARTTLS')) {
			throw new Exception("SMTP server doesn't support STARTTLS command");
		}

		//
		// S: 220
		// E: 454, 501
		//
		$this->put('STARTTLS');
		if (!$this->checkResponse(220)) {
			throw new Exception(sprintf(
				"STARTTLS command returned an error (%s)",
				$this->responseData
			));
		}

		$ssl_options   = $this->opts['stream_opts']['ssl'];
		$crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;

		if (isset($ssl_options['crypto_method'])) {
			$crypto_method = $ssl_options['crypto_method'];
		}
		// With PHP >= 5.6.7, *_TLS_CLIENT means TLS 1.0 only.
		// More infos: http://php.net/manual/en/function.stream-socket-enable-crypto.php#119122
		else if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
			$crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
			$crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
		}

		if (!stream_socket_enable_crypto($this->socket, true, $crypto_method)) {
			fclose($this->socket);
			throw new Exception("Cannot enable TLS encryption");
		}

		$this->hello($this->localhost);
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
			$codes = [$codes];
		}

		if (count($codes) == 0) {
			throw new Exception("No response code given!");
		}

		$this->pipeline[] = ['codes' => $codes, 'cmd' => $this->lastCommand];

		if ($this->hasSupport('pipelining') && $this->opts['pipelining'] &&
			in_array($this->lastCommand, [
				'RSET','MAIL FROM','SEND FROM','SOML FROM','SAML FROM','RCPT TO'
			])
		) {
			return true;
		}

		$pipeline = $this->pipeline;
		$this->pipeline = [];
		$result = true;

		for ($i = 0; $i < count($pipeline); $i++) {
			$this->responseData = '';

			if (!is_numeric($timeout) || $timeout < 1) {
				$timeout = $this->iotimeout;
			}

			stream_set_timeout($this->socket, (integer) ceil($timeout));

			do {
				$data = fgets($this->socket, 1024);

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
				continue;
			}

			if ($this->inMailTransaction) {
				if (in_array($pipeline[$i]['cmd'], ['EHLO','HELO','RSET','QUIT','.'])) {
					$this->inMailTransaction = false;
				}
			}
			else if ($pipeline[$i]['cmd'] == 'MAIL FROM') {
				$this->inMailTransaction = true;
			}
		}

		return $result;
	}

	/**
	 * Commande de démarrage EHLO ou HELO auprès du serveur
	 *
	 * @param string $localhost
	 *
	 * @throws Exception
	 * @return boolean
	 */
	public function hello($localhost)
	{
		if (!$localhost) {
			throw new Exception("Invalid localhost argument");
		}

		/**
		* Si l’hôte local s’identifie avec une IP, celle-ci doit être présentée
		* sous forme litérale comme décrit dans la RFC.
		*
		* @see RFC 5321#2.3.5 - Domain Names
		* @see RFC 5321#4.1.3 - Address Literals
		*/
		if (filter_var($localhost, FILTER_VALIDATE_IP)) {
			$literal = '[%s]';
			if (filter_var($localhost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
				$literal = '[IPv6:%s]';
			}

			$localhost = sprintf($literal, $localhost);
		}

		//
		// Comme on est poli, on dit bonjour
		//
		// S: 250
		// E: 502, 504, 550
		//
		$this->put(sprintf('EHLO %s', $localhost));
		if (!$this->checkResponse(250)) {
			$this->put(sprintf('HELO %s', $localhost));
			if (!$this->checkResponse(250)) {
				return false;
			}

			return true;
		}

		// On récupère la liste des extensions supportées par ce serveur
		$this->extensions = [];
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
		$supported_methods = ['CRAM-MD5','PLAIN','LOGIN'];

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

		return $this->checkResponse([250, 251]);
	}

	/**
	 * Envoie les données
	 *
	 * @param string $message
	 *
	 * @throws Exception
	 * @return boolean
	 */
	public function send($message)
	{
		$message = preg_replace('/\r\n?|\n/', "\r\n", $message);

		if (($maxsize = $this->hasSupport('SIZE')) && is_numeric($maxsize)) {
			$message_len = strlen($message);
			if ($message_len > $maxsize) {
				throw new Exception("The message length exceeds the maximum allowed by the server");
			}
		}

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
		if (!$this->inMailTransaction) {
			return true;
		}

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

		return $this->checkResponse([250, 251, 252]);
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

		$infos = [];
		$infos['host']      = '';
		$infos['port']      = 0;
		$infos['greeting']  = '';
		$infos['encrypted'] = false;
		$infos['trusted']   = false;
		$infos['context']   = null;

		$this->serverInfos = $infos;
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
			case 'serverInfos':
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
