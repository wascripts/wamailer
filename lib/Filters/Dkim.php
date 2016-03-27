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
	 * Tableau d’options pour ce filtre.
	 *
	 * @var array
	 */
	protected $opts = array(
		'pkey'       => null,
		'passphrase' => null,
		// Paramètres DKIM
		'tag-a' => 'rsa-sha256',
		'tag-c' => 'relaxed/relaxed',
		'tag-q' => 'dns/txt',
		'tag-d' => null,
		'tag-s' => null,
		'tag-h' => 'from:to:subject:date',
	);

	/**
	 * @param array $opts
	 */
	public function __construct(array $opts = array())
	{
		$this->options($opts);
	}

	/**
	 * Définition des options supplémentaires pour ce transport.
	 *
	 * @param array $opts
	 *
	 * @return array
	 */
	public function options(array $opts = array())
	{
		// Alias pour une meilleure lisibilité.
		if (isset($opts['domain'])) {
			$opts['tag-d'] = $opts['domain'];
		}

		if (isset($opts['selector'])) {
			$opts['tag-s'] = $opts['selector'];
		}

		if (isset($opts['tag-c'])) {
			$canonicalization = $opts['tag-c'];
			if (!strpos($canonicalization, '/')) {
				$canonicalization .= '/simple';
			}

			foreach (explode('/', $canonicalization, 2) as $c_val) {
				if ($c_val != 'relaxed' && $c_val != 'simple') {
					trigger_error("Incorrect value for dkim tag 'c'.", E_USER_WARNING);
					unset($opts['tag-c']);
					break;
				}
			}
		}

		if (isset($opts['tag-a'])) {
			if (!preg_match('#^[a-z][a-z0-9]*-[a-z][a-z0-9]*#i', $opts['tag-a'])) {
				trigger_error("Incorrect value for dkim tag 'a'.", E_USER_WARNING);
				unset($opts['tag-a']);
			}
		}

		if (isset($opts['tag-h'])) {
			if (!$opts['tag-h']) {
				trigger_error("Incorrect value for dkim tag 'h'. Must not be empty.", E_USER_WARNING);
				unset($opts['tag-h']);
			}
		}

		$this->opts = array_replace_recursive($this->opts, $opts);

		if (!empty($this->opts['pkey']) && !is_resource($this->opts['pkey'])) {
			$pkey = trim($this->opts['pkey']);

			if (strpos($pkey, '-----BEGIN') !== 0 && strpos($pkey, 'file://') === false) {
				$pkey = 'file://'.$pkey;
			}

			$this->opts['pkey'] = openssl_pkey_get_private($pkey, $this->opts['passphrase']);
		}

		return $this->opts;
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
		// Formats canonique
		list($headers_c, $body_c) = explode('/', $this->opts['tag-c']);
		// Algorithmes de chiffrement et de hashage
		list($crypt_algo, $hash_algo) = explode('-', $this->opts['tag-a']);

		// Canonicalisation et hashage du corps du mail avec les
		// paramètres spécifiés
		$body = $this->canonicalizeBody($body, $body_c);
		$body = base64_encode(hash($hash_algo, $body, true));
		$body = rtrim(chunk_split($body, 74, "\r\n\t"));

		// Définition des tags DKIM
		$dkim_tags['v']  = 1;
		$dkim_tags['a']  = $this->opts['tag-a'];
		$dkim_tags['c']  = $this->opts['tag-c'];
		$dkim_tags['d']  = $this->opts['tag-d'];
		$dkim_tags['s']  = $this->opts['tag-s'];
		$dkim_tags['q']  = $this->opts['tag-q'];
		$dkim_tags['t']  = time();
		$dkim_tags['h']  = $this->opts['tag-h'];
		$dkim_tags['bh'] = $body;

		// On ne garde que les en-têtes à signer
		$headers_to_sign = explode(':', $dkim_tags['h']);

		$headers = preg_split('#\r\n(?![\t ])#', rtrim($headers));
		$headers = array_filter($headers, function ($header) use ($headers_to_sign) {
			$name = substr($header, 0, strpos($header, ':'));
			return in_array(strtolower($name), $headers_to_sign);
		});
		$headers = implode("\r\n", $headers);

		// Création de l'en-tête DKIM
		$dkim_header = 'DKIM-Signature: ';
		foreach ($dkim_tags as $name => $value) {
			$dkim_header .= "$name=$value; ";
		}
		$dkim_header  = rtrim(wordwrap($dkim_header, 77, "\r\n\t"));
		$dkim_header .= "\r\n\tb=";

		// Canonicalisation des en-têtes à signer, et génération de la
		// signature proprement dite par OpenSSL.
		$headers = $this->canonicalizeHeaders($headers."\r\n".$dkim_header, $headers_c);
		openssl_sign($headers, $signature, $this->opts['pkey'], $hash_algo);

		$dkim_header .= rtrim(chunk_split(base64_encode($signature), 75, "\r\n\t"));

		return $dkim_header;
	}

	/**
	 * Transformation du bloc d’en-têtes dans le format canonique spécifié.
	 * Seul le format 'relaxed' apporte des changements dans le cas des
	 * en-têtes.
	 *
	 * @param string $headers
	 * @param string $canonicalization
	 *
	 * @return string
	 */
	protected function canonicalizeHeaders($headers, $canonicalization = 'simple')
	{
		if ($canonicalization == 'simple') {
			return $headers;
		}

		$headers = preg_split('#\r\n(?![\t ])#', $headers);
		foreach ($headers as &$header) {
			list($name, $value) = explode(':', $header, 2);

			$name   = strtolower($name);
			$value  = trim(preg_replace('#\s+#', ' ', $value));
			$header = "$name:$value";
		}
		$headers = implode("\r\n", $headers);

		return $headers;
	}

	/**
	 * Transformation du message dans le format canonique spécifié.
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
}
