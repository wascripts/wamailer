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
		'c' => 'relaxed/relaxed',
		'q' => 'dns/txt',
		'd' => null,
		's' => null,
		't' => null,
		'h' => 'from:to:subject:date',
	);

	/**
	 * @param array $opts
	 */
	public function __construct(array $opts = array())
	{
		$this->options($opts);
	}

	/**
	 * Définition d’options supplémentaires.
	 *
	 * Les tags DKIM peuvent être transmis par cette méthode en les
	 * préfixant avec 'tag:'. Exemple :
	 * $dkim->options(['tag:c' => 'relaxed/relaxed']);
	 *
	 * Ainsi, les tags sont aussi transmissibles directement avec
	 * le tableau d’options fourni au constructeur.
	 *
	 * @param array $opts
	 *
	 * @return array
	 */
	public function options(array $opts = array())
	{
		// Alias pour une meilleure lisibilité.
		if (isset($opts['domain'])) {
			$opts['tag:d'] = $opts['domain'];
		}

		if (isset($opts['selector'])) {
			$opts['tag:s'] = $opts['selector'];
		}

		foreach ($opts as $key => $value) {
			if (strncmp($key, 'tag:', 4) !== 0) {
				continue;
			}

			$this->setTag(substr($key, 4), $value);
			unset($opts[$key]);
		}

		$this->opts = array_replace_recursive($this->opts, $opts);

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

		$tagval = trim($tagval);

		switch ($tagname) {
			case 'v':
				trigger_error("The value for dkim tag 'v' is not settable.", E_USER_NOTICE);
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
			default:
				if (!preg_match('#^[\x21-\x3A\x3C-\x7E\s]*$#', $tagval)) {
					trigger_error("Invalid value for dkim tag '$tagname', according to RFC 4871.", E_USER_WARNING);
					$tagval = null;
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

		// Canonicalisation et hashage du corps du mail avec les
		// paramètres spécifiés
		$body = $this->canonicalizeBody($body, $body_c);
		$body = base64_encode(hash($hash_algo, $body, true));
		$body = rtrim(chunk_split($body, 74, "\r\n\t"));

		// Définition des tags DKIM et création de l’en-tête DKIM-Signature
		$dkim_tags = $this->tags;
		$dkim_tags['bh'] = $body;

		if (!$dkim_tags['t']) {
			$dkim_tags['t'] = time();
		}

		$dkim_header = 'DKIM-Signature: ';
		foreach ($dkim_tags as $name => $value) {
			$dkim_header .= "$name=$value; ";
		}
		$dkim_header  = rtrim(wordwrap($dkim_header, 77, "\r\n\t"));
		$dkim_header .= "\r\n\tb=";

		// On récupère les en-têtes à signer.
		$headers_to_sign = explode(':', strtolower($dkim_tags['h']));
		$headers_to_sign = array_map('trim', $headers_to_sign);

		$headers = preg_split('#\r\n(?![\t ])#', rtrim($headers));
		foreach ($headers as $header) {
			$name = trim(strtolower(strtok($header, ':')));
			$headers[$name][] = $header;
		}

		// Il doit y avoir un en-tête 'From' à signer.
		if (!in_array('from', $headers_to_sign) || !isset($headers['from'])) {
			trigger_error("Cannot sign mail without 'from' in tag 'h' or message headers", E_USER_WARNING);
			return '';
		}

		$unsigned_headers = '';
		foreach ($headers_to_sign as $name) {
			if (!empty($headers[$name])) {
				/**
				 * Si on signe plusieurs en-têtes de même nom, il faut
				 * les signer dans l’ordre inverse d’occurence.
				 * @see RFC 4871#5.4 - Determine the Header Fields to Sign
				 */
				$header = array_pop($headers[$name]);
				$unsigned_headers .= $this->canonicalizeHeader($header, $headers_c);
				$unsigned_headers .= "\r\n";
			}
		}

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
	 *
	 * @return string
	 */
	protected function canonicalizeBody($body, $canonicalization = 'simple')
	{
		$body = rtrim($body, "\r\n")."\r\n";

		if ($canonicalization == 'relaxed') {
			$body = explode("\r\n", $body);

			foreach ($body as &$line) {
				$line = rtrim(preg_replace('#\s+#', ' ', $line));
			}

			$body = implode("\r\n", $body);
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
		// On ne peut rien faire sans OpenSSL
		if (!function_exists('openssl_pkey_get_private')) {
			trigger_error("Cannot sign mail because the openssl extension is not available!", E_USER_WARNING);
			return null;
		}

		$privkey = $this->opts['privkey'];

		// Pas de clé, ou bien la tentative de lecture précédente a échoué.
		if (is_null($privkey)) {
			return null;
		}

		if (!is_resource($privkey)) {
			if (strpos($privkey, '-----BEGIN') === false && strpos($privkey, 'file://') === false) {
				$privkey = 'file://'.$privkey;
			}

			if (!($privkey = openssl_pkey_get_private($privkey, $this->opts['passphrase']))) {
				trigger_error(sprintf("Could not read private key. (OpenSSL said: %s)",
					openssl_error_string()),
					E_USER_WARNING
				);
				$privkey = null;
			}

			$this->opts['privkey'] = $privkey;
		}

		return $privkey;
	}
}
