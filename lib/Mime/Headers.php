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

class Headers implements \Iterator
{
	/**
	 * Tableau d’en-têtes
	 *
	 * @var array
	 */
	private $headers = [];

	/**
	 * Utilisé pour permettre l’itération dans la liste des en-têtes (interface Iterator).
	 *
	 * @var integer
	 */
	private $it_tot = 0;

	/**
	 * Voir ci-dessus.
	 *
	 * @var integer
	 */
	private $it_ind = 0;

	/**
	 * Voir ci-dessus.
	 *
	 * @var Header
	 */
	private $it_obj = null;

	/**
	 * Constructeur de classe
	 *
	 * @param array $headers Tableau d’en-têtes d’email à ajouter dans l’objet
	 */
	public function __construct($headers = null)
	{
		if (is_array($headers)) {
			foreach ($headers as $name => $value) {
				$this->add($name, $value);
			}
		}
	}

	/**
	 * Ajout d’un en-tête
	 *
	 * @param string $name  Nom de l’en-tête
	 * @param string $value Valeur de l’en-tête
	 *
	 * @return Header
	 */
	public function add($name, $value)
	{
		$header = new Header($name, $value);
		$name   = strtolower($header->name);

		if ($this->get($name) != null) {
			if (!is_array($this->headers[$name])) {
				$this->headers[$name] = [$this->headers[$name]];
			}

			$this->headers[$name][] = $header;
		}
		else {
			$this->headers[$name] = $header;
		}

		return $header;
	}

	/**
	 * Ajout d’un en-tête, en écrasant si besoin la valeur précédemment affectée
	 * à l’en-tête de même nom
	 *
	 * @param string $name  Nom de l’en-tête
	 * @param string $value Valeur de l’en-tête
	 *
	 * @return Header
	 */
	public function set($name, $value)
	{
		$header = new Header($name, $value);
		$this->headers[strtolower($name)] = $header;

		return $header;
	}

	/**
	 * Retourne l’objet \Wamailer\Mime\Header ou un tableau d’objets
	 * correspondant au nom d’en-tête donné.
	 *
	 * @param string $name Nom de l’en-tête
	 *
	 * @return Header|array
	 */
	public function get($name)
	{
		$name = strtolower($name);
		if (isset($this->headers[$name]) && (is_array($this->headers[$name]) || $this->headers[$name]->value != '')) {
			return $this->headers[$name];
		}

		return null;
	}

	/**
	 * Supprime le ou les en-têtes correspondants au nom d’en-tête donné dans $name
	 *
	 * @param string $name Nom de l’en-tête
	 */
	public function remove($name)
	{
		$name = strtolower($name);
		if (isset($this->headers[$name])) {
			unset($this->headers[$name]);
		}
	}

	public function current()
	{
		return $this->it_obj->value;
	}

	public function key()
	{
		return $this->it_obj->name;
	}

	public function next()
	{
		$this->it_ind++;
	}

	public function rewind()
	{
		reset($this->headers);
		$this->it_tot = count($this->headers);
		$this->it_ind = 0;
	}

	public function valid()
	{
		if ($this->it_ind < $this->it_tot) {
			$tmp = each($this->headers);
			$this->it_obj = $tmp['value'];
			$ret = true;
		}
		else {
			$ret = false;
		}

		return $ret;
	}

	/**
	 * Retourne le bloc d’en-têtes sous forme de chaîne
	 *
	 * @return string
	 */
	public function __toString()
	{
		$str = '';
		foreach ($this->headers as $headers) {
			if (!is_array($headers)) {
				$headers = [$headers];
			}

			foreach ($headers as $header) {
				if ($header->value != '') {
					$str .= $header->__toString();
					$str .= "\r\n";
				}
			}
		}

		return $str;
	}

	public function __clone()
	{
		foreach ($this->headers as &$headers) {
			if (is_array($headers)) {
				foreach ($headers as &$header) {
					$header = clone $header;
				}
			}
			else {
				$headers = clone $headers;
			}
		}
	}
}
