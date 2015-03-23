<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2015 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 *
 * @see RFC 2045 - Multipurpose Internet Mail Extensions (MIME) Part One: Format of Internet Message Bodies
 * @see RFC 2046 - Multipurpose Internet Mail Extensions (MIME) Part Two: Media Types
 * @see RFC 2047 - Multipurpose Internet Mail Extensions (MIME) Part Three: Message Header Extensions for Non-ASCII Text
 * @see RFC 4289 - Multipurpose Internet Mail Extensions (MIME) Part Four: Registration Procedures
 * @see RFC 2049 - Multipurpose Internet Mail Extensions (MIME) Part Five: Conformance Criteria and Examples
 * @see RFC 2076 - Common Internet Message Headers
 * @see RFC 2392 - Content-ID and Message-ID Uniform Resource Locators
 * @see RFC 2183 - Communicating Presentation Information in Internet Messages: The Content-Disposition Header Field
 * @see RFC 2231 - MIME Parameter Value and Encoded Word Extensions: Character Sets, Languages, and Continuations
 * @see RFC 2822 - Internet Message Format
 * @see RFC 2387 - The MIME Multipart/Related Content-type
 *
 * Les sources qui m’ont bien aidées :
 *
 * @link http://abcdrfc.free.fr/ (français)
 * @link http://www.faqs.org/rfcs/ (anglais)
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
	private $params = array();

	/**
	 * Active/Désactive le pliage des entêtes tel que décrit dans la RFC 2822
	 *
	 * @see RFC 2822#2.2.3 Long Header Fields
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
	 * @see RFC 2822#2.2
	 *
	 * @param string $name
	 *
	 * @return string
	 */
	public function validName($name)
	{
		if (!preg_match('/^[\x21-\x39\x3B-\x7E]+$/', $name)) {
			throw new Exception("'$name' is not a valid header name!");
		}

		return str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
	}

	/**
	 * Le contenu de l’en-tête ne doit contenir aucun retour chariot
	 * ou saut de ligne
	 *
	 * @see RFC 2822#2.2
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
	 * Vérifie si la chaîne passée en argument est un 'token' tel que défini dans la RFC 2045
	 *
	 * @see RFC 2045#5.1
	 *
	 * @param string $str
	 *
	 * @access public
	 * @return boolean
	 */
	public function isToken($str)
	{
		/**
		 * Tout caractère ASCII est accepté à l’exception des caractères de contrôle, de l’espace
		 * et des caractères spéciaux listés ci-dessous.
		 *
		 * token := 1*<any (US-ASCII) CHAR except SPACE, CTLs, or tspecials>
		 *
		 * tspecials := "(" / ")" / "<" / ">" / "@" /
		 *              "," / ";" / ":" / "\" / <">
		 *              "/" / "[" / "]" / "?" / "="
		 */

		return (bool) !preg_match('/[^\x21\x23-\x27\x2A\x2B\x2D\x2E\x30-\x39\x5E-\x7E]/Si', $str);
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
	 * @param string $header  Nom de l’en-tête concerné
	 * @param string $header  Valeur d’en-tête à encoder
	 * @param string $charset Jeu de caractères utilisé
	 * @param string $token
	 *
	 * @return string
	 */
	public static function encode($name, $value, $charset, $token = 'text')
	{
		if (preg_match('/[\x00-\x1F\x7F-\xFF]/', $value)) {
			$maxlen = 76;
			$sep = "\r\n\t";

			switch ($token) {
				case 'comment':
					$charlist = '\x00-\x1F\x22\x28\x29\x3A\x3D\x3F\x5F\x7F-\xFF';
					break;
				case 'phrase':
					$charlist = '\x00-\x1F\x22-\x29\x2C\x2E\x3A\x40\x5B-\x60\x7B-\xFF';
					break;
				case 'text':
				default:
					$charlist = '\x00-\x1F\x3A\x3D\x3F\x5F\x7F-\xFF';
					break;
			}

			/**
			 * Si le nombre d’octets à encoder représente plus de 33% de la chaîne,
			 * nous utiliserons l’encodage base64 qui garantit une chaîne encodée 33%
			 * plus longue que l’originale, sinon, on utilise l’encodage "Q".
			 * La RFC 2047 recommande d’utiliser pour chaque cas l’encodage produisant
			 * le résultat le plus court.
			 *
			 * @see RFC 2045#6.8
			 * @see RFC 2047#4
			 */
			$q = preg_match_all("/[$charlist]/", $value, $matches);
			$strlen   = strlen($value);
			$encoding = (($q / $strlen) < 0.33) ? 'Q' : 'B';
			$template = sprintf('=?%s?%s?%%s?=%s', $charset, $encoding, $sep);
			$maxlen   = ($maxlen - strlen($template) + strlen($sep) + 2);// + 2 pour le %s dans le modèle
			$is_utf8  = (strcasecmp($charset, 'UTF-8') == 0);
			$newbody  = '';
			$pos = 0;

			while ($pos < $strlen) {
				$tmplen = $maxlen;
				if ($newbody == '') {
					$tmplen -= strlen($name . ': ');
					if ($encoding == 'Q') {
						$tmplen++;
					}
				}

				if ($encoding == 'Q') {
					$q = preg_match_all("/[$charlist]/", substr($value, $pos, $tmplen), $matches);
					// chacun des octets trouvés prendra trois fois plus de place dans
					// la chaîne encodée. On retranche cette valeur de la longueur du tronçon
					$tmplen -= ($q * 2);
				}
				else {
					/**
					 * La longueur de l'encoded-text' doit être un multiple de 4
					 * pour ne pas casser l’encodage base64
					 *
					 * @see RFC 2047#5
					 */
					$tmplen -= ($tmplen % 4);
					$tmplen = floor(($tmplen/4)*3);
				}

				if ($is_utf8) {
					/**
					 * Il est interdit de sectionner un caractère multi-octet.
					 * On teste chaque octet en partant de la fin du tronçon en cours
					 * jusqu’à tomber sur un caractère ascii ou l’octet de début de
					 * séquence d’un caractère multi-octets.
					 * On vérifie alors qu’il y bien $m octets qui suivent (le cas échéant).
					 * Si ce n’est pas le cas, on réduit la longueur du tronçon.
					 *
					 * @see RFC 2047#5
					 */
					$_utf8test = array(
						0x80 => 0, 0xE0 => 0xC0, 0xF0 => 0xE0, 0xF8 => 0xF0, 0xFC => 0xF8, 0xFE => 0xFC
					);

					for ($i = min(($pos + $tmplen), $strlen), $c = 1; $i > $pos; $i--, $c++) {
						$d = ord($value[$i-1]);

						reset($_utf8test);
						for ($m = 1; $m <= 6; $m++) {
							$test = each($_utf8test);
							if (($d & $test[0]) == $test[1]) {
								if ($c < $m) {
									$tmplen -= $c;
								}
								break 2;
							}
						}
					}
				}

				$tmp = substr($value, $pos, $tmplen);
				if ($encoding == 'Q') {
					$tmp = preg_replace_callback("/([$charlist])/",
						function ($m) { return sprintf('=%02X', ord($m[1])); },
						$tmp
					);
					$tmp = str_replace(' ', '_', $tmp);
				}
				else {
					$tmp = base64_encode($tmp);
				}

				$newbody .= sprintf($template, $tmp);
				$pos += $tmplen;
			}

			$value = rtrim($newbody);
		}
		else if ($token != 'text') {
			if (preg_match('/[^!#$%&\'*+\/0-9=?a-z^_`{|}~-]/', $value)) {
				$value = '"'.$value.'"';
			}
		}

		return $value;
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
				 * @see RFC 2231#4
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
			$value = wordwrap($value, 77, "\r\n\t");
		}

		return $value;
	}

	public function __set($name, $value)
	{
		switch ($name) {
			case 'value':
				$this->{$name} = self::sanitizeValue($value);
				break;
		}
	}

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
