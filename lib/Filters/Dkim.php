<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2016 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 *
 * @see RFC 4871 - DomainKeys Identified Mail (DKIM) Signatures
 *
 * @link http://dkim.org/specs/rfc4871-dkimbase.html
 */

namespace Wamailer\Filters;

class Dkim
{
	/**
	 * Tableau d’options par défaut.
	 *
	 * @var array
	 */
	protected $opts = array(
		'privkey'    => null,
		'passphrase' => null,
		'domain'     => null, // Alias pour le tag DKIM 'd'
		'selector'   => null, // Alias pour le tag DKIM 's'
		'debug'      => false,// Pour ajouter le tag DKIM 'z'
	);

	/**
	 * Paramètres DKIM par défaut.
	 * Les tags DKIM peuvent être ajoutés avec la méthode self::setTag().
	 * Exemple :
	 * $dkim->setTag('c', 'relaxed/relaxed');
	 * Ils peuvent aussi être ajoutés par la méthode self::options(),
	 * et donc aussi directement dans le tableau transmis au constructeur.
	 *
	 * @var array
	 */
	private $tags = array(
		'v' => 1,
		'a' => 'rsa-sha256',
		'c' => 'relaxed',
		'q' => 'dns/txt',
		'd' => null,
		's' => null,
		'h' => 'from:to:subject',
	);

	/**
	 * @param array $opts
	 */
	public function __construct(array $opts = array())
	{
		$this->tags['t'] = time();
		$this->tags['x'] = -1;
		$this->tags['l'] = -1;

		$this->options($opts);
	}

	/**
	 * Définition d’options supplémentaires.
	 *
	 * Les tags DKIM peuvent être transmis par cette méthode en utilisant
	 * l’entrée 'tags'. Exemple :
	 * $tags['c'] = 'relaxed/relaxed';
	 * $tags['h'] = 'from:to:subject:myheader';
	 * $dkim->options(['tags' => $tags]);
	 *
	 * Les tags sont donc aussi transmissibles directement avec
	 * le tableau d’options fourni au constructeur.
	 *
	 * @param array $opts
	 *
	 * @return array
	 */
	public function options(array $opts = array())
	{
		if (isset($opts['domain'])) {
			$opts['tags']['d'] = $opts['domain'];
		}

		if (isset($opts['selector'])) {
			$opts['tags']['s'] = $opts['selector'];
		}

		if (isset($opts['tags']) && is_array($opts['tags'])) {
			foreach ($opts['tags'] as $tagname => $tagval) {
				$this->setTag($tagname, $tagval);
			}
			unset($opts['tags']);
		}

		$this->opts = array_replace_recursive($this->opts, $opts);

		// Le but ici est de forcer la lecture de la clé privée le plus
		// tôt possible et éviter de conserver la clé au format PEM
		// et l’éventuel passphrase dans le tableau d’options.
		$this->getPrivKey();

		return $this->opts;
	}

	/**
	 * Tests sur le nom du tag DKIM et sa valeur, puis ajout/modification
	 * dans le tableau $tags.
	 *
	 * @see RFC 4871#3.2 - Tag=Value Lists
	 *
	 * @param string $tagname
	 * @param string $tagval
	 *
	 * @return boolean
	 */
	public function setTag($tagname, $tagval)
	{
		if (!preg_match('#^[a-z][a-z0-9_]*$#i', $tagname)) {
			trigger_error("Invalid dkim tag name '$tagname', according to RFC 4871.", E_USER_WARNING);
			return false;
		}

		if (!is_scalar($tagval)) {
			$tagval = null;
		}

		switch ($tagname) {
			case 'v':
			case 'z':
				trigger_error("The value for dkim tag '$tagname' is not settable.", E_USER_NOTICE);
				$tagval = null;
				break;
			case 'c':
				foreach (explode('/', $tagval, 2) as $c_val) {
					if ($c_val != 'relaxed' && $c_val != 'simple') {
						trigger_error("Incorrect value for dkim tag 'c'."
							. "Acceptable values are 'relaxed' or 'simple',"
							. "or a combination of both, separated by a slash.", E_USER_WARNING);
						$tagval = null;
						break;
					}
				}
				break;
			case 'a':
				if (!preg_match('#^[a-z][a-z0-9]*-[a-z][a-z0-9]*$#i', $tagval)) {
					trigger_error("Incorrect value for dkim tag 'a'.", E_USER_WARNING);
					$tagval = null;
				}
				break;
			case 't':
			case 'x':
				try {
					new \DateTime('@' . $tagval);
				}
				catch(\Exception $e) {
					trigger_error("Invalid timestamp value for dkim tag '$tagname'.", E_USER_WARNING);
					$tagval = null;
				}
				break;
			case 'l':
				if ($tagval !== true) {
					$tagval = intval($tagval);
				}
				break;
			default:
				if (preg_match('#[^\x21-\x3A\x3C-\x7E\s]#', $tagval)) {
					$tagval = $this->encodeQuotedPrintable($tagval);
				}
				break;
		}

		if (!is_null($tagval)) {
			$this->tags[$tagname] = $tagval;
			return true;
		}

		return false;
	}

	/**
	 * Génération de l'en-tête DKIM-Signature
	 *
	 * @param string $headers
	 * @param string $body
	 *
	 * @return string
	 */
	public function sign($headers, $body)
	{
		// Récupération de la clé
		if (!($privkey = $this->getPrivKey())) {
			return '';
		}

		// Formats canonique
		$headers_c = $this->tags['c'];
		$body_c    = 'simple';

		if (strpos($this->tags['c'], '/')) {
			list($headers_c, $body_c) = explode('/', $this->tags['c']);
		}

		// Algorithmes de chiffrement et de hashage
		list($crypt_algo, $hash_algo) = explode('-', $this->tags['a']);

		// Définition des tags DKIM
		$dkim_tags = $this->tags;

		if ($dkim_tags['x'] <= $dkim_tags['t']) {
			unset($dkim_tags['x']);
		}

		// On récupère les en-têtes à signer.
		// (RFC 4871#5.4 - Determine the Header Fields to Sign)
		$headers_to_sign = explode(':', strtolower($dkim_tags['h']));
		$headers_to_sign = array_map('trim', $headers_to_sign);

		$headers = preg_split('#\r\n(?![\t ])#', rtrim($headers));
		foreach ($headers as $header) {
			$name = trim(strtolower(strtok($header, ':')));
			$headers[$name][] = $header;
		}

		if (!in_array('from', $headers_to_sign) || !isset($headers['from'])) {
			trigger_error("Cannot sign mail without 'from' in tag 'h' or message headers", E_USER_WARNING);
			return '';
		}

		$unsigned_headers = '';
		foreach ($headers_to_sign as $name) {
			if (!empty($headers[$name])) {
				// Les en-têtes à multiples occurences doivent être signés
				// dans l’ordre inverse d’apparition.
				$header = array_pop($headers[$name]);
				$header = $this->canonicalizeHeader($header, $headers_c);
				$unsigned_headers .= $header . "\r\n";

				if ($this->opts['debug']) {
					$dkim_tags['z'][] = $this->encodeQuotedPrintable($header, '|');
				}
			}
		}

		if ($this->opts['debug']) {
			$dkim_tags['z'] = implode('|', $dkim_tags['z']);
		}

		// Canonicalisation et hashage du corps du mail avec les
		// paramètres spécifiés
		$body = $this->canonicalizeBody($body, $body_c, $dkim_tags['l']);
		$body = base64_encode(hash($hash_algo, $body, true));
		$body = rtrim(chunk_split($body, 74, "\r\n\t"));
		$dkim_tags['bh'] = $body;

		if ($dkim_tags['l'] < 0) {
			unset($dkim_tags['l']);
		}

		// création de l’en-tête DKIM-Signature
		$dkim_header = 'DKIM-Signature: ';
		foreach ($dkim_tags as $name => $value) {
			$dkim_header .= "$name=$value; ";
		}
		$dkim_header  = rtrim(wordwrap($dkim_header, 77, "\r\n\t"));
		$dkim_header .= "\r\n\tb=";

		$unsigned_headers .= $this->canonicalizeHeader($dkim_header, $headers_c);

		// Génération de la signature proprement dite par OpenSSL.
		$result = openssl_sign($unsigned_headers, $signature, $privkey, $hash_algo);
		if (!$result) {
			trigger_error(sprintf("Could not sign mail. (OpenSSL said: %s)",
				openssl_error_string()),
				E_USER_WARNING
			);
			return '';
		}

		// On ajoute la signature à l’en-tête dkim
		$dkim_header .= rtrim(chunk_split(base64_encode($signature), 75, "\r\n\t"));
		$dkim_header .= "\r\n";

		return $dkim_header;
	}

	/**
	 * Transformation de l’en-tête dans le format canonique spécifié.
	 * Seul le format 'relaxed' apporte des changements dans le cas des
	 * en-têtes.
	 *
	 * @see RFC 4871#3.4 - Canonicalization
	 *
	 * @param string $header
	 * @param string $canonicalization
	 *
	 * @return string
	 */
	protected function canonicalizeHeader($header, $canonicalization = 'simple')
	{
		if ($canonicalization == 'simple') {
			return $header;
		}

		list($name, $value) = explode(':', $header, 2);

		$name   = strtolower($name);
		$value  = trim(preg_replace('#\s+#', ' ', $value));
		$header = "$name:$value";

		return $header;
	}

	/**
	 * Transformation du message dans le format canonique spécifié.
	 *
	 * @see RFC 4871#3.4 - Canonicalization
	 *
	 * @param string $body
	 * @param string $canonicalization
	 * @param mixed  $len
	 *
	 * @return string
	 */
	protected function canonicalizeBody($body, $canonicalization = 'simple', &$len = -1)
	{
		$body = rtrim($body, "\r\n")."\r\n";

		if ($canonicalization == 'relaxed') {
			$body = explode("\r\n", $body);

			foreach ($body as &$line) {
				$line = rtrim(preg_replace('#\s+#', ' ', $line));
			}

			$body = implode("\r\n", $body);
		}

		// $len à true est un cas de figure spécial où on veut forcer
		// la présence du tag 'l'.
		$body_len = strlen($body);
		if ($len === true) {
			$len = $body_len;
		}
		else if ($len >= 0 && $len <= $body_len) {
			if ($len < $body_len) {
				$body = substr($body, 0, $len);
			}
		}
		else {
			$len = -1;
		}

		return $body;
	}

	/**
	 * Teste la disponibilité d’OpenSSL et retourne la clé privée.
	 *
	 * @return resource
	 */
	protected function getPrivKey()
	{
		$privkey =& $this->opts['privkey'];

		if (!is_null($privkey) && !is_resource($privkey)) {
			$privkey = trim($privkey);
			if (strpos($privkey, '-----BEGIN') === false && strpos($privkey, 'file://') === false) {
				$privkey = 'file://'.$privkey;
			}

			if (!function_exists('openssl_pkey_get_private')) {
				trigger_error("Cannot sign mail because the openssl extension is not available!", E_USER_WARNING);
			}
			else if (!($privkey = openssl_pkey_get_private($privkey, $this->opts['passphrase']))) {
				trigger_error(sprintf("Cannot read private key. (OpenSSL said: %s)",
					openssl_error_string()),
					E_USER_WARNING
				);
			}

			$this->opts['passphrase'] = null;

			if (!is_resource($privkey)) {
				$privkey = null;
			}
		}

		return $privkey;
	}

	/**
	 * Encodage "Quoted Printable" à la sauce DKIM.
	 *
	 * @see RFC 4871#2.6 - DKIM-Quoted-Printable
	 *
	 * @param string $str
	 * @param string $charlist Caractères additionnels à encoder
	 *
	 * @return string
	 */
	public function encodeQuotedPrintable($str, $charlist = '')
	{
		$charlist = preg_quote(preg_replace('/[0-9A-F=]/', '', $charlist), '#');
		$str = quoted_printable_encode($str);
		$str = str_replace("=\r\n", '', $str);// Remove soft line break
		$str = preg_replace_callback("#[\s;$charlist]#", function ($m) {
			return sprintf('=%02X', ord($m[0]));
		}, $str);

		return $str;
	}
}
