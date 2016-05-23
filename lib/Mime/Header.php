<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2016 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 *
 * @see RFC 5322 - Internet Message Format
 * @see RFC 2045 - Multipurpose Internet Mail Extensions (MIME) Part One: Format of Internet Message Bodies
 * @see RFC 2046 - Multipurpose Internet Mail Extensions (MIME) Part Two: Media Types
 * @see RFC 2047 - Multipurpose Internet Mail Extensions (MIME) Part Three: Message Header Extensions for Non-ASCII Text
 * @see RFC 2049 - Multipurpose Internet Mail Extensions (MIME) Part Five: Conformance Criteria and Examples
 * @see RFC 2231 - MIME Parameter Value and Encoded Word Extensions: Character Sets, Languages, and Continuations
 * @see RFC 4021 - Registration of Mail and MIME Header Fields
 * @see RFC 2392 - Content-ID and Message-ID Uniform Resource Locators
 * @see RFC 2183 - Communicating Presentation Information in Internet Messages: The Content-Disposition Header Field
 * @see RFC 2387 - The MIME Multipart/Related Content-type
 * @see RFC 2557 - MIME Encapsulation of Aggregate Documents, such as HTML (MHTML)
 */

namespace Wamailer\Mime;

use Exception;

class Header
{
	/**
	 * Nom de l’en-tête (accès en lecture)
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Valeur de l’en-tête (accès en lecture)
	 *
	 * @var string
	 */
	private $value;

	/**
	 * Liste des paramètres associés à la valeur de cet en-tête
	 *
	 * @var array
	 */
	private $params = [];

	/**
	 * Active/Désactive le pliage des entêtes
	 *
	 * @see RFC 5322#2.2.3 - Long Header Fields
	 *
	 * @var boolean
	 */
	public $folding = true;

	/**
	 * Constructeur de classe
	 *
	 * @param string $name  Nom de l’en-tête
	 * @param string $value Valeur de l’en-tête
	 */
	public function __construct($name, $value)
	{
		$name  = self::validName($name);
		$value = self::sanitizeValue($value);

		if (($name == 'Content-Type' || $name == 'Content-Disposition') && strpos($value, ';')) {
			list($value, $params) = explode(';', $value, 2);
			preg_match_all('/([\x21-\x39\x3B-\x7E]+)=(")?(.+?)(?(2)(?<!\\\\)(?:\\\\\\\\)")(?=;|$)/S',
				$params, $matches, PREG_SET_ORDER);

			foreach ($matches as $param) {
				$this->param($param[1], $param[3]);
			}
		}

		$this->name  = $name;
		$this->value = $value;
	}

	/**
	 * Le nom de l’en-tête ne doit contenir que des caractères us-ascii imprimables,
	 * et ne doit pas contenir le caractère deux points (:)
	 *
	 * @see RFC 5322#2.2 - Header Fields
	 *
	 * @param string $name
	 *
	 * @throws Exception
	 * @return string
	 */
	public function validName($name)
	{
		if (preg_match('/[^\x21-\x39\x3B-\x7E]/', $name)) {
			throw new Exception("'$name' is not a valid header name!");
		}

		return str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
	}

	/**
	 * Le contenu de l’en-tête ne doit contenir aucun retour chariot
	 * ou saut de ligne
	 *
	 * @see RFC 5322#2.2 - Header Fields
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	public function sanitizeValue($value)
	{
		return preg_replace('/\s+/S', ' ', $value);
	}

	/**
	 * Vérifie si la chaîne passée en argument est un 'token'.
	 *
	 * @see RFC 2045 - Appendix A -- Collected Grammar
	 *
	 * @param string $str
	 *
	 * @return boolean
	 */
	public function isToken($str)
	{
		/**
		 * Tout caractère ASCII est accepté à l’exception des caractères de
		 * contrôle, de l’espace et des caractères spéciaux listés ci-dessous.
		 *
		 * token := 1*<any (US-ASCII) CHAR except SPACE, CTLs, or tspecials>
		 *
		 * tspecials := "(" / ")" / "<" / ">" / "@" /
		 *              "," / ";" / ":" / "\" / <">
		 *              "/" / "[" / "]" / "?" / "="
		 */

		return !preg_match('/[^\x21\x23-\x27\x2A\x2B\x2D\x2E\x30-\x39\x5E-\x7E]/Si', $str);
	}

	/**
	 * Complète la valeur de l’en-tête
	 *
	 * @param string $str
	 */
	public function append($str)
	{
		$this->value .= self::sanitizeValue($str);
	}

	/**
	 * Ajoute un paramètre à l’en-tête
	 *
	 * @param string $name  Nom du paramètre
	 * @param string $value Valeur du paramètre
	 *
	 * @return string
	 */
	public function param($name, $value = null)
	{
		$curVal = null;
		if (isset($this->params[$name])) {
			$curVal = $this->params[$name];
		}
		else if (!self::isToken($name)) {
			$value = null;
		}

		if (!is_null($value)) {
			$this->params[$name] = strval($value);
		}

		return $curVal;
	}

	/**
	 * Encode la valeur d’un en-tête si elle contient des caractères non-ascii.
	 * Autrement, des guillemets peuvent néanmoins être ajoutés aux extrémités
	 * si des caractères interdits pour le token considéré sont présents.
	 *
	 * @see RFC 2045
	 * @see RFC 2047
	 * @see RFC 5322
	 *
	 * @param string $name    Nom de l’en-tête concerné
	 * @param string $value   Valeur d’en-tête à encoder
	 * @param string $charset Jeu de caractères utilisé
	 * @param string $token   Nom du token ('phrase', 'text' ou 'comment')
	 *
	 * @return string
	 */
	public static function encode($name, $value, $charset, $token = 'text')
	{
		$charlist = '\x00-\x1F\x7F-\xFF'; // non printable + non us-ascii

		if (!preg_match("/[$charlist]/", $value)) {
			if ($token != 'text' && preg_match('/[^A-Z0-9!#$%&\'*+\/=?^_`{|}~-]/i', $value)) {
				$value = '"'.addcslashes($value, '\\"').'"';
			}

			return $value;
		}

		if ($token == 'phrase') {
			// "#$%&'(),.:;<=>?@[\]^_`{|}~
			$charlist .= '\x22-\x29\x2C\x2E\x3A-\x40\x5B-\x60\x7B-\x7E';
		}
		else {
			$charlist .= '\x3A\x3D\x3F\x5F';// :=?_

			if ($token == 'comment') {
				$charlist .= '\x22\x28\x29';// "()
			}
		}

		/**
		 * Si le nombre d’octets à encoder représente plus de 33% de la chaîne,
		 * nous utiliserons l’encodage base64 qui garantit une chaîne encodée 33%
		 * plus longue que l’originale, sinon, on utilise l’encodage "Q".
		 * La RFC 2047 recommande d’utiliser pour chaque cas l’encodage produisant
		 * le résultat le plus court.
		 *
		 * @see RFC 2047#4 - Encodings
		 */
		$q = preg_match_all("/[$charlist]/", $value, $matches);

		$maxlen   = 75;
		$encoding = (($q / strlen($value)) < 0.33) ? 'Q' : 'B';
		$template = sprintf('=?%s?%s?%%s?=', $charset, $encoding);
		$maxlen   = ($maxlen - strlen($template) + 2);// + 2 pour le %s résultant dans le modèle
		$is_utf8  = (strcasecmp($charset, 'UTF-8') == 0);
		$output   = '';

		$utf8test = [
			0x80 => 0, 0xE0 => 0xC0, 0xF0 => 0xE0, 0xF8 => 0xF0, 0xFC => 0xF8, 0xFE => 0xFC
		];

		/**
		 * Si on travaille en Quoted Printable, on fait l’encodage *avant* de
		 * travailler sur la chaîne car c’est beaucoup plus simple ensuite pour
		 * obtenir la bonne longueur.
		 * Si on travaille en base64, le codage se fait à la fin de chaque
		 * itération dans la boucle de traitement.
		 */
		if ($encoding == 'Q') {
			$replace_pairs = array_flip($matches[0]);
			array_walk($replace_pairs,
				function (&$val, $key) { $val = sprintf('=%02X', ord($key)); }
			);
			// Le signe égal doit être remplacé en premier !
			$replace_pairs = array_merge(['=' => '=3D', ' ' => '_'], $replace_pairs);

			$value = strtr($value, $replace_pairs);
		}

		while ($value) {
			$chunk_len = $maxlen;
			if ($output == '') {
				$chunk_len -= strlen($name . ':');
			}

			if ($encoding == 'B') {
				/**
				 * La longueur du 'encoded-text' doit être un multiple de 4
				 * pour ne pas casser l’encodage base64
				 *
				 * @see RFC 2047#5 - Use of encoded-words in message headers
				 */
				$chunk_len -= ($chunk_len % 4);
				$chunk_len  = (integer) floor(($chunk_len/4)*3);
			}

			$chunk = substr($value, 0, $chunk_len);
			$check_utf8 = false;

			if (strlen($chunk) == $chunk_len) {
				$check_utf8 = $is_utf8;

				if ($encoding == 'Q') {
					while ($chunk[$chunk_len-1] == '=' || $chunk[$chunk_len-2] == '=') {
						$chunk = substr($chunk, 0, -1);
						$chunk_len--;
					}

					if ($chunk[$chunk_len-3] != '=') {
						$check_utf8 = false;
					}
				}
			}

			if ($check_utf8) {
				/**
				 * Il est interdit de sectionner un caractère multi-octet.
				 * On teste chaque octet en partant de la fin du tronçon en cours
				 * jusqu’à tomber sur un caractère ascii ou l’octet de début de
				 * séquence d’un caractère multi-octets.
				 * On vérifie alors qu’il y bien $m octets qui suivent (le cas échéant).
				 * Si ce n’est pas le cas, on réduit la longueur du tronçon.
				 *
				 * @see RFC 2047#5 - Use of encoded-words in message headers
				 *
				 * Si quoted-printable, on progresse par séquence de 3 (=XX).
				 * Si base64, le codage n’est pas encore fait, donc on progresse
				 * octet par octet.
				 */

				$v = ($encoding == 'Q') ? 3 : 1;
				for ($i = $chunk_len, $c = $v; $i > 0; $i -= $v, $c += $v) {
					$char = substr($chunk, ($i - $v), $v);
					$d = ($encoding == 'Q') ? hexdec(ltrim($char, '=')) : ord($char);

					reset($utf8test);
					for ($m = 1; $m <= 6; $m++) {
						$test = each($utf8test);
						if (($d & $test[0]) == $test[1]) {
							if ($c < ($m*$v)) {
								$chunk_len -= $c;
								$chunk = substr($chunk, 0, $chunk_len);
							}
							break 2;
						}
					}
				}
			}

			if ($output) {
				$output .= "\r\n\t";
			}

			if ($encoding == 'B') {
				$chunk = base64_encode($chunk);
			}

			$output .= sprintf($template, $chunk);
			$value = substr($value, $chunk_len);
		}

		return $output;
	}

	/**
	 * Renvoie l’en-tête sous forme de chaîne formatée
	 *
	 * @return string
	 */
	public function __toString()
	{
		$value = $this->value;

		foreach ($this->params as $pName => $pValue) {
			if (empty($pValue)) {
				continue;
			}

			if (!self::isToken($pValue)) {
				/**
				 * Syntaxe spécifique pour les valeurs comportant
				 * des caractères non-ascii.
				 *
				 * @see RFC 2231#4 - Parameter Value Character Set and Language Information
				 */
				if (preg_match('/[\x80-\xFF]/S', $pValue)) {
					$pName .= '*';
					$pValue = 'UTF-8\'\'' . rawurlencode($pValue);// TODO charset
				}
				else {
					$pValue = '"' . $pValue . '"';
				}
			}

			$value .= sprintf('; %s=%s', $pName, $pValue);
		}

		$value = sprintf('%s: %s', $this->name, $value);

		if ($this->folding) {
			$value = wordwrap($value, 78, "\r\n\t");
		}

		return $value;
	}

	/**
	 * Modification des propriétés non publiques autorisées.
	 *
	 * @param string $name  Nom de la propriété
	 * @param mixed  $value Nouvelle valeur de la propriété
	 */
	public function __set($name, $value)
	{
		switch ($name) {
			case 'value':
				$this->{$name} = self::sanitizeValue($value);
				break;
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
			case 'name':
			case 'value':
				return $this->{$name};
				break;
			default:
				throw new Exception("Error while trying to get property '$name'");
				break;
		}
	}
}
