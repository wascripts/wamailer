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

use Wamailer\Mime;
use Exception;

class Part
{
	/**
	 * Bloc d’en-têtes de cette partie (accès en lecture)
	 *
	 * @var Headers
	 */
	private $headers  = null;

	/**
	 * Contenu de cette partie
	 *
	 * @var string
	 */
	public $body      = null;

	/**
	 * tableau des éventuelles sous-parties
	 *
	 * @var mixed
	 */
	private $subparts = array();

	/**
	 * Frontière de séparation entre les différentes sous-parties
	 *
	 * @var string
	 */
	private $boundary = null;

	/**
	 * Limitation de longueur des lignes de texte.
	 * Par défaut, la limitation est celle imposée par la RFC2822,
	 * à savoir 998 octets + CRLF
	 * Si cet attribut est placé à true, la limitation est de 78
	 * octets + CRLF
	 *
	 * @see RFC 2822#2.1.1
	 *
	 * @var boolean
	 */
	public $wraptext  = true;

	/**
	 * Constructeur de classe
	 *
	 * @param string $body
	 */
	public function __construct($body = null, $headers = null)
	{
		$this->headers = new Headers($headers);

		if (!is_null($body)) {
			$this->body = $body;
		}
	}

	/**
	 * Ajout de sous-partie(s) à ce bloc MIME
	 *
	 * @param mixed $subpart Peut être un objet \Wamailer\Mime\Part, un tableau
	 *                       d’objets \Wamailer\Mime\Part, ou simplement une chaîne
	 */
	public function addSubPart($subpart)
	{
		if (is_array($subpart)) {
			$this->subparts = array_merge($this->subparts, $subpart);
		}
		else {
			$this->subparts[] = $subpart;
		}
	}

	/**
	 * Indique si ce bloc MIME contient des sous-parties
	 *
	 * @return boolean
	 */
	public function isMultiPart()
	{
		return count($this->subparts) > 0;
	}

	/**
	 * @return string
	 */
	public function __toString()
	{
		if ($this->headers->get('Content-Type') == null) {
			$this->headers->set('Content-Type', 'application/octet-stream');
		}

		$body = $this->body;

		if ($this->isMultiPart()) {
			$this->boundary = '--=_Part_' . md5(microtime());
			$this->headers->get('Content-Type')->param('boundary', $this->boundary);

			if ($body != '') {
				$body .= "\r\n\r\n";
			}

			foreach ($this->subparts as $subpart) {
				$body .= '--' . $this->boundary . "\r\n";
				$body .= !is_string($subpart) ? $subpart->__toString() : $subpart;
				$body .= "\r\n";
			}

			$body .= '--' . $this->boundary . "--\r\n";
		}
		else {
			if ($encoding = $this->headers->get('Content-Transfer-Encoding')) {
				$encoding = strtolower($encoding->value);
			}

			if (!in_array($encoding, array('7bit', '8bit', 'quoted-printable', 'base64', 'binary'))) {
				$this->headers->remove('Content-Transfer-Encoding');
				$encoding = '7bit';
			}

			switch ($encoding) {
				case 'quoted-printable':
					/**
					 * Encodage en chaîne à guillemets
					 *
					 * @see RFC 2045#6.7
					 */
					$body = quoted_printable_encode($body);
					break;
				case 'base64':
					/**
					 * Encodage en base64
					 *
					 * @see RFC 2045#6.8
					 */
					$body = rtrim(chunk_split(base64_encode($body)));
					break;
				case '7bit':
				case '8bit':
					$body = preg_replace("/\r\n?|\n/", "\r\n", $body);

					/**
					 * Limitation sur les longueurs des lignes de texte.
					 * La limite basse est de 78 caractères par ligne.
					 * En tout état de cause, chaque ligne ne DOIT PAS
					 * faire plus de 998 caractères.
					 *
					 * @see RFC 2822#2.1.1
					 */
					$body = Mime::wordwrap($body, $this->wraptext ? 78 : 998);
					break;
			}
		}

		return $this->headers->__toString() . "\r\n" . $body;
	}

	public function __set($name, $value)
	{
		if ($name == 'encoding') {
			$this->headers->set('Content-Transfer-Encoding', $value);
		}
	}

	public function __get($name)
	{
		$value = null;

		switch ($name) {
			case 'headers':
				$value = $this->headers;
				break;
			case 'encoding':
				$encoding = $this->headers->get('Content-Transfer-Encoding');
				$value = ($encoding) ? $encoding->value : '7bit';
				break;
			default:
				throw new Exception("Error while trying to get property '$name'");
				break;
		}

		return $value;
	}

	public function __clone()
	{
		$this->headers = clone $this->headers;

		if (is_array($this->subparts)) {
			foreach ($this->subparts as &$subpart) {
				$subpart = clone $subpart;
			}
		}
	}
}
