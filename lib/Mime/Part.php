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
	private $subparts = [];

	/**
	 * Frontière de séparation entre les différentes sous-parties
	 *
	 * @var string
	 */
	private $boundary = null;

	/**
	 * Limitation de longueur des lignes de texte.
	 * Si cet attribut est placé à true, la limitation des lignes de texte
	 * est de 78 octets + <CR><LF>.
	 *
	 * De plus, si une ligne dépasse 998 octets + <CR><LF>, le codage
	 * quoted-printable ou base64 (si l’encoding de base est 'binary') est
	 * automatiquement utilisé pour garantir la bonne livraison du message
	 * par les MTA.
	 *
	 * @see RFC 5322#2.1.1 - Line Length Limits
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
		return (count($this->subparts) > 0);
	}

	/**
	 * @return string
	 */
	public function __toString()
	{
		if ($encoding = $this->headers->get('Content-Transfer-Encoding')) {
			$encoding = strtolower($encoding->value);
		}
		else {
			$encoding = '7bit';
		}

		$body = $this->body;

		if ($this->isMultiPart()) {
			/**
			 * Le type multipart est restreint aux codages 7bit, 8bit et binary,
			 * cela pour éviter que des données soient codées plusieurs fois.
			 *
			 * @see RFC 2045#6.4 - Interpretation and Use
			 */
			if (!in_array($encoding, ['7bit', '8bit', 'binary'])) {
				$this->headers->remove('Content-Transfer-Encoding');
			}

			$this->boundary = '--=_Part_' . md5(microtime().mt_rand());
			$this->headers->get('Content-Type')->param('boundary', $this->boundary);

			if ($body != '') {
				$body .= "\r\n\r\n";
			}

			foreach ($this->subparts as $subpart) {
				$body .= '--' . $this->boundary . "\r\n";
				$body .= (!is_string($subpart)) ? $subpart->__toString() : $subpart;
				$body .= "\r\n";
			}

			$body .= '--' . $this->boundary . "--\r\n";
		}
		else {
			/**
			 * On normalise les fins de ligne pour les codages concernés.
			 *
			 * Le codage 'quoted-printable' est inclus dans la liste car
			 * quoted_printable_encode() code également les caractères <CR>
			 * et <LF> s’ils ne font pas partie d’une paire <CR><LF>.
			 */
			if (in_array($encoding, ['7bit', '8bit', 'quoted-printable'])) {
				$body = preg_replace("/\r\n?|\n/", "\r\n", $body);
			}

			/**
			 * La limitation de longueur recommandée est de 78 caractères
			 * par ligne.
			 * La limite stricte est de 998 caractères. Si celle-ci est
			 * dépassée, on passe sur un codage quoted-printable pour
			 * des données lisibles, sinon sur du base64.
			 *
			 * @see RFC 5322#2.1.1 - Line Length Limits
			 */
			if (in_array($encoding, ['7bit', '8bit', 'binary'])) {
				$oldbody = $body;

				if ($encoding != 'binary' && $this->wraptext) {
					$body = wordwrap($body, 78, "\r\n");
				}

				if (preg_match('/^.{998}[^\r\n]/m', $body)) {
					$encoding = ($encoding == 'binary') ? 'base64' : 'quoted-printable';
					$body = $oldbody;
				}

				unset($oldbody);
			}

			/**
			 * On redéfinit l’en-tête de codage des données car le codage a pu
			 * être changé pour respecter les limitations de ligne
			 * (voir bloc de code précédent).
			 *
			 * 7bit est le 'codage' par défaut. Pas besoin de le préciser dans
			 * les en-têtes.
			 *
			 * @see RFC 2045#6.1 - Content-Transfer-Encoding Syntax
			 */
			if ($encoding != '7bit') {
				$this->headers->set('Content-Transfer-Encoding', $encoding);
			}

			if ($encoding == 'quoted-printable') {
				/**
				 * Encodage en chaîne à guillemets.
				 *
				 * @see RFC 2045#6.7 - Quoted-Printable Content-Transfer-Encoding
				 */
				$body = quoted_printable_encode($body);
			}
			else if ($encoding == 'base64') {
				/**
				 * Encodage en base64
				 *
				 * @see RFC 2045#6.8 - Base64 Content-Transfer-Encoding
				 */
				$body = rtrim(chunk_split(base64_encode($body)));
			}
		}

		return $this->headers->__toString() . "\r\n" . $body;
	}

	/**
	 * Modification des propriétés non publiques autorisées.
	 *
	 * @param string $name  Nom de la propriété
	 * @param mixed  $value Nouvelle valeur de la propriété
	 */
	public function __set($name, $value)
	{
		if ($name == 'encoding') {
			$this->headers->set('Content-Transfer-Encoding', $value);
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

	/**
	 * Clonage de l’objet
	 */
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
